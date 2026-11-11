<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Decision;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Change Requests (CHG-001, Kapitel 02 §4.4): Änderungen an Umfang, Termin, Budget, Ressourcen
 * oder Nutzen mit Pflicht-Auswirkungsanalyse. Die Schwellenlogik entscheidet, wer genehmigt:
 * unterhalb Projektleitung bzw. PMO (Lenkungsausschuss), oberhalb die Instanz laut
 * Entscheidungsrechte-Matrix (Typ „change“). Eine Genehmigung erzeugt eine neue Baseline.
 */
class ChangeService {
	public const TYPES = ['scope', 'time', 'cost', 'resource', 'benefit'];
	private const TABLE = 'nx_changes';
	private const DECIDE_ROLES = ['board_member', 'portfolio_manager', 'admin'];

	public function __construct(
		private Rows $rows,
		private DemandService $demands,
		private ProjectService $projects,
		private GovernanceService $governance,
		private DecisionService $decisions,
		private CapacityService $capacity,
		private PoolMapper $pools,
		private PermissionService $permissions,
		private SettingsService $settings,
		private NotificationService $notifications,
		private AuditService $audit,
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(string $userId, int $demandId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$result = [];
		foreach (array_reverse($this->rows->where(self::TABLE, ['demandId' => $demandId])) as $c) {
			if ($c['createdAt'] >= $demand->getCreatedAt()) {
				$result[] = $this->decorate($userId, $demand, $c);
			}
		}
		return $result;
	}

	/**
	 * Entwurf anlegen oder ändern.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function save(string $userId, int $demandId, ?int $id, array $data): array {
		$demand = $this->projects->manageable($userId, $demandId);
		if (!in_array($demand->getStatus(), ProjectService::ACTIVE, true)) {
			throw new ValidationException('Change requests are possible for running projects only');
		}
		$before = null;
		if ($id !== null) {
			$before = $this->change($id, $demandId);
			if ($before['status'] !== 'draft') {
				throw new ValidationException('Only drafts can be changed');
			}
		}
		$values = $this->validate($data, $before);
		$now = $this->time->getTime();
		if ($id === null) {
			$id = $this->rows->insert(self::TABLE, $values + ['demandId' => $demandId, 'status' => 'draft', 'origin' => 'manual', 'createdBy' => $userId, 'createdAt' => $now, 'updatedAt' => $now]);
			$this->audit->log(DemandService::ENTITY, $demandId, 'change_create', $userId, ['change' => [null, $values['title']]]);
		} else {
			$this->rows->update(self::TABLE, $id, $values + ['updatedAt' => $now]);
		}
		return $this->decorate($userId, $demand, $this->change($id, $demandId));
	}

	/**
	 * Einreichen: Auswirkungsanalyse vollständig, Schwellen auswerten, zuständige Instanz bestimmen.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function submit(string $userId, int $demandId, int $id): array {
		$demand = $this->projects->manageable($userId, $demandId);
		$change = $this->change($id, $demandId);
		if ($change['status'] !== 'draft') {
			throw new ValidationException('Only drafts can be changed');
		}
		if (trim((string)$change['impactAnalysis']) === '' || trim((string)$change['deltaRisk']) === '' || trim((string)$change['deltaBenefit']) === '') {
			throw new ValidationException('The impact analysis must cover cost, time, resources, risk and benefit');
		}
		$reasons = $this->thresholdReasons($demand, $change);
		$body = null;
		if ($reasons !== []) {
			$decider = $this->governance->deciderFor('change', $demand);
			$body = $decider['kind'] === 'body' ? (int)$decider['body']['id'] : null;
		}
		$this->rows->update(self::TABLE, $id, [
			'status' => 'submitted', 'aboveThreshold' => $reasons === [] ? 0 : 1, 'thresholdReasons' => $reasons,
			'bodyId' => $body, 'updatedAt' => $this->time->getTime(),
		]);
		$this->audit->log(DemandService::ENTITY, $demandId, 'change_submit', $userId, ['change' => [$change['title'], $change['title']], 'status' => ['draft', 'submitted']], $reasons === [] ? 'below threshold' : implode(', ', $reasons));
		$this->notifications->changeSubmitted($demand, (string)$change['title']);
		return $this->decorate($userId, $demand, $this->change($id, $demandId));
	}

	/**
	 * Direkt entscheiden: unterhalb der Schwelle Projektleitung/PMO, oberhalb ohne zuständiges
	 * Gremium Board bzw. PMO des Bereichs. Ein Gremium entscheidet in der Sitzung.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function decide(string $userId, int $demandId, int $id, string $outcome, ?string $reason): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$change = $this->change($id, $demandId);
		if (!$this->canDecide($userId, $demand, $change)) {
			throw new ForbiddenException($change['bodyId'] !== null
				? 'This change request is decided by the body in a session'
				: 'Only the responsible level may decide on the change request');
		}
		$this->apply($userId, $demand, $change, $outcome, $reason, null, []);
		return $this->decorate($userId, $demand, $this->change($id, $demandId));
	}

	/**
	 * Beschluss eines Gremiums in der Sitzung (Quorum und Stimmen prüft der SessionService).
	 *
	 * @param array<string, mixed> $body
	 * @param array<string, mixed> $details
	 * @throws ValidationException
	 */
	public function decideInSession(string $userId, Demand $demand, int $id, string $outcome, ?string $reason, array $body, array $details): Decision {
		$change = $this->change($id, $demand->getId());
		return $this->apply($userId, $demand, $change, $outcome, $reason, ['role' => 'body', 'scopeType' => 'body', 'scopeId' => (int)$body['id']], $details + ['body' => $body['name']]);
	}

	/** @return list<array<string, mixed>> eingereichte Change Requests eines Gremiums (Tagesordnung) */
	public function submittedFor(int $bodyId): array {
		return $this->rows->where(self::TABLE, ['bodyId' => $bodyId, 'status' => 'submitted']);
	}

	/** @return array<string, mixed>|null */
	public function find(int $id): ?array {
		return $this->rows->find(self::TABLE, $id);
	}

	/**
	 * Nachfinanzierung ist kein formloser Vorgang (Kapitel 03 §7.2): Überschreitet der FAC den
	 * Budgetrahmen über die Toleranz, entsteht automatisch ein Change Request vom Typ „cost“.
	 */
	public function checkForecast(string $userId, Demand $demand): void {
		$finance = $this->projects->finance($demand);
		if ($finance['frame'] === null || $finance['forecast'] === null || $finance['frame'] <= 0) {
			return;
		}
		if ($finance['forecast'] <= $finance['frame'] * (1 + $this->settings->get('facTolerancePct') / 100)) {
			return;
		}
		foreach ($this->rows->where(self::TABLE, ['demandId' => $demand->getId()]) as $c) {
			if ($c['changeType'] === 'cost' && in_array($c['status'], ['draft', 'submitted'], true) && $c['createdAt'] >= $demand->getCreatedAt()) {
				return;
			}
		}
		$now = $this->time->getTime();
		$delta = round($finance['forecast'] - $finance['frame'], 2);
		$this->rows->insert(self::TABLE, [
			'demandId' => $demand->getId(), 'title' => 'Nachfinanzierung (Forecast über Budgetrahmen)',
			'changeType' => 'cost', 'deltaBudget' => $delta, 'status' => 'draft', 'origin' => 'system',
			'createdBy' => $userId, 'createdAt' => $now, 'updatedAt' => $now,
		]);
		$this->audit->log(DemandService::ENTITY, $demand->getId(), 'change_create', 'system', ['change' => [null, 'cost +' . $delta]], 'forecast over budget');
	}

	// ---- intern -------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $change
	 * @param array{role: string, scopeType: string, scopeId: int|null}|null $mandate
	 * @param array<string, mixed> $details
	 * @throws ValidationException
	 */
	private function apply(string $userId, Demand $demand, array $change, string $outcome, ?string $reason, ?array $mandate, array $details): Decision {
		if ($change['status'] !== 'submitted') {
			throw new ValidationException('The change request has not been submitted');
		}
		if (!in_array($outcome, ['approved', 'rejected'], true)) {
			throw new ValidationException('Unknown decision');
		}
		$reason = trim((string)$reason);
		if ($reason === '') {
			throw new ValidationException('This decision needs a reason');
		}
		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			$details += ['change' => $change['title'], 'aboveThreshold' => $change['aboveThreshold'] === 1];
			$decision = $this->decisions->record($userId, $demand, 'change', $outcome, $reason, $details, false, $mandate);
			$values = ['status' => $outcome === 'approved' ? 'incorporated' : 'rejected', 'decisionId' => $decision->getId(), 'updatedAt' => $now];
			if ($outcome === 'approved') {
				// Re-Baselining: frühere Baselines bleiben, Abweichungen gegen beide
				$baseline = $this->projects->baselines($demand)[0] ?? null;
				$project = $this->projects->projectRow($demand) ?? [];
				$end = $baseline['plannedEnd'] ?? $project['plannedEnd'] ?? null;
				if ($end !== null && (int)$change['deltaDays'] !== 0) {
					$end = date('Y-m-d', (int)strtotime($end . ' ' . sprintf('%+d', (int)$change['deltaDays']) . ' days'));
				}
				$extraDays = array_sum(array_map('floatval', (array)($change['deltaPersonDays'] ?? [])));
				$values['baselineId'] = $this->projects->createBaseline($userId, $demand, $decision->getId(), (int)$change['id'], 'CR: ' . $change['title'], [
					'plannedEnd' => $end,
					'budgetFrame' => ($baseline['budgetFrame'] ?? 0) + (float)($change['deltaBudget'] ?? 0),
					'personDays' => ($baseline['personDays'] ?? 0) + $extraDays,
				]);
				if ($end !== null) {
					$this->projects->saveProjectSystem($demand, ['plannedEnd' => $end]);
				}
			}
			$this->rows->update(self::TABLE, (int)$change['id'], $values);
			$this->audit->log(DemandService::ENTITY, $demand->getId(), 'change_decided', $userId, ['change' => [$change['title'], $change['title']], 'status' => ['submitted', $values['status']]], $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $decision;
	}

	/**
	 * Schwellen (Kapitel 02 §4.4): Budget +x % oder +y €, Termin +n Tage, Ressourcen +z % in einem
	 * Engpass-Pool, jede Änderung des zugesagten Nutzens.
	 *
	 * @param array<string, mixed> $change
	 * @return list<string>
	 */
	public function thresholdReasons(Demand $demand, array $change): array {
		$reasons = [];
		$frame = $this->projects->finance($demand)['frame'];
		$budget = (float)($change['deltaBudget'] ?? 0);
		if ($budget > 0 && ($budget >= $this->settings->get('crBudgetAbs') || ($frame !== null && $frame > 0 && $budget / $frame * 100 >= $this->settings->get('crBudgetPct')))) {
			$reasons[] = 'budget';
		}
		if ((int)($change['deltaDays'] ?? 0) >= $this->settings->get('crDays')) {
			$reasons[] = 'time';
		}
		$planned = [];
		foreach ((array)($this->projects->baselines($demand)[0]['resourceDemand'] ?? []) as $poolId => $months) {
			$planned[(int)$poolId] = array_sum(array_map('floatval', (array)$months));
		}
		$bottleneck = [];
		foreach ($this->pools->findAll() as $pool) {
			$bottleneck[$pool->getId()] = $pool->getIsBottleneck() === 1;
		}
		foreach ((array)($change['deltaPersonDays'] ?? []) as $poolId => $days) {
			$base = $planned[(int)$poolId] ?? 0.0;
			if (($bottleneck[(int)$poolId] ?? false) && (float)$days > 0
				&& ($base <= 0 || (float)$days / $base * 100 >= $this->settings->get('crPersonDaysPct'))) {
				$reasons[] = 'resources';
				break;
			}
		}
		if ($change['changeType'] === 'benefit') {
			$reasons[] = 'benefit';
		}
		return $reasons;
	}

	/**
	 * Portfolioauswirkung: Reicht die freie Projekt-Kapazität der Engpass-Pools im laufenden Monat
	 * für die Mehrbelastung? Sonst wird sichtbar, wer betroffen wäre.
	 *
	 * @param array<string, mixed> $change
	 * @return list<array{pool: string, extra: float, free: float, covered: bool}>
	 */
	private function portfolioImpact(array $change): array {
		$result = [];
		$month = date('Y-m', $this->time->getTime());
		$pools = [];
		foreach ($this->pools->findAll() as $pool) {
			$pools[$pool->getId()] = $pool;
		}
		$load = [];
		foreach ($this->rows->where('nx_allocations') as $a) {
			if ($a['period'] === $month) {
				$load[$a['poolId']] = ($load[$a['poolId']] ?? 0) + (float)$a['personDays'];
			}
		}
		foreach ((array)($change['deltaPersonDays'] ?? []) as $poolId => $days) {
			$pool = $pools[(int)$poolId] ?? null;
			if ($pool === null || (float)$days <= 0) {
				continue;
			}
			$capacity = $this->capacity->capacityRows($pool, $month, 1)[0]['project'];
			$free = max(0.0, $capacity - ($load[(int)$poolId] ?? 0));
			$result[] = ['pool' => $pool->getName(), 'extra' => (float)$days, 'free' => round($free, 1), 'covered' => (float)$days <= $free];
		}
		return $result;
	}

	/**
	 * @param array<string, mixed> $change
	 */
	private function canDecide(string $userId, Demand $demand, array $change): bool {
		if ($change['status'] !== 'submitted' || $change['createdBy'] === $userId && !$this->permissions->canTriage($userId, $demand)) {
			return false;
		}
		if ($change['bodyId'] !== null) {
			return false;
		}
		if ($change['aboveThreshold'] === 1) {
			return $demand->getRequestorId() !== $userId
				&& ($this->permissions->isAdmin($userId) || $this->permissions->hasRole($userId, self::DECIDE_ROLES, $demand->getOrgUnitId()));
		}
		// unterhalb der Schwelle: Lenkungsausschuss bzw. Projektleitung (Klasse S) oder PMO
		$project = $this->projects->projectRow($demand) ?? [];
		return ($project['managerId'] ?? null) === $userId || ($project['sponsorId'] ?? null) === $userId
			|| $this->permissions->canTriage($userId, $demand) || $this->permissions->isAdmin($userId);
	}

	/**
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws ValidationException
	 */
	private function validate(array $data, ?array $before): array {
		$title = trim((string)($data['title'] ?? ($before['title'] ?? '')));
		if ($title === '' || mb_strlen($title) > 255) {
			throw new ValidationException('A title is required');
		}
		$type = (string)($data['changeType'] ?? ($before['changeType'] ?? 'scope'));
		if (!in_array($type, self::TYPES, true)) {
			throw new ValidationException('Unknown kind');
		}
		$pd = [];
		$known = array_map(static fn ($p) => $p->getId(), $this->pools->findAll());
		foreach ((array)($data['deltaPersonDays'] ?? ($before['deltaPersonDays'] ?? [])) as $poolId => $days) {
			if (!in_array((int)$poolId, $known, true) || !is_numeric($days)) {
				throw new ValidationException('Unknown pool');
			}
			if ((float)$days !== 0.0) {
				$pd[(string)(int)$poolId] = (float)$days;
			}
		}
		$text = fn (string $k) => trim((string)($data[$k] ?? ($before[$k] ?? ''))) ?: null;
		$budget = $data['deltaBudget'] ?? ($before['deltaBudget'] ?? null);
		$days = $data['deltaDays'] ?? ($before['deltaDays'] ?? null);
		return [
			'title' => $title, 'description' => $text('description'), 'changeType' => $type,
			'deltaBudget' => $budget === null || $budget === '' ? null : (float)$budget,
			'deltaDays' => $days === null || $days === '' ? null : (int)$days,
			'deltaPersonDays' => $pd === [] ? null : $pd,
			'deltaRisk' => $text('deltaRisk'), 'deltaBenefit' => $text('deltaBenefit'), 'impactAnalysis' => $text('impactAnalysis'),
		];
	}

	/**
	 * @param array<string, mixed> $change
	 * @return array<string, mixed>
	 */
	private function decorate(string $userId, Demand $demand, array $change): array {
		$canManage = $this->projects->canManage($userId, $demand);
		$body = null;
		if ($change['bodyId'] !== null) {
			try {
				$body = $this->governance->body((int)$change['bodyId']);
			} catch (NotFoundException) {
			}
		}
		return $change + [
			'reasons' => $change['status'] === 'draft' ? $this->thresholdReasons($demand, $change) : ($change['thresholdReasons'] ?? []),
			'portfolioImpact' => $this->portfolioImpact($change),
			'bodyName' => $body['name'] ?? null,
			'can' => [
				'edit' => $canManage && $change['status'] === 'draft',
				'submit' => $canManage && $change['status'] === 'draft',
				'decide' => $this->canDecide($userId, $demand, $change),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	private function change(int $id, int $demandId): array {
		$row = $this->rows->find(self::TABLE, $id);
		if ($row === null || $row['demandId'] !== $demandId) {
			throw new NotFoundException('Change request not found');
		}
		return $row;
	}
}
