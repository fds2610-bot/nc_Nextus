<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\AllocationMapper;
use OCA\Nexus\Db\CounterMapper;
use OCA\Nexus\Db\Decision;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\ObjectiveMapper;
use OCA\Nexus\Db\PlanningLinkMapper;
use OCA\Nexus\Db\Pool;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\ResourceNeed;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Db\ScoringMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Stage-Gate-Modell (Kapitel 02 §3, §4.3, §5; LIF-001/002):
 *
 *   In Qualifizierung → „Zur Priorisierung“ (Kandidat, PMO)
 *   Kandidat → G2 Portfolioaufnahme → Priorisiert (Projektschlüssel)
 *   Priorisiert → G3 Freigabe mit harter Allokation → Freigegeben
 *   Freigegeben/Pausiert → G4 Phasenfreigabe → In Umsetzung (wiederholbar), Pausiert oder Abgebrochen
 *   In Umsetzung → G5 Abnahme → Abgeschlossen (Zusagen ab dem Folgemonat frei)
 *   Abgeschlossen → G6 Nutzenreview → Geschlossen
 *
 * Welche Gates gelten und welche Pflichtprüfungen sie haben, bestimmt das Gate-Set des Vorhabens;
 * wer entscheidet, die Entscheidungsrechte-Matrix: ein Gremium entscheidet in einer Sitzung,
 * sonst Board oder PMO des Bereichs direkt. Einheitliche Beschlussarten an allen Gates.
 */
class GateService {
	public const OUTCOMES = ['approved', 'approved_with_conditions', 'rework', 'deferred', 'rejected', 'cancelled'];
	public const INSTANCES = 'nx_gate_instances';
	private const TRANCHES = 'nx_tranches';
	private const DECIDE_ROLES = ['board_member', 'portfolio_manager', 'admin'];
	/** erlaubte Beschlussarten je Gate */
	private const ALLOWED = [
		'g2' => ['approved', 'approved_with_conditions', 'rework', 'deferred', 'rejected'],
		'g3' => ['approved', 'approved_with_conditions', 'rework', 'deferred', 'rejected'],
		'g4' => ['approved', 'approved_with_conditions', 'rework', 'deferred', 'cancelled'],
		'g5' => ['approved', 'approved_with_conditions', 'rework', 'rejected'],
		'g6' => ['approved', 'approved_with_conditions', 'rework', 'rejected'],
	];
	/** Status des Vorhabens, an dem das Gate ansteht */
	private const AT = [
		'g2' => ['candidate'],
		'g3' => ['prioritized'],
		'g4' => ['released', 'in_execution', 'paused'],
		'g5' => ['released', 'in_execution'],
		'g6' => ['completed'],
	];
	/** Gates, die ein Vorhaben in diesem Status sicher hinter sich hat */
	private const PASSED = [
		'prioritized' => ['g2'], 'released' => ['g2', 'g3'], 'in_execution' => ['g2', 'g3'], 'paused' => ['g2', 'g3'],
		'completed' => ['g2', 'g3', 'g4', 'g5'], 'closed' => ['g2', 'g3', 'g4', 'g5', 'g6'],
	];
	/** Status nach Go */
	private const ON_GO = ['g2' => 'prioritized', 'g3' => 'released', 'g4' => 'in_execution', 'g5' => 'completed', 'g6' => 'closed'];

	public function __construct(
		private DemandService $demands,
		private DemandMapper $demandMapper,
		private AssessmentService $assessments,
		private ScoringMapper $scoring,
		private BusinessCaseService $businessCases,
		private CapacityService $capacity,
		private PoolMapper $pools,
		private ResourceNeedMapper $needs,
		private AllocationMapper $allocations,
		private ObjectiveMapper $objectives,
		private PlanningLinkMapper $planningLinks,
		private DecisionService $decisions,
		private GovernanceService $governance,
		private ConditionService $conditions,
		private CounterMapper $counters,
		private PermissionService $permissions,
		private AuditService $audit,
		private Rows $rows,
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Gate-Lage eines Vorhabens: Gate-Set mit Fortschritt, nächster Schritt mit Prüfliste und
	 * Zuständigkeit, Auflagen, Tranchen und Zusagen.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function status(string $userId, int $demandId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$set = $this->governance->gateSetFor($demand);
		$steps = $this->steps($demand, $set);
		$step = $steps[0] ?? null;
		$checks = $step === null ? [] : $this->checks($demand, $step);
		$decider = $step === null || $step === 'candidate' ? ['kind' => 'pmo', 'body' => null, 'quorumMin' => null, 'rightId' => null] : $this->governance->deciderFor($step, $demand);
		$instance = $step === null || $step === 'candidate' ? null : $this->openInstance($demand, $step);
		$decided = [];
		foreach ($this->instances($demand) as $i) {
			if ($i['status'] === 'decided') {
				$decided[$i['gateCode']] = $i;
			}
		}
		$tranches = $this->tranches($demand);
		return [
			'step' => $step,
			'steps' => $steps,
			'checks' => $checks,
			'ready' => $step !== null && !in_array(false, array_column($checks, 'ok'), true),
			'canDecide' => $step !== null && $this->canDecide($userId, $demand, $step),
			'canSubmit' => $step !== null && $decider['kind'] === 'body' && $this->canPrepare($userId, $demand),
			'decider' => ['kind' => $decider['kind'], 'body' => $decider['body'] === null ? null : ['id' => $decider['body']['id'], 'code' => $decider['body']['code'], 'name' => $decider['body']['name']]],
			'instance' => $instance,
			'outcomes' => $step === null || $step === 'candidate' ? [] : self::ALLOWED[$step],
			'gateSet' => [
				'id' => $set['id'], 'name' => $set['name'], 'version' => $set['version'],
				'gates' => array_map(fn (array $g) => [
					'code' => $g['code'], 'name' => $g['name'], 'budgetTranche' => $g['budgetTranche'] === 1,
					'state' => in_array($g['code'], $steps, true) ? 'current'
						: (isset($decided[$g['code']]) || in_array($g['code'], self::PASSED[$demand->getStatus()] ?? [], true) ? 'done' : 'open'),
				], $set['gates']),
			],
			'tranche' => $step !== null && $this->gateDef($set, $step)['budgetTranche'] === 1,
			'tranches' => $tranches,
			'budgetReleased' => array_sum(array_column($tranches, 'amount')),
			'conditions' => $this->conditions->forDemand($userId, $demand),
			'allocations' => $this->allocations->forDemand($demandId),
		];
	}

	/**
	 * Vorhaben zur Priorisierung stellen (Kandidat).
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function toCandidate(string $userId, int $demandId, ?string $reason): Demand {
		$demand = $this->demands->findReadable($userId, $demandId);
		if ($demand->getStatus() !== 'in_qualification') {
			throw new ForbiddenException('The demand is not at this gate');
		}
		if (!$this->canDecide($userId, $demand, 'candidate')) {
			throw new ForbiddenException($demand->getRequestorId() === $userId ? 'You cannot decide on your own demand' : 'Only the board or the portfolio office of the area may decide');
		}
		$this->requireChecks($demand, 'candidate');
		$this->db->beginTransaction();
		try {
			$this->setStatus($userId, $demand, 'candidate', $reason);
			$this->decisions->record($userId, $demand, 'candidate', 'ready_for_prioritization', $reason, []);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $demand;
	}

	/**
	 * Gate einem Gremium vorlegen: Pflichtprüfung, dann „entscheidungsreif“ (oder „unvollständig“).
	 * Entscheidungsreife Gates sammelt das PMO in die Tagesordnung der nächsten Sitzung.
	 *
	 * @return array<string, mixed> Gate-Instanz
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function submit(string $userId, int $demandId, string $step): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$this->requireStep($demand, $step);
		if (!$this->canPrepare($userId, $demand)) {
			throw new ForbiddenException('Only the portfolio office of the area may submit a gate');
		}
		$decider = $this->governance->deciderFor($step, $demand);
		if ($decider['kind'] !== 'body') {
			throw new ValidationException('This gate is decided directly, not in a session');
		}
		$checks = $this->checks($demand, $step);
		$ready = !in_array(false, array_column($checks, 'ok'), true);
		$instance = $this->openInstance($demand, $step) ?? $this->createInstance($userId, $demand, $step);
		$now = $this->time->getTime();
		$status = $ready ? 'ready' : 'incomplete';
		$this->rows->update(self::INSTANCES, $instance['id'], [
			'status' => $status, 'completeness' => $checks, 'bodyId' => $decider['body']['id'] ?? null,
			'submittedBy' => $userId, 'submittedAt' => $now, 'updatedAt' => $now,
		]);
		$this->audit->log(DemandService::ENTITY, $demand->getId(), 'gate_submit', $userId, ['gate' => [null, strtoupper($step)], 'gateStatus' => [$instance['status'], $status]]);
		return (array)$this->rows->find(self::INSTANCES, $instance['id']);
	}

	/**
	 * Gate direkt entscheiden (Zuständigkeit PMO bzw. Board des Bereichs laut Entscheidungsrechte-Matrix).
	 *
	 * @param array<string, mixed> $data outcome, reason, resubmissionDate, conditions, tranche{amount, fiscalYear}
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function decide(string $userId, int $demandId, string $step, array $data): Demand {
		$demand = $this->demands->findReadable($userId, $demandId);
		$this->requireStep($demand, $step);
		$decider = $this->governance->deciderFor($step, $demand);
		if ($decider['kind'] === 'body') {
			throw new ForbiddenException('This gate is decided by %1$s in a session', [(string)$decider['body']['name']]);
		}
		if (!$this->canDecide($userId, $demand, $step)) {
			throw new ForbiddenException($demand->getRequestorId() === $userId ? 'You cannot decide on your own demand' : 'Only the board or the portfolio office of the area may decide');
		}
		$this->apply($userId, $demand, $step, $data, null, []);
		return $demand;
	}

	/**
	 * Beschluss eines Gremiums in einer Sitzung umsetzen (Aufruf aus dem SessionService, dort sind
	 * Mandat, Quorum und Stimmen geprüft).
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $body
	 * @param array<string, mixed> $details Sitzung und Stimmbild
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function decideInSession(string $userId, Demand $demand, string $step, array $data, array $body, array $details): Decision {
		$this->requireStep($demand, $step);
		return $this->apply($userId, $demand, $step, $data, $body, $details);
	}

	/**
	 * Entscheidungsreife Gate-Instanzen eines Gremiums (für die Tagesordnung).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function readyFor(int $bodyId): array {
		return array_values(array_filter(
			$this->rows->where(self::INSTANCES, ['bodyId' => $bodyId]),
			static fn (array $i) => in_array($i['status'], ['ready', 'adjourned'], true),
		));
	}

	/** Status einer Gate-Instanz setzen (Vertagung, Aufnahme in die Tagesordnung). */
	public function setInstanceStatus(int $instanceId, string $status): void {
		$this->rows->update(self::INSTANCES, $instanceId, ['status' => $status, 'updatedAt' => $this->time->getTime()]);
	}

	/**
	 * Pflichtprüfung je Schritt laut Gate-Definition des Gate-Sets.
	 *
	 * @return list<array{key: string, ok: bool, detail?: string}>
	 */
	public function checks(Demand $demand, string $step): array {
		$set = $this->governance->gateSetFor($demand);
		$keys = $this->gateDef($set, $step === 'candidate' ? 'g2' : $step)['requiredChecks'] ?? [];
		$checks = [];
		foreach ($keys as $key) {
			if ($step === 'candidate' && in_array($key, ['capacity', 'open_conditions'], true)) {
				continue;
			}
			$checks[] = $this->check($demand, (string)$key);
		}
		return $checks;
	}

	/**
	 * Board oder PMO des Bereichs entscheiden direkt – nie über das eigene Vorhaben
	 * (Funktionstrennung); „Zur Priorisierung“ stellt das PMO.
	 */
	public function canDecide(string $userId, Demand $demand, string $step): bool {
		if ($demand->getRequestorId() === $userId) {
			return false;
		}
		if ($step === 'candidate') {
			return $this->permissions->canTriage($userId, $demand);
		}
		if ($this->governance->deciderFor($step, $demand)['kind'] === 'body') {
			return false;
		}
		return $this->permissions->isAdmin($userId) || $this->permissions->hasRole($userId, self::DECIDE_ROLES, $demand->getOrgUnitId());
	}

	/** Gremiums-Gates bereitet das PMO des Bereichs vor (Vorlage, Tagesordnung). */
	public function canPrepare(string $userId, Demand $demand): bool {
		return $this->permissions->canTriage($userId, $demand);
	}

	/**
	 * Mögliche Gates im aktuellen Status (In Umsetzung: weitere Phase G4 oder Abnahme G5).
	 *
	 * @param array<string, mixed> $set
	 * @return list<string>
	 */
	public function steps(Demand $demand, array $set): array {
		if ($demand->getStatus() === 'in_qualification') {
			return ['candidate'];
		}
		$codes = array_column($set['gates'], 'code');
		$steps = [];
		foreach (GovernanceService::GATES as $gate) {
			if (in_array($gate, $codes, true) && in_array($demand->getStatus(), self::AT[$gate], true)) {
				$steps[] = $gate;
			}
		}
		// Ohne G4 im Set führt die Freigabe direkt zur Abnahme
		if ($demand->getStatus() === 'released' && in_array('g4', $steps, true)) {
			$steps = ['g4'];
		}
		return $steps;
	}

	// ---- intern -------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed>|null $body
	 * @param array<string, mixed> $details
	 * @throws ValidationException|ForbiddenException
	 */
	private function apply(string $userId, Demand $demand, string $step, array $data, ?array $body, array $details): Decision {
		$outcome = (string)($data['outcome'] ?? 'approved');
		$outcome = ['prioritized' => 'approved', 'released' => 'approved'][$outcome] ?? $outcome;
		if (!in_array($outcome, self::ALLOWED[$step], true)) {
			throw new ValidationException('Unknown decision');
		}
		$go = in_array($outcome, ['approved', 'approved_with_conditions'], true);
		$reason = trim((string)($data['reason'] ?? ''));
		if (!$go && $reason === '') {
			throw new ValidationException('This decision needs a reason');
		}
		$date = $data['resubmissionDate'] ?? null;
		$today = date('Y-m-d', $this->time->getTime());
		if (in_array($outcome, ['deferred', 'rework'], true)) {
			if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $today) {
				throw new ValidationException($outcome === 'rework' ? 'Rework needs a due date' : 'Deferring needs a resubmission date');
			}
		}
		$items = $this->conditions->validate($data['conditions'] ?? []);
		$kind = $step === 'g6' || $outcome === 'rework' ? 'action' : 'condition';
		if ($outcome === 'approved_with_conditions' && $items === []) {
			throw new ValidationException('A conditional decision needs at least one condition');
		}
		if ($step === 'g6' && $outcome === 'rejected' && $items === []) {
			throw new ValidationException('A missed benefit needs at least one action');
		}
		$set = $this->governance->gateSetFor($demand);
		$tranche = null;
		$t = $data['tranche'] ?? null;
		if ($go && is_array($t) && ($t['amount'] ?? '') !== '' && $t['amount'] !== null) {
			if ($this->gateDef($set, $step)['budgetTranche'] !== 1) {
				throw new ValidationException('This gate releases no budget tranche');
			}
			$amount = (float)$t['amount'];
			$year = (int)($t['fiscalYear'] ?? date('Y', $this->time->getTime()));
			if ($amount <= 0 || $year < 2000 || $year > 2100) {
				throw new ValidationException('The tranche needs an amount and a fiscal year');
			}
			$tranche = ['amount' => $amount, 'fiscalYear' => $year];
		}
		if ($go) {
			$this->requireChecks($demand, $step);
		}

		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			if ($step === 'g3' && $go) {
				$conflict = $this->capacityConflict($demand);
				if ($conflict !== null) {
					throw new ValidationException('Not enough capacity in pool %1$s in %2$s (%3$s PD needed, %4$s PD free)', $conflict);
				}
			}
			$status = $demand->getStatus();
			if ($go) {
				$status = self::ON_GO[$step];
			} elseif ($outcome === 'deferred') {
				$status = match ($step) {
					'g2' => 'deferred',
					'g4' => 'paused',
					default => $status, // G3: Startverschiebung, bleibt priorisiert
				};
			} elseif ($outcome === 'rejected') {
				$status = in_array($step, ['g2', 'g3'], true) ? 'rejected' : ($step === 'g6' ? 'closed' : $status);
			} elseif ($outcome === 'cancelled') {
				$status = 'cancelled';
			}
			if ($outcome === 'deferred' || $outcome === 'rework') {
				$demand->setResubmissionDate((string)$date);
				$details['resubmissionDate'] = $date;
			}
			if ($step === 'g2') {
				if ($outcome === 'deferred') {
					$demand->setTriageCode('deferred');
				} elseif ($outcome === 'rejected') {
					$demand->setTriageCode('rejected');
					$demand->setTriageReason($reason);
				} elseif ($go && $demand->getProjectKey() === null) {
					$year = date('Y', $now);
					$demand->setProjectKey('PRJ-' . $year . '-' . str_pad((string)$this->counters->next('project-' . $year), 4, '0', STR_PAD_LEFT));
					$details['projectKey'] = $demand->getProjectKey();
				}
			}
			if ($step === 'g3' && $go) {
				$demand->setReleasedAt($now);
				$demand->setResubmissionDate(null);
			}
			if ($step === 'g4' && $go) {
				$demand->setResubmissionDate(null);
			}
			if ($go && in_array($step, ['g2', 'g3', 'g4'], true)) {
				$details['businessCaseVersion'] = $this->businessCases->freeze($userId, $demand, strtoupper($step));
			}
			if ($tranche !== null) {
				$details['tranche'] = $tranche;
			}
			if ($items !== []) {
				$details['conditions'] = count($items);
			}
			$this->setStatus($userId, $demand, $status, $reason === '' ? null : $reason);

			$mandate = $body === null ? null : ['role' => 'body', 'scopeType' => 'body', 'scopeId' => (int)$body['id']];
			if ($body !== null) {
				$details['body'] = $body['name'];
			}
			$decision = $this->decisions->record($userId, $demand, $step, $outcome, $reason === '' ? null : $reason, $details, false, $mandate);

			if ($step === 'g3' && $go) {
				foreach ($this->validNeeds($demand) as $need) {
					$this->allocations->insert($demand->getId(), $need->getPoolId(), $need->getPeriod(), $need->getPersonDays(), 'hard', $decision->getId(), $userId, $now);
				}
			}
			// Abbruch gibt alle Zusagen ab sofort frei, die Abnahme ab dem Folgemonat
			if ($outcome === 'cancelled' || ($step === 'g5' && $go)) {
				$from = $outcome === 'cancelled' ? date('Y-m', $now) : date('Y-m', (int)strtotime('first day of next month', $now));
				$released = $this->allocations->releaseFrom($demand->getId(), $from);
				if ($released > 0) {
					$this->audit->log(DemandService::ENTITY, $demand->getId(), 'allocations_released', $userId, ['personDays' => [$released, 0]], 'from ' . $from);
				}
			}
			if ($tranche !== null) {
				$this->rows->insert(self::TRANCHES, $tranche + ['demandId' => $demand->getId(), 'gateCode' => $step, 'decisionId' => $decision->getId(), 'createdBy' => $userId, 'createdAt' => $now]);
			}
			$this->conditions->createFor($userId, $demand, $decision->getId(), $body === null ? null : (int)$body['id'], $kind, $items);

			$instance = $this->openInstance($demand, $step) ?? $this->createInstance($userId, $demand, $step);
			$this->rows->update(self::INSTANCES, $instance['id'], [
				'status' => $outcome === 'rework' ? 'open' : 'decided',
				'decisionId' => $decision->getId(),
				'completeness' => $this->checks($demand, $step),
				'updatedAt' => $now,
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $decision;
	}

	/** @throws ValidationException */
	private function requireChecks(Demand $demand, string $step): void {
		$failed = array_filter($this->checks($demand, $step), static fn (array $c) => !$c['ok'] && $c['key'] !== 'capacity');
		if ($failed !== []) {
			throw new ValidationException('Required information is missing: %1$s', [implode(', ', array_column($failed, 'key'))]);
		}
	}

	/** @throws ForbiddenException */
	private function requireStep(Demand $demand, string $step): void {
		if (!in_array($step, GovernanceService::GATES, true)
			|| !in_array($step, $this->steps($demand, $this->governance->gateSetFor($demand)), true)) {
			throw new ForbiddenException('The demand is not at this gate');
		}
	}

	private function setStatus(string $userId, Demand $demand, string $status, ?string $reason): void {
		$from = $demand->getStatus();
		$now = $this->time->getTime();
		$demand->setStatus($status);
		$demand->setTriagedBy($userId);
		$demand->setTriagedAt($now);
		$demand->setUpdatedAt($now);
		$this->demandMapper->update($demand);
		if ($from !== $status) {
			$this->audit->log(DemandService::ENTITY, $demand->getId(), 'status_change', $userId, ['status' => [$from, $status]], $reason);
		}
	}

	/** @return array{key: string, ok: bool, detail?: string} */
	private function check(Demand $demand, string $key): array {
		switch ($key) {
			case 'org_unit':
				return ['key' => $key, 'ok' => $demand->getOrgUnitId() !== null];
			case 'project_class':
				return ['key' => $key, 'ok' => $demand->getProjectClass() !== null];
			case 'objectives':
				$linked = array_filter($this->objectives->links(), static fn (array $l) => $l['demandId'] === $demand->getId() && $l['createdAt'] >= $demand->getCreatedAt());
				return ['key' => $key, 'ok' => $linked !== []];
			case 'assessment_review':
				$current = AssessmentService::current($this->assessments->valid($demand, $this->scoring->assessments($demand->getId())));
				return ['key' => $key, 'ok' => $current !== null && $current['kind'] === 'review'];
			case 'business_case':
				$missing = $this->businessCases->missingFor($demand);
				return ['key' => $key, 'ok' => $missing === [], 'detail' => implode(', ', $missing)];
			case 'resource_needs':
				return ['key' => $key, 'ok' => $this->validNeeds($demand) !== []];
			case 'capacity':
				// Nur vor der harten Zusage sinnvoll; danach ist die Kapazität bereits gebunden
				$conflict = $demand->getStatus() === 'prioritized' ? $this->capacityConflict($demand) : null;
				return ['key' => $key, 'ok' => $conflict === null, 'detail' => $conflict === null ? '' : vsprintf('%s %s: %s / %s', $conflict)];
			case 'open_conditions':
				$open = $this->conditions->openBlocking($demand);
				return ['key' => $key, 'ok' => $open === 0, 'detail' => $open === 0 ? '' : (string)$open];
			case 'planning_link':
				return ['key' => $key, 'ok' => $this->planningLinks->find($demand->getId(), 'gantt') !== null];
		}
		return ['key' => $key, 'ok' => true];
	}

	/**
	 * @param array<string, mixed> $set
	 * @return array<string, mixed>
	 */
	private function gateDef(array $set, string $code): array {
		foreach ($set['gates'] as $gate) {
			if ($gate['code'] === $code) {
				return $gate;
			}
		}
		return ['code' => $code, 'requiredChecks' => [], 'budgetTranche' => 0];
	}

	/** @return list<array<string, mixed>> Gate-Instanzen des Vorhabens (ab dem Anlegen) */
	private function instances(Demand $demand): array {
		return array_values(array_filter(
			$this->rows->where(self::INSTANCES, ['demandId' => $demand->getId()]),
			static fn (array $i) => $i['createdAt'] >= $demand->getCreatedAt(),
		));
	}

	/** @return array<string, mixed>|null offene (nicht entschiedene) Instanz des Gates */
	private function openInstance(Demand $demand, string $gate): ?array {
		foreach (array_reverse($this->instances($demand)) as $i) {
			if ($i['gateCode'] === $gate && $i['status'] !== 'decided') {
				return $i;
			}
		}
		return null;
	}

	/** @return array<string, mixed> */
	private function createInstance(string $userId, Demand $demand, string $gate): array {
		$now = $this->time->getTime();
		$set = $this->governance->gateSetFor($demand);
		$id = $this->rows->insert(self::INSTANCES, [
			'demandId' => $demand->getId(), 'gateCode' => $gate, 'setId' => $set['id'], 'status' => 'open',
			'createdBy' => $userId, 'createdAt' => $now, 'updatedAt' => $now,
		]);
		return (array)$this->rows->find(self::INSTANCES, $id);
	}

	/** @return list<array<string, mixed>> */
	private function tranches(Demand $demand): array {
		return array_values(array_filter(
			$this->rows->where(self::TRANCHES, ['demandId' => $demand->getId()]),
			static fn (array $t) => $t['createdAt'] >= $demand->getCreatedAt(),
		));
	}

	/**
	 * Erster Engpass-Pool und Monat, in dem der Bedarf die freie Projekt-Kapazität übersteigt.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string}|null Pool, Monat, Bedarf, frei
	 */
	private function capacityConflict(Demand $demand): ?array {
		$hard = $this->allocations->hardByPoolAndPeriod();
		$pools = [];
		foreach ($this->pools->findAll() as $pool) {
			$pools[$pool->getId()] = $pool;
		}
		$wanted = [];
		foreach ($this->validNeeds($demand) as $need) {
			$wanted[$need->getPoolId()][$need->getPeriod()] = ($wanted[$need->getPoolId()][$need->getPeriod()] ?? 0) + $need->getPersonDays();
		}
		ksort($wanted);
		foreach ($wanted as $poolId => $months) {
			$pool = $pools[$poolId] ?? null;
			if (!$pool instanceof Pool || $pool->getIsBottleneck() !== 1) {
				continue;
			}
			ksort($months);
			foreach ($months as $period => $days) {
				$project = $this->capacity->capacityRows($pool, $period, 1)[0]['project'];
				$free = $project - ($hard[$poolId][$period] ?? 0.0);
				if ($days > $free + 0.001) {
					return [$pool->getCode(), $period, (string)round($days, 1), (string)round(max(0, $free), 1)];
				}
			}
		}
		return null;
	}

	/** @return list<ResourceNeed> */
	private function validNeeds(Demand $demand): array {
		return array_values(array_filter(
			$this->needs->findForDemand($demand->getId()),
			static fn (ResourceNeed $n) => $n->getUpdatedAt() >= $demand->getCreatedAt(),
		));
	}
}
