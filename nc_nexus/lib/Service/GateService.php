<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\AllocationMapper;
use OCA\Nexus\Db\CounterMapper;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\Pool;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\ResourceNeed;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Db\ScoringMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Weg vom bewerteten Antrag zur Freigabe (Kapitel 02 §3, vereinfacht für Stufe 2):
 *
 *   In Qualifizierung → „Zur Priorisierung“ (Kandidat): Pflichtangaben vollständig
 *   Kandidat → G2: priorisieren (wird Projekt mit Schlüssel), zurückstellen oder ablehnen
 *   Priorisiert → G3: freigeben mit harter Allokation; passt der Bedarf in einem Engpass-Pool
 *                     nicht in die verbleibende Projekt-Kapazität, wird die Freigabe abgelehnt (P4)
 *
 * Jeder Schritt wird Beschluss im Register; an G2 und G3 wird der Business Case eingefroren.
 */
class GateService {
	private const DECIDE_ROLES = ['board_member', 'portfolio_manager', 'admin'];
	private const G2_OUTCOMES = ['prioritized', 'deferred', 'rejected'];

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
		private DecisionService $decisions,
		private CounterMapper $counters,
		private PermissionService $permissions,
		private AuditService $audit,
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Prüfliste für den nächsten Schritt eines Vorhabens.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function status(string $userId, int $demandId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$step = match ($demand->getStatus()) {
			'in_qualification' => 'candidate',
			'candidate' => 'g2',
			'prioritized' => 'g3',
			default => null,
		};
		$checks = $step === null ? [] : $this->checks($demand, $step);
		return [
			'step' => $step,
			'checks' => $checks,
			'ready' => $step !== null && !in_array(false, array_column($checks, 'ok'), true),
			'canDecide' => $step !== null && $this->canDecide($userId, $demand, $step),
			'allocations' => $this->allocations->forDemand($demandId),
		];
	}

	/**
	 * Vorhaben zur Priorisierung stellen (Kandidat).
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function toCandidate(string $userId, int $demandId, ?string $reason): Demand {
		$demand = $this->prepare($userId, $demandId, 'in_qualification', 'candidate');
		return $this->transition($userId, $demand, 'candidate', 'candidate', 'ready_for_prioritization', $reason, []);
	}

	/**
	 * G2: priorisieren, zurückstellen oder ablehnen.
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function g2(string $userId, int $demandId, string $outcome, ?string $reason, ?string $resubmissionDate): Demand {
		if (!in_array($outcome, self::G2_OUTCOMES, true)) {
			throw new ValidationException('Unknown decision');
		}
		$reason = trim((string)$reason);
		if ($outcome !== 'prioritized' && $reason === '') {
			throw new ValidationException('Deferring or rejecting needs a reason');
		}
		$demand = $this->prepare($userId, $demandId, 'candidate', 'g2', $outcome === 'prioritized');
		$details = [];
		$status = ['prioritized' => 'prioritized', 'deferred' => 'deferred', 'rejected' => 'rejected'][$outcome];
		if ($outcome === 'deferred') {
			if ($resubmissionDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $resubmissionDate) || $resubmissionDate < date('Y-m-d', $this->time->getTime())) {
				throw new ValidationException('Deferring needs a resubmission date');
			}
			$demand->setResubmissionDate($resubmissionDate);
			$demand->setTriageCode('deferred');
			$details['resubmissionDate'] = $resubmissionDate;
		}
		if ($outcome === 'rejected') {
			$demand->setTriageCode('rejected');
			$demand->setTriageReason($reason);
		}
		if ($outcome === 'prioritized' && $demand->getProjectKey() === null) {
			$year = date('Y', $this->time->getTime());
			$demand->setProjectKey('PRJ-' . $year . '-' . str_pad((string)$this->counters->next('project-' . $year), 4, '0', STR_PAD_LEFT));
			$details['projectKey'] = $demand->getProjectKey();
		}
		return $this->transition($userId, $demand, $status, 'g2', $outcome, $reason === '' ? null : $reason, $details, $outcome === 'prioritized' ? 'G2' : null);
	}

	/**
	 * G3: Freigabe mit harter Allokation des Bedarfs. Integritätsregel: Σ hard je Engpass-Pool und
	 * Monat ≤ Projekt-Kapazität – sonst Ablehnung mit Pool und Monat.
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function g3(string $userId, int $demandId, ?string $reason): Demand {
		$demand = $this->prepare($userId, $demandId, 'prioritized', 'g3');
		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			$conflict = $this->capacityConflict($demand);
			if ($conflict !== null) {
				throw new ValidationException('Not enough capacity in pool %1$s in %2$s (%3$s PD needed, %4$s PD free)', $conflict);
			}
			$demand->setStatus('released');
			$demand->setReleasedAt($now);
			$demand->setUpdatedAt($now);
			$this->demandMapper->update($demand);
			$this->audit->log(DemandService::ENTITY, $demandId, 'status_change', $userId, ['status' => ['prioritized', 'released']], $reason);
			$version = $this->businessCases->freeze($userId, $demand, 'G3');
			$decision = $this->decisions->record($userId, $demand, 'g3', 'released', $reason, ['businessCaseVersion' => $version]);
			foreach ($this->validNeeds($demand) as $need) {
				$this->allocations->insert($demandId, $need->getPoolId(), $need->getPeriod(), $need->getPersonDays(), 'hard', $decision->getId(), $userId, $now);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $demand;
	}

	/**
	 * Pflichtprüfung je Schritt.
	 *
	 * @return list<array{key: string, ok: bool, detail?: string}>
	 */
	public function checks(Demand $demand, string $step): array {
		$current = AssessmentService::current($this->assessments->valid($demand, $this->scoring->assessments($demand->getId())));
		$missing = $this->businessCases->missingFor($demand);
		$checks = [
			['key' => 'assessment_review', 'ok' => $current !== null && $current['kind'] === 'review'],
			['key' => 'business_case', 'ok' => $missing === [], 'detail' => implode(', ', $missing)],
			['key' => 'resource_needs', 'ok' => $this->validNeeds($demand) !== []],
			['key' => 'org_unit', 'ok' => $demand->getOrgUnitId() !== null],
		];
		if ($step === 'g3') {
			$conflict = $this->capacityConflict($demand);
			$checks[] = ['key' => 'capacity', 'ok' => $conflict === null, 'detail' => $conflict === null ? '' : vsprintf('%s %s: %s / %s', $conflict)];
		}
		return $checks;
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

	/** @throws NotFoundException|ForbiddenException|ValidationException */
	private function prepare(string $userId, int $demandId, string $status, string $step, bool $requireComplete = true): Demand {
		$demand = $this->demands->findReadable($userId, $demandId);
		if ($demand->getStatus() !== $status) {
			throw new ForbiddenException('The demand is not at this gate');
		}
		if (!$this->canDecide($userId, $demand, $step)) {
			throw new ForbiddenException($demand->getRequestorId() === $userId ? 'You cannot decide on your own demand' : 'Only the board or the portfolio office of the area may decide');
		}
		if ($requireComplete) {
			$failed = array_filter($this->checks($demand, $step), static fn (array $c) => !$c['ok'] && $c['key'] !== 'capacity');
			if ($failed !== []) {
				throw new ValidationException('Required information is missing: %1$s', [implode(', ', array_column($failed, 'key'))]);
			}
		}
		return $demand;
	}

	/**
	 * @param array<string, mixed> $details
	 */
	private function transition(string $userId, Demand $demand, string $status, string $type, string $outcome, ?string $reason, array $details, ?string $freeze = null): Demand {
		$from = $demand->getStatus();
		$now = $this->time->getTime();
		$demand->setStatus($status);
		$demand->setTriagedBy($userId);
		$demand->setTriagedAt($now);
		$demand->setUpdatedAt($now);
		$this->db->beginTransaction();
		try {
			$demand = $this->demandMapper->update($demand);
			$this->audit->log(DemandService::ENTITY, $demand->getId(), 'status_change', $userId, ['status' => [$from, $status]], $reason);
			if ($freeze !== null) {
				$details['businessCaseVersion'] = $this->businessCases->freeze($userId, $demand, $freeze);
			}
			$this->decisions->record($userId, $demand, $type, $outcome, $reason, $details);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $demand;
	}

	/**
	 * „Zur Priorisierung“ stellt das PMO; G2 und G3 entscheiden Board oder PMO des Bereichs –
	 * nie über das eigene Vorhaben (Funktionstrennung).
	 */
	public function canDecide(string $userId, Demand $demand, string $step): bool {
		if ($demand->getRequestorId() === $userId) {
			return false;
		}
		if ($step === 'candidate') {
			return $this->permissions->canTriage($userId, $demand);
		}
		return $this->permissions->isAdmin($userId) || $this->permissions->hasRole($userId, self::DECIDE_ROLES, $demand->getOrgUnitId());
	}
}
