<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\ObjectiveMapper;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Db\ScoringMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * KPI-Definitionen (REP-002, Kapitel 01 §10, 05 §9): Kennzahlen als konfigurierte Objekte mit
 * Definition, Datenquelle, Owner, Zielrichtung und Schwellen für die Ampel. Die Kennzahlen des
 * Startmodells berechnet das Tool selbst; eigene Kennzahlen ohne Berechnung bleiben „extern“.
 */
class KpiService {
	public const DIRECTIONS = ['up', 'down', 'info'];
	private const TABLE = 'nx_kpis';
	/** Startmodell: Code => [Name, Definition, Datenquelle, Richtung, grün, rot, Einheit] */
	private const DEFAULTS = [
		'lead_time_decision' => ['Durchlaufzeit Antrag → Entscheidung', 'Median der Tage von „Eingereicht“ bis zur ersten Triage-Entscheidung (letzte 365 Tage)', 'Anträge, Entscheidungsregister', 'down', 10, 30, 'd'],
		'gate_lead_time' => ['Entscheidungsdurchlaufzeit', 'Median der Tage von „Entscheidungsreif“ bis „Entschieden“ je Gate', 'Gate-Instanzen, Entscheidungsregister', 'down', 14, 45, 'd'],
		'bottleneck_load' => ['Engpassauslastung', 'Harte Zusagen ÷ Projekt-Kapazität der Engpass-Pools im laufenden Monat', 'Allokationen, Pool-Kapazität', 'down', 90, 100, '%'],
		'wip_pools_over' => ['Pools über WIP-Limit', 'Anzahl der Pools mit mehr aktiven Vorhaben als ihr WIP-Limit', 'Konflikterkennung', 'down', 0, 1, ''],
		'bucket_fidelity' => ['Bucket-Treue', 'Größte Abweichung des Ist-Anteils harter Zusagen je Bucket von der Zielquote', 'Allokationen, Buckets', 'down', 10, 20, 'pp'],
		'overdue_conditions' => ['Überfällige Auflagen', 'Offene Auflagen und Maßnahmen nach Fälligkeit', 'Beschlussverfolgung', 'down', 0, 5, ''],
		'data_completeness' => ['Datenvollständigkeit', 'Anteil offener Anträge ohne Befund im Datenqualitäts-Cockpit', 'Datenqualitäts-Cockpit', 'up', 95, 80, '%'],
		'override_rate' => ['Board-Korrekturquote', 'Anteil der Ränge mit Board-Korrektur', 'Rangliste', 'down', 10, 25, '%'],
		'health_contradiction' => ['Status-Health-Widerspruch', 'Anteil laufender Projekte, deren Meldung dem berechneten Indikator widerspricht', 'Statusberichte, Health-Indikator', 'down', 0, 10, '%'],
		'strategic_coverage' => ['Strategische Abdeckung', 'Anteil der Blattziele mit mindestens einem laufenden Vorhaben', 'Strategische Ziele, Zuordnungen', 'up', 100, 70, '%'],
		'uncovered_demand' => ['Ungedeckter Bedarf', 'Personentage zurückgestellter Vorhaben – Argumentationsbasis für Kapazitätsausbau', 'Ressourcenbedarf', 'info', null, null, 'PT'],
		'cancel_rate' => ['Abbruchquote nach G3', 'Anteil freigegebener Vorhaben, die abgebrochen wurden (überwacht, nicht null)', 'Projektstatus', 'info', null, null, '%'],
	];

	private bool $checked = false;

	public function __construct(
		private Rows $rows,
		private DemandMapper $demands,
		private PoolMapper $pools,
		private ObjectiveMapper $objectives,
		private ScoringMapper $scoring,
		private CapacityService $capacity,
		private ProjectService $projects,
		private QualityService $quality,
		private PermissionService $permissions,
		private AuditService $audit,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Kennzahlen mit aktuellem Wert und Ampel.
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException
	 */
	public function dashboard(string $userId): array {
		if (!$this->capacity->canSee($userId)) {
			throw new ForbiddenException('Only governance roles may see the ranking');
		}
		$this->ensureDefaults();
		$values = $this->compute($userId);
		$kpis = [];
		foreach ($this->rows->where(self::TABLE, [], 'id') as $k) {
			$value = $values[$k['code']] ?? null;
			$kpis[] = $k + ['value' => $value, 'level' => self::level($k, $value), 'computed' => array_key_exists($k['code'], self::DEFAULTS)];
		}
		return ['kpis' => $kpis, 'canManage' => $this->permissions->canManageOrg($userId)];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws ForbiddenException|NotFoundException|ValidationException
	 */
	public function save(string $userId, ?int $id, array $data): array {
		if (!$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may change the governance configuration');
		}
		$before = $id === null ? null : ($this->rows->find(self::TABLE, $id) ?? throw new NotFoundException('KPI not found'));
		$name = trim((string)($data['name'] ?? ($before['name'] ?? '')));
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('A name is required');
		}
		$direction = (string)($data['direction'] ?? ($before['direction'] ?? 'up'));
		if (!in_array($direction, self::DIRECTIONS, true)) {
			throw new ValidationException('Unknown direction');
		}
		$num = static fn (mixed $v): ?float => $v === null || $v === '' ? null : (float)$v;
		$owner = trim((string)($data['ownerId'] ?? ($before['ownerId'] ?? '')));
		if ($owner !== '' && !$this->userManager->userExists($owner)) {
			throw new ValidationException('Unknown account or group');
		}
		$values = [
			'name' => $name,
			'definition' => trim((string)($data['definition'] ?? ($before['definition'] ?? ''))) ?: null,
			'dataSource' => trim((string)($data['dataSource'] ?? ($before['dataSource'] ?? ''))) ?: null,
			'ownerId' => $owner === '' ? null : $owner,
			'direction' => $direction,
			'thresholdGreen' => $num(array_key_exists('thresholdGreen', $data) ? $data['thresholdGreen'] : ($before['thresholdGreen'] ?? null)),
			'thresholdRed' => $num(array_key_exists('thresholdRed', $data) ? $data['thresholdRed'] : ($before['thresholdRed'] ?? null)),
			'unit' => trim((string)($data['unit'] ?? ($before['unit'] ?? ''))) ?: null,
			'active' => (bool)($data['active'] ?? ($before['active'] ?? 1)) ? 1 : 0,
		];
		if ($direction !== 'info' && ($values['thresholdGreen'] === null || $values['thresholdRed'] === null)) {
			throw new ValidationException('A traffic light needs both thresholds');
		}
		if ($id === null) {
			$code = 'custom_' . bin2hex(random_bytes(3));
			$id = $this->rows->insert(self::TABLE, $values + ['code' => $code, 'createdBy' => $userId, 'createdAt' => $this->time->getTime()]);
		} else {
			$this->rows->update(self::TABLE, $id, $values);
		}
		$this->audit->log('kpi', $id, $before === null ? 'create' : 'update', $userId, [
			'name' => [$before['name'] ?? null, $name],
			'thresholds' => [$before === null ? null : $before['thresholdGreen'] . '/' . $before['thresholdRed'], $values['thresholdGreen'] . '/' . $values['thresholdRed']],
		]);
		return $this->dashboard($userId);
	}

	/**
	 * Ampel nach Zielrichtung: steigend grün ab Schwelle grün, rot unter Schwelle rot; fallend umgekehrt.
	 *
	 * @param array<string, mixed> $kpi
	 */
	public static function level(array $kpi, ?float $value): ?string {
		if ($value === null || $kpi['direction'] === 'info' || $kpi['thresholdGreen'] === null || $kpi['thresholdRed'] === null) {
			return null;
		}
		if ($kpi['direction'] === 'up') {
			return $value >= $kpi['thresholdGreen'] ? 'green' : ($value >= $kpi['thresholdRed'] ? 'yellow' : 'red');
		}
		return $value <= $kpi['thresholdGreen'] ? 'green' : ($value <= $kpi['thresholdRed'] ? 'yellow' : 'red');
	}

	public function ensureDefaults(): void {
		if ($this->checked) {
			return;
		}
		$this->checked = true;
		$existing = array_column($this->rows->where(self::TABLE), 'code');
		foreach (self::DEFAULTS as $code => [$name, $definition, $source, $direction, $green, $red, $unit]) {
			if (!in_array($code, $existing, true)) {
				$this->rows->insert(self::TABLE, [
					'code' => $code, 'name' => $name, 'definition' => $definition, 'dataSource' => $source, 'direction' => $direction,
					'thresholdGreen' => $green === null ? null : (float)$green, 'thresholdRed' => $red === null ? null : (float)$red,
					'unit' => $unit === '' ? null : $unit, 'active' => 1, 'createdBy' => 'system', 'createdAt' => $this->time->getTime(),
				]);
			}
		}
	}

	/** @return array<string, float|null> berechnete Werte des Startmodells */
	private function compute(string $userId): array {
		$now = $this->time->getTime();
		$month = date('Y-m', $now);
		$demands = [];
		foreach ($this->demands->findAll() as $d) {
			$demands[$d->getId()] = $d;
		}
		$v = [];

		// Durchlaufzeiten
		$firstTriage = [];
		$decidedAt = [];
		foreach ($this->rows->where('nx_decisions') as $dec) {
			$decidedAt[$dec['id']] = $dec['decidedAt'];
			if ($dec['decisionType'] === 'triage' && (!isset($firstTriage[$dec['subjectId']]) || $dec['decidedAt'] < $firstTriage[$dec['subjectId']])) {
				$firstTriage[$dec['subjectId']] = $dec['decidedAt'];
			}
		}
		$lead = [];
		foreach ($demands as $d) {
			$t = $firstTriage[$d->getId()] ?? null;
			if ($t !== null && $d->getSubmittedAt() !== null && $t >= $d->getSubmittedAt() && $d->getSubmittedAt() > $now - 365 * 86400) {
				$lead[] = ($t - $d->getSubmittedAt()) / 86400;
			}
		}
		$v['lead_time_decision'] = self::median($lead);
		$gate = [];
		foreach ($this->rows->where(GateService::INSTANCES, ['status' => 'decided']) as $i) {
			if ($i['submittedAt'] !== null && isset($decidedAt[$i['decisionId']])) {
				$gate[] = max(0, ($decidedAt[$i['decisionId']] - $i['submittedAt']) / 86400);
			}
		}
		$v['gate_lead_time'] = self::median($gate);

		// Engpassauslastung, Bucket-Treue
		$hard = [];
		$byBucket = [];
		foreach ($this->rows->where('nx_allocations', ['level' => 'hard']) as $a) {
			if ($a['period'] === $month) {
				$hard[$a['poolId']] = ($hard[$a['poolId']] ?? 0) + (float)$a['personDays'];
			}
			if ($a['period'] >= $month && isset($demands[$a['demandId']])) {
				$bucket = $demands[$a['demandId']]->getBucket() ?? AssessmentService::BUCKET_OF_TYPE[$demands[$a['demandId']]->getDemandType()] ?? 'C';
				$byBucket[$bucket] = ($byBucket[$bucket] ?? 0) + (float)$a['personDays'];
			}
		}
		$load = 0.0;
		$cap = 0.0;
		foreach ($this->pools->findAll() as $pool) {
			if ($pool->getIsBottleneck() === 1) {
				$load += $hard[$pool->getId()] ?? 0;
				$cap += (float)$this->capacity->capacityRows($pool, $month, 1)[0]['project'];
			}
		}
		$v['bottleneck_load'] = $cap > 0 ? round($load / $cap * 100, 1) : null;
		$total = array_sum($byBucket);
		$dev = null;
		if ($total > 0) {
			$dev = 0.0;
			foreach ($this->scoring->buckets() as $b) {
				$dev = max($dev, abs(($byBucket[$b['code']] ?? 0) / $total * 100 - (float)$b['targetSharePct']));
			}
			$dev = round($dev, 1);
		}
		$v['bucket_fidelity'] = $dev;
		$v['wip_pools_over'] = (float)count(array_filter($this->rows->where('nx_conflicts', ['status' => 'open']), static fn (array $c) => $c['conflictType'] === 'wip_exceeded'));

		// Auflagen, Datenqualität, Korrekturen
		$today = date('Y-m-d', $now);
		$v['overdue_conditions'] = (float)count(array_filter($this->rows->where(ConditionService::TABLE, ['status' => 'open']), static fn (array $c) => $c['dueDate'] < $today));
		try {
			$v['data_completeness'] = (float)$this->quality->cockpit($userId)['score'];
		} catch (ForbiddenException) {
			$v['data_completeness'] = null;
		}
		$ranked = array_filter($demands, static fn ($d) => in_array($d->getStatus(), RankingService::RANKED, true));
		$v['override_rate'] = $ranked === [] ? null : round(count(array_filter($ranked, static fn ($d) => $d->getRankOverride() !== null)) / count($ranked) * 100, 1);

		// Widerspruch Meldung/Indikator
		$active = array_filter($demands, static fn ($d) => in_array($d->getStatus(), ProjectService::ACTIVE, true));
		$reported = 0;
		$contradicting = 0;
		foreach ($active as $d) {
			$report = $this->projects->latestReport($d);
			if ($report !== null) {
				$reported++;
				if ($this->projects->contradictions($report, $this->projects->health($d)) !== []) {
					$contradicting++;
				}
			}
		}
		$v['health_contradiction'] = $reported === 0 ? null : round($contradicting / $reported * 100, 1);

		// Strategische Abdeckung (Blattziele mit laufendem Vorhaben)
		$all = $this->objectives->findAll();
		$parents = array_flip(array_filter(array_map(static fn ($o) => $o->getParentId(), $all)));
		$covered = [];
		foreach ($this->objectives->links() as $l) {
			$d = $demands[$l['demandId']] ?? null;
			if ($d !== null && $l['createdAt'] >= $d->getCreatedAt() && in_array($d->getStatus(), ['in_qualification', 'candidate', 'prioritized', 'released', 'in_execution', 'paused'], true)) {
				$covered[$l['objectiveId']] = true;
			}
		}
		$leaves = array_filter($all, static fn ($o) => !isset($parents[$o->getId()]) && ($o->getValidTo() === null || $o->getValidTo() >= $today));
		$v['strategic_coverage'] = $leaves === [] ? null : round(count(array_filter($leaves, static fn ($o) => isset($covered[$o->getId()]))) / count($leaves) * 100, 1);

		// Ungedeckter Bedarf, Abbruchquote
		$uncovered = 0.0;
		foreach ($this->rows->where('nx_res_demand') as $n) {
			$d = $demands[$n['demandId']] ?? null;
			if ($d !== null && $d->getStatus() === 'deferred' && $n['updatedAt'] >= $d->getCreatedAt()) {
				$uncovered += (float)$n['personDays'];
			}
		}
		$v['uncovered_demand'] = round($uncovered, 1);
		$released = array_filter($demands, static fn ($d) => $d->getReleasedAt() !== null);
		$v['cancel_rate'] = $released === [] ? null : round(count(array_filter($released, static fn ($d) => $d->getStatus() === 'cancelled')) / count($released) * 100, 1);
		return $v;
	}

	/** @param list<float|int> $values */
	private static function median(array $values): ?float {
		if ($values === []) {
			return null;
		}
		sort($values);
		$n = count($values);
		$mid = intdiv($n, 2);
		return round($n % 2 === 1 ? (float)$values[$mid] : ((float)$values[$mid - 1] + (float)$values[$mid]) / 2, 1);
	}
}
