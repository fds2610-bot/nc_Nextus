<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Db\ScoringMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ForbiddenException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Management-Dashboard (REP-001, Kapitel 06 §2.9): ein Bildschirm, sechs Kacheln – Portfolio,
 * Finanzen, Kapazität, Risiken, Entscheidungen, Nutzen. Die Kacheln fassen nur zusammen, was die
 * Fachsichten ohnehin berechnen; gezählt wird nur, was das Konto lesen darf.
 */
class DashboardService {
	/** Monate, über die die Engpassauslastung betrachtet wird */
	public const CAPACITY_MONTHS = 6;
	/** Budgetabweichung in Prozent, ab der ein Projekt in der Finanzkachel auffällt */
	public const DEVIATION_PCT = 10.0;
	private const TOP = 5;
	/** Reifegrad-Stufen der Portfolio-Matrix (x-Achse) in Reihenfolge, je mit den zugehörigen Status */
	public const STAGES = [
		'intake' => ['submitted', 'query'],
		'analysis' => ['further_analysis', 'in_qualification'],
		'candidate' => ['candidate'],
		'prioritized' => ['prioritized'],
		'released' => ['released'],
		'execution' => ['in_execution', 'paused'],
		'completion' => ['completed'],
	];
	/** ab dieser Stufe ist das Vorhaben ein Projekt (freigegeben, G3) */
	public const PROJECT_STAGE = 'released';
	private const DAY = 86400;

	public function __construct(
		private DemandService $demands,
		private CapacityService $capacity,
		private CapacityCutService $cut,
		private ProjectService $projects,
		private RiskService $risks,
		private ConditionService $conditions,
		private BenefitService $benefits,
		private ObjectiveService $objectives,
		private SessionService $sessions,
		private Rows $rows,
		private ResourceNeedMapper $needs,
		private BusinessCaseService $businessCases,
		private SettingsService $settings,
		private ScoringMapper $scoring,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array<string, mixed>
	 * @throws ForbiddenException
	 */
	public function management(string $userId): array {
		if (!$this->capacity->canSee($userId)) {
			throw new ForbiddenException('Only governance roles may see the management dashboard');
		}
		$now = $this->time->getTime();
		$demands = array_values(array_filter(
			$this->demands->findAll($userId),
			static fn (Demand $d) => $d->getStatus() !== DemandService::STATUS_DRAFT,
		));
		$projects = $this->projects->list($userId);
		$month = date('Y-m', $now);
		return [
			'generatedAt' => $now,
			'portfolio' => self::portfolio($demands, $projects, $this->objectives->all($userId), date('Y-m-d', $now)),
			'finance' => self::finance($projects),
			'capacity' => self::capacity($this->capacity->heatmap($userId, $month, self::CAPACITY_MONTHS), $this->cut->cut($userId)),
			'risks' => self::risks($this->risks->portfolio($userId)),
			'decisions' => $this->decisions($userId, $demands, $now),
			'benefits' => self::benefits($this->benefits->register($userId)),
			'maturity' => $this->maturityMatrix($demands, $projects),
		];
	}

	/**
	 * Portfolio-Matrix: jedes Vorhaben als Kreis – Reifegrad (Status) × Aufwand (PT), Fläche = Kosten.
	 *
	 * @param list<Demand> $demands lesbare Anträge ohne Entwürfe
	 * @param list<array<string, mixed>> $projects ProjectService::list
	 * @return array<string, mixed>
	 */
	private function maturityMatrix(array $demands, array $projects): array {
		$created = [];
		foreach ($demands as $d) {
			$created[$d->getId()] = $d->getCreatedAt();
		}
		$days = [];
		foreach ($this->needs->findRange('0000-00', '9999-12') as $need) {
			$id = $need->getDemandId();
			// Bedarf aus einer früheren Installation mit derselben ID nicht mitzählen
			if (isset($created[$id]) && $need->getUpdatedAt() >= $created[$id]) {
				$days[$id] = ($days[$id] ?? 0.0) + $need->getPersonDays();
			}
		}
		$cost = [];
		foreach ($projects as $p) {
			if ($p['budgetFrame'] !== null) {
				$cost[$p['id']] = (float)$p['budgetFrame'];
			}
		}
		foreach ($demands as $d) {
			if (!isset($cost[$d->getId()]) && self::stageOf($d->getStatus()) !== null) {
				$c = $this->businessCases->kpisFor($d)['oneTimeCost'] ?? null;
				if ($c !== null) {
					$cost[$d->getId()] = (float)$c;
				}
			}
		}
		$buckets = array_map(static fn (array $b) => ['code' => $b['code'], 'name' => $b['name']], $this->scoring->buckets());
		return ['buckets' => $buckets] + self::maturity($demands, $days, $cost, [
			'S' => (float)$this->settings->get('classDaysS'),
			'M' => (float)$this->settings->get('classDaysM'),
			'L' => (float)$this->settings->get('classDaysL'),
		]);
	}

	public static function stageOf(string $status): ?string {
		foreach (self::STAGES as $stage => $statuses) {
			if (in_array($status, $statuses, true)) {
				return $stage;
			}
		}
		return null;
	}

	/**
	 * @param list<Demand> $demands
	 * @param array<int, float> $days Personentage je Antrag
	 * @param array<int, float> $cost Kosten je Antrag
	 * @param array<string, float> $classDays Untergrenzen der Klassen in PT
	 * @return array<string, mixed>
	 */
	public static function maturity(array $demands, array $days, array $cost, array $classDays): array {
		$points = [];
		$withoutDays = [];
		foreach ($demands as $d) {
			$stage = self::stageOf($d->getStatus());
			if ($stage === null) {
				continue;
			}
			$id = (int)$d->getId();
			$pd = round($days[$id] ?? 0.0, 1);
			$isProject = array_search($stage, array_keys(self::STAGES), true) >= array_search(self::PROJECT_STAGE, array_keys(self::STAGES), true);
			$row = [
				'id' => $id,
				'key' => $isProject ? ($d->getProjectKey() ?? $d->getDemandKey()) : $d->getDemandKey(),
				'title' => $d->getTitle(),
				'status' => $d->getStatus(),
				'stage' => $stage,
				'project' => $isProject,
				'projectClass' => $d->getProjectClass(),
				'bucket' => RankingService::bucketOf($d),
				'personDays' => $pd,
				'cost' => isset($cost[$id]) ? round($cost[$id], 2) : null,
			];
			if ($pd <= 0) {
				$withoutDays[] = $row;
			} else {
				$points[] = $row;
			}
		}
		// große Kreise zuerst zeichnen, damit kleine obenauf liegen und anklickbar bleiben
		usort($points, static fn (array $a, array $b) => ($b['cost'] ?? 0) <=> ($a['cost'] ?? 0) ?: $a['id'] <=> $b['id']);
		return [
			'stages' => array_keys(self::STAGES),
			'projectStage' => self::PROJECT_STAGE,
			'classDays' => $classDays,
			'points' => $points,
			'withoutDays' => array_map(static fn (array $r) => array_intersect_key($r, array_flip(['id', 'key', 'title', 'status', 'project'])), $withoutDays),
		];
	}

	/**
	 * Vorhaben je Phase, Ampelverteilung der laufenden Projekte, strategische Zielabdeckung.
	 *
	 * @param list<Demand> $demands
	 * @param list<array<string, mixed>> $projects
	 * @param list<array<string, mixed>> $objectives
	 * @return array<string, mixed>
	 */
	public static function portfolio(array $demands, array $projects, array $objectives, string $today): array {
		$phases = array_fill_keys(PortfolioService::PHASES, 0);
		$cancelled = 0;
		foreach ($demands as $d) {
			$phase = PortfolioService::PHASE_OF[$d->getStatus()] ?? null;
			if ($phase !== null) {
				$phases[$phase]++;
			}
			if ($d->getStatus() === 'cancelled') {
				$cancelled++;
			}
		}
		$rag = ['green' => 0, 'yellow' => 0, 'red' => 0, 'none' => 0];
		foreach ($projects as $p) {
			if (in_array($p['status'], ['released', 'in_execution'], true)) {
				$rag[self::worst($p['rag'])]++;
			}
		}
		$current = array_values(array_filter(
			$objectives,
			static fn (array $o) => (($o['validFrom'] ?? null) === null || $o['validFrom'] <= $today)
				&& (($o['validTo'] ?? null) === null || $o['validTo'] >= $today),
		));
		$uncovered = array_values(array_filter($current, static fn (array $o) => (int)$o['demandCount'] === 0));
		return [
			'phases' => $phases,
			'active' => $phases['active'],
			'planned' => $phases['planned'],
			'onHold' => $phases['on_hold'],
			'cancelled' => $cancelled,
			'rag' => $rag,
			'objectives' => count($current),
			'objectivesCovered' => count($current) - count($uncovered),
			'uncovered' => array_map(static fn (array $o) => ['id' => $o['id'], 'code' => $o['code'], 'title' => $o['title']], array_slice($uncovered, 0, self::TOP)),
		];
	}

	/**
	 * Gesamtampel eines Statusberichts: die schlechteste der Dimensionen; ohne Bericht „none“.
	 *
	 * @param array<string, string>|null $rag
	 */
	public static function worst(?array $rag): string {
		if ($rag === null) {
			return 'none';
		}
		foreach (['red', 'yellow', 'green'] as $level) {
			if (in_array($level, $rag, true)) {
				return $level;
			}
		}
		return 'none';
	}

	/**
	 * Budgetrahmen, Ist und Forecast der freigegebenen und laufenden Projekte; größte Abweichungen.
	 *
	 * @param list<array<string, mixed>> $projects
	 * @return array<string, mixed>
	 */
	public static function finance(array $projects): array {
		$sum = ['frame' => 0.0, 'actual' => 0.0, 'forecast' => 0.0];
		$counted = 0;
		$over = [];
		foreach ($projects as $p) {
			if (!in_array($p['status'], ['released', 'in_execution', 'paused'], true)) {
				continue;
			}
			$counted++;
			$frame = (float)($p['budgetFrame'] ?? 0);
			$sum['frame'] += $frame;
			$sum['actual'] += (float)($p['actual'] ?? 0);
			// ohne Forecast gilt der Rahmen als Prognose
			$sum['forecast'] += (float)($p['forecast'] ?? $frame);
			if ($p['deviationPct'] !== null && $p['deviationPct'] >= self::DEVIATION_PCT) {
				$over[] = ['id' => $p['id'], 'key' => $p['key'], 'title' => $p['title'], 'deviationPct' => $p['deviationPct']];
			}
		}
		usort($over, static fn (array $a, array $b) => $b['deviationPct'] <=> $a['deviationPct']);
		return [
			'projects' => $counted,
			'frame' => round($sum['frame'], 2),
			'actual' => round($sum['actual'], 2),
			'forecast' => round($sum['forecast'], 2),
			'deviationPct' => $sum['frame'] > 0 ? round(($sum['forecast'] - $sum['frame']) / $sum['frame'] * 100, 1) : null,
			'overBudget' => count($over),
			'threshold' => self::DEVIATION_PCT,
			'top' => array_slice($over, 0, self::TOP),
		];
	}

	/**
	 * Engpass-Pools: höchste und mittlere Auslastung der nächsten Monate, überbuchte Monate;
	 * dazu, wie viele Vorhaben der Kapazitätsschnitt startet bzw. warten lässt.
	 *
	 * @param array{periods: list<string>, pools: list<array<string, mixed>>} $heatmap
	 * @param array<string, mixed> $cut
	 * @return array<string, mixed>
	 */
	public static function capacity(array $heatmap, array $cut): array {
		$pools = [];
		foreach ($heatmap['pools'] as $row) {
			if ((int)($row['pool']['isBottleneck'] ?? 0) !== 1) {
				continue;
			}
			$loads = [];
			$overloaded = 0;
			foreach ($row['cells'] as $cell) {
				// ohne Kapazität, aber mit Bedarf: als 999 % werten, damit es auffällt
				$load = $cell['load'] ?? 999;
				$loads[] = $load;
				if ($load > 100) {
					$overloaded++;
				}
			}
			$pools[] = [
				'id' => $row['pool']['id'],
				'code' => $row['pool']['code'],
				'name' => $row['pool']['name'],
				'peak' => $loads === [] ? 0 : max($loads),
				'average' => $loads === [] ? 0 : (int)round(array_sum($loads) / count($loads)),
				'overloadedMonths' => $overloaded,
			];
		}
		usort($pools, static fn (array $a, array $b) => $b['peak'] <=> $a['peak']);
		$states = ['startable' => 0, 'shifted' => 0, 'waiting' => 0, 'budget' => 0, 'unassessed' => 0];
		foreach ($cut['results'] ?? [] as $r) {
			if (isset($states[$r['state']])) {
				$states[$r['state']]++;
			}
		}
		return [
			'months' => count($heatmap['periods']),
			'from' => $heatmap['periods'][0] ?? null,
			'pools' => $pools,
			'bottlenecks' => count(array_filter($pools, static fn (array $p) => $p['overloadedMonths'] > 0)),
			'cut' => $states,
		];
	}

	/**
	 * Offene Risiken, Eskalationen und die höchsten Risiken nach Score.
	 *
	 * @param array<string, mixed> $portfolio RiskService::portfolio
	 * @return array<string, mixed>
	 */
	public static function risks(array $portfolio): array {
		$critical = 0;
		foreach ($portfolio['matrix'] as $p => $byImpact) {
			foreach ($byImpact as $i => $count) {
				if ($p * $i >= (int)$portfolio['threshold']) {
					$critical += $count;
				}
			}
		}
		$escalated = array_filter($portfolio['risks'], static fn (array $r) => (int)$r['escalated'] === 1);
		$hints = array_filter($portfolio['risks'], static fn (array $r) => $r['escalationHint']);
		$top = $portfolio['risks'];
		usort($top, static fn (array $a, array $b) => $b['score'] <=> $a['score']);
		return [
			'open' => $portfolio['open'],
			'critical' => $critical,
			'threshold' => $portfolio['threshold'],
			'escalated' => count($escalated),
			'escalationHints' => count($hints),
			'top' => array_map(static fn (array $r) => [
				'id' => $r['id'],
				'title' => $r['title'],
				'score' => $r['score'],
				'escalated' => (int)$r['escalated'] === 1,
				'project' => $r['projects'][0] ?? null,
			], array_slice($top, 0, self::TOP)),
		];
	}

	/**
	 * Offene Entscheidungen (Triage, Gates) mit Alter; offene und überfällige Beschlussmaßnahmen;
	 * nächste Sitzung.
	 *
	 * @param list<Demand> $demands lesbare Anträge ohne Entwürfe
	 * @return array<string, mixed>
	 */
	private function decisions(string $userId, array $demands, int $now): array {
		$byId = [];
		$pending = [];
		foreach ($demands as $d) {
			$byId[$d->getId()] = $d;
			if ($d->getStatus() === DemandService::STATUS_SUBMITTED) {
				$pending[] = ['kind' => 'triage', 'id' => $d->getId(), 'key' => $d->getDemandKey(), 'title' => $d->getTitle(), 'gate' => null, 'since' => $d->getSubmittedAt() ?? $d->getCreatedAt()];
			}
		}
		foreach ($this->rows->where(GateService::INSTANCES) as $i) {
			$d = $byId[$i['demandId']] ?? null;
			if ($d === null || !in_array($i['status'], ['ready', 'incomplete', 'adjourned'], true) || $i['submittedAt'] === null || $i['createdAt'] < $d->getCreatedAt()) {
				continue;
			}
			$pending[] = ['kind' => 'gate', 'id' => $d->getId(), 'key' => $d->getProjectKey() ?? $d->getDemandKey(), 'title' => $d->getTitle(), 'gate' => strtoupper((string)$i['gateCode']), 'since' => (int)$i['submittedAt']];
		}
		$conditions = array_values(array_filter($this->conditions->list($userId), static fn (array $c) => $c['status'] === 'open'));
		$next = null;
		foreach (array_reverse($this->sessions->list($userId)) as $s) {
			if (in_array($s['status'], ['planned', 'agenda_final'], true) && $s['scheduledAt'] >= $now - self::DAY) {
				$next = ['id' => $s['id'], 'title' => $s['title'], 'bodyName' => $s['bodyName'], 'scheduledAt' => $s['scheduledAt'], 'items' => $s['items']];
				break;
			}
		}
		return self::decisionSummary($pending, $conditions, $next, $now);
	}

	/**
	 * @param list<array<string, mixed>> $pending
	 * @param list<array<string, mixed>> $openConditions
	 * @param array<string, mixed>|null $nextSession
	 * @return array<string, mixed>
	 */
	public static function decisionSummary(array $pending, array $openConditions, ?array $nextSession, int $now): array {
		foreach ($pending as &$p) {
			$p['ageDays'] = max(0, intdiv($now - (int)$p['since'], self::DAY));
		}
		unset($p);
		usort($pending, static fn (array $a, array $b) => $b['ageDays'] <=> $a['ageDays']);
		$ages = array_column($pending, 'ageDays');
		sort($ages);
		$median = $ages === [] ? null : (count($ages) % 2 === 1 ? $ages[intdiv(count($ages), 2)] : intdiv($ages[count($ages) / 2 - 1] + $ages[count($ages) / 2], 2));
		return [
			'pending' => count($pending),
			'triage' => count(array_filter($pending, static fn (array $p) => $p['kind'] === 'triage')),
			'gates' => count(array_filter($pending, static fn (array $p) => $p['kind'] === 'gate')),
			'oldestDays' => $ages === [] ? null : max($ages),
			'medianDays' => $median,
			'oldest' => array_map(static fn (array $p) => array_diff_key($p, ['since' => true]), array_slice($pending, 0, self::TOP)),
			'openActions' => count($openConditions),
			'overdueActions' => count(array_filter($openConditions, static fn (array $c) => $c['overdue'])),
			'nextSession' => $nextSession,
		];
	}

	/**
	 * Geplanter, prognostizierter und realisierter Nutzen; Realisierungsstatus; fällige Reviews.
	 *
	 * @param array{rows: list<array<string, mixed>>, balance: array<string, mixed>, factors: array<string, mixed>} $register
	 * @return array<string, mixed>
	 */
	public static function benefits(array $register): array {
		$total = $register['balance']['total'];
		$due = array_values(array_filter($register['rows'], static fn (array $r) => $r['reviewDue']));
		return [
			'committed' => round($total['committed'], 2),
			'forecast' => round($total['forecast'], 2),
			'realized' => round($total['realized'], 2),
			// Realisierungsgrad nur über die bereits gemessenen Positionen, sonst wirkt jedes junge Projekt wie ein Ausfall
			'realizationPct' => $total['reviewedCommitted'] > 0 ? (int)round($total['realized'] / $total['reviewedCommitted'] * 100) : null,
			'statuses' => $register['balance']['statuses'],
			'positions' => count(array_filter($register['rows'], static fn (array $r) => $r['committed'])),
			'reviewsDue' => count($due),
			'due' => array_map(static fn (array $r) => ['demandId' => $r['demandId'], 'key' => $r['demandKey'], 'title' => $r['demandTitle'], 'nextReview' => $r['nextReview']], array_slice($due, 0, self::TOP)),
		];
	}
}
