<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\AllocationMapper;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\Pool;
use OCA\Nexus\Db\PoolCapacity;
use OCA\Nexus\Db\PoolCapacityMapper;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\ResourceNeed;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Ressourcenpools, Kapazitätsrechnung und Auslastung (POR-004/RES-001, Kapitel 04).
 *
 *   Brutto  = FTE × Arbeitstage
 *   Netto   = Brutto × (1 − Abwesenheit) × (1 − Linienarbeit) × (1 − Overhead)
 *   Projekt = Netto × (1 − Notfallreserve)   – oder die begründete Übersteuerung
 *
 * Fehlt für einen Monat ein Eintrag, gilt die FTE des letzten gepflegten Monats mit den
 * Werktagen (Mo–Fr) des jeweiligen Monats.
 */
class CapacityService {
	public const TYPES = ['internal', 'external', 'business'];
	/** Status, deren Bedarf die Auslastung belastet (Entwürfe privat, Zurückgestellte ruhen) */
	public const LOAD_STATUSES = ['submitted', 'query', 'further_analysis', 'in_qualification', 'candidate', 'prioritized', 'released', 'in_execution', 'paused'];
	private const MAX_MONTHS = 24;
	private const SEE_ROLES = ['portfolio_manager', 'pool_manager', 'board_member', 'steering_member', 'auditor', 'admin'];

	public function __construct(
		private PoolMapper $pools,
		private PoolCapacityMapper $capacities,
		private ResourceNeedMapper $needs,
		private AllocationMapper $allocations,
		private DemandMapper $demands,
		private DemandService $demandService,
		private PermissionService $permissions,
		private AuditService $audit,
		private IDBConnection $db,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	// ---- Rechte -------------------------------------------------------------------------------

	public function canSee(string $userId): bool {
		if ($this->permissions->isAdmin($userId)) {
			return true;
		}
		foreach ($this->permissions->assignmentsOf($userId) as $a) {
			if (in_array($a->getRole(), self::SEE_ROLES, true)) {
				return true;
			}
		}
		return false;
	}

	public function canManagePools(string $userId): bool {
		return $this->permissions->canManageOrg($userId);
	}

	public function canEditCapacity(string $userId, Pool $pool): bool {
		return $this->canManagePools($userId)
			|| $pool->getManagerUserId() === $userId
			|| ($pool->getOrgUnitId() !== null && $this->permissions->hasRole($userId, ['pool_manager'], $pool->getOrgUnitId()));
	}

	// ---- Pools --------------------------------------------------------------------------------

	/** @return list<Pool> @throws ForbiddenException */
	public function pools(string $userId): array {
		// Pools sind Stammdaten; Antragsteller brauchen sie für die Bedarfserfassung
		return $this->pools->findAll();
	}

	/** @throws NotFoundException */
	public function pool(int $id): Pool {
		try {
			return $this->pools->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Pool not found');
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function savePool(string $userId, ?int $id, array $data): Pool {
		if (!$this->canManagePools($userId)) {
			throw new ForbiddenException('Only the portfolio office may manage pools');
		}
		$pool = $id === null ? new Pool() : $this->pool($id);
		$before = $id === null ? [] : $pool->jsonSerialize();
		$code = strtoupper(trim((string)($data['code'] ?? $pool->getCode())));
		$name = trim((string)($data['name'] ?? $pool->getName()));
		if ($code === '' || strlen($code) > 32 || !preg_match('/^[A-Z0-9_-]+$/', $code)) {
			throw new ValidationException('The code may contain letters, digits, - and _ (max. 32)');
		}
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('A name is required');
		}
		foreach ($this->pools->findAll() as $other) {
			if ($other->getCode() === $code && $other->getId() !== $id) {
				throw new ValidationException('The code is already in use');
			}
		}
		$type = (string)($data['poolType'] ?? ($pool->getPoolType() ?: 'internal'));
		if (!in_array($type, self::TYPES, true)) {
			throw new ValidationException('Unknown pool type');
		}
		$manager = trim((string)($data['managerUserId'] ?? $pool->getManagerUserId() ?? ''));
		if ($manager !== '' && !$this->userManager->userExists($manager)) {
			throw new ValidationException('Unknown account or group');
		}
		$pool->setCode($code);
		$pool->setName($name);
		$pool->setPoolType($type);
		if (array_key_exists('orgUnitId', $data)) {
			$pool->setOrgUnitId($data['orgUnitId'] === null || $data['orgUnitId'] === '' ? null : (int)$data['orgUnitId']);
		}
		$pool->setManagerUserId($manager === '' ? null : $manager);
		$pool->setIsBottleneck(!empty($data['isBottleneck'] ?? ($pool->getIsBottleneck() === 1)) ? 1 : 0);
		$pool->setKeyPersons($this->optInt($data['keyPersons'] ?? $pool->getKeyPersons()));
		$pool->setWipLimit($this->optInt($data['wipLimit'] ?? $pool->getWipLimit()));
		$skills = trim((string)($data['skills'] ?? $pool->getSkills() ?? ''));
		$pool->setSkills($skills === '' ? null : $skills);
		$defaults = ['absencePct' => 16.0, 'linePct' => 30.0, 'overheadPct' => 12.0, 'reservePct' => 5.0];
		foreach ($defaults as $field => $default) {
			$current = $id === null ? $default : $pool->{'get' . ucfirst($field)}();
			$value = (float)($data[$field] ?? $current);
			if ($value < 0 || $value >= 100) {
				throw new ValidationException('Rates must be between 0 and 99 percent');
			}
			$pool->{'set' . ucfirst($field)}($value);
		}
		$now = $this->time->getTime();
		$pool->setUpdatedAt($now);
		if ($id === null) {
			$pool->setCreatedAt($now);
			$pool = $this->pools->insert($pool);
			$this->audit->log('pool', $pool->getId(), 'create', $userId, $this->diff([], $pool->jsonSerialize()));
		} else {
			$pool = $this->pools->update($pool);
			$this->audit->log('pool', $pool->getId(), 'update', $userId, $this->diff($before, $pool->jsonSerialize()));
		}
		return $pool;
	}

	/** @throws ForbiddenException|ValidationException|NotFoundException */
	public function deletePool(string $userId, int $id): void {
		if (!$this->canManagePools($userId)) {
			throw new ForbiddenException('Only the portfolio office may manage pools');
		}
		$pool = $this->pool($id);
		if ($this->needs->countForPool($id) > 0 || $this->allocations->countForPool($id) > 0) {
			throw new ValidationException('Demands still need this pool');
		}
		$this->db->beginTransaction();
		try {
			$this->capacities->deleteForPool($id);
			$this->pools->delete($pool);
			$this->audit->log('pool', $id, 'delete', $userId, $this->diff($pool->jsonSerialize(), []));
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	// ---- Kapazität ----------------------------------------------------------------------------

	/**
	 * Kapazität eines Monats setzen (FTE, Arbeitstage, optionale Übersteuerung mit Begründung).
	 *
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function setCapacity(string $userId, int $poolId, string $period, float $fte, ?int $workingDays, ?float $overrideDays, ?string $overrideReason): PoolCapacity {
		$pool = $this->pool($poolId);
		if (!$this->canEditCapacity($userId, $pool)) {
			throw new ForbiddenException('Only the portfolio office or the pool manager may change the capacity');
		}
		$this->checkPeriod($period);
		if ($fte < 0 || $fte > 10000) {
			throw new ValidationException('Invalid FTE');
		}
		$workingDays ??= self::weekdays($period);
		if ($workingDays < 0 || $workingDays > 31) {
			throw new ValidationException('Invalid number of working days');
		}
		$overrideReason = trim((string)$overrideReason);
		if ($overrideDays !== null && ($overrideDays < 0 || $overrideReason === '')) {
			throw new ValidationException('An override needs a reason');
		}
		try {
			$cap = $this->capacities->findFor($poolId, $period);
			$isNew = false;
		} catch (DoesNotExistException) {
			$cap = new PoolCapacity();
			$cap->setPoolId($poolId);
			$cap->setPeriod($period);
			$isNew = true;
		}
		$before = $isNew ? [] : $cap->jsonSerialize();
		$cap->setFte($fte);
		$cap->setWorkingDays($workingDays);
		$cap->setOverrideDays($overrideDays);
		$cap->setOverrideReason($overrideDays === null ? null : $overrideReason);
		$cap->setUpdatedBy($userId);
		$cap->setUpdatedAt($this->time->getTime());
		$cap = $isNew ? $this->capacities->insert($cap) : $this->capacities->update($cap);
		$this->audit->log('pool', $poolId, 'capacity', $userId, $this->diff($before, $cap->jsonSerialize()), $period);
		return $cap;
	}

	/**
	 * Kapazität eines Pools über einen Zeitraum, mit Rechenweg.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function capacityRows(Pool $pool, string $from, int $months): array {
		$periods = self::periods($from, $months);
		$rows = [];
		$entries = [];
		foreach ($this->capacities->findRange('0000-00', end($periods), $pool->getId()) as $cap) {
			$entries[$cap->getPeriod()] = $cap;
		}
		ksort($entries);
		foreach ($periods as $period) {
			$own = $entries[$period] ?? null;
			$base = $own;
			if ($base === null) {
				foreach ($entries as $p => $cap) {
					if ($p < $period) {
						$base = $cap;
					}
				}
			}
			$fte = $base?->getFte() ?? 0.0;
			$days = $own?->getWorkingDays() ?? self::weekdays($period);
			$calc = self::calculate($pool, $fte, $days);
			$override = $own?->getOverrideDays();
			$rows[] = [
				'period' => $period,
				'fte' => $fte,
				'workingDays' => $days,
				'inherited' => $own === null,
				'gross' => $calc['gross'],
				'net' => $calc['net'],
				'project' => $override ?? $calc['project'],
				'calculated' => $calc['project'],
				'overrideDays' => $override,
				'overrideReason' => $own?->getOverrideReason(),
			];
		}
		return $rows;
	}

	/**
	 * @return array{gross: float, net: float, project: float}
	 */
	public static function calculate(Pool $pool, float $fte, int $workingDays): array {
		$gross = $fte * $workingDays;
		$net = $gross * (1 - $pool->getAbsencePct() / 100) * (1 - $pool->getLinePct() / 100) * (1 - $pool->getOverheadPct() / 100);
		$project = $net * (1 - $pool->getReservePct() / 100);
		return ['gross' => round($gross, 1), 'net' => round($net, 1), 'project' => round($project, 1)];
	}

	// ---- Bedarf je Antrag -----------------------------------------------------------------------

	/**
	 * @return list<ResourceNeed>
	 * @throws NotFoundException
	 */
	public function needs(string $userId, int $demandId): array {
		$demand = $this->demandService->findReadable($userId, $demandId);
		return array_values(array_filter(
			$this->needs->findForDemand($demandId),
			static fn (ResourceNeed $n) => $n->getUpdatedAt() >= $demand->getCreatedAt(),
		));
	}

	/**
	 * Bedarf eines Antrags vollständig ersetzen (Liste aus Pool, Monat, Personentagen).
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<ResourceNeed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function replaceNeeds(string $userId, int $demandId, array $rows): array {
		$demand = $this->demandService->findReadable($userId, $demandId);
		if (!$this->demandService->canChange($userId, $demand)) {
			throw new ForbiddenException('Only the requestor may change this demand');
		}
		$pools = [];
		foreach ($this->pools->findAll() as $pool) {
			$pools[$pool->getId()] = $pool;
		}
		$clean = [];
		foreach ($rows as $row) {
			$poolId = (int)($row['poolId'] ?? 0);
			$period = (string)($row['period'] ?? '');
			$days = (float)($row['personDays'] ?? 0);
			if (!isset($pools[$poolId])) {
				throw new ValidationException('Pool not found');
			}
			$this->checkPeriod($period);
			if ($days < 0 || $days > 100000) {
				throw new ValidationException('Invalid number of person days');
			}
			if ($days > 0) {
				$key = $poolId . '|' . $period;
				$clean[$key] = ['poolId' => $poolId, 'period' => $period, 'personDays' => round(($clean[$key]['personDays'] ?? 0) + $days, 1)];
			}
		}
		$before = array_map(static fn (ResourceNeed $n) => [$n->getPoolId(), $n->getPeriod(), $n->getPersonDays()], $this->needs->findForDemand($demandId));
		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			$this->needs->deleteForDemand($demandId);
			$result = [];
			foreach ($clean as $row) {
				$need = new ResourceNeed();
				$need->setDemandId($demandId);
				$need->setPoolId($row['poolId']);
				$need->setPeriod($row['period']);
				$need->setPersonDays($row['personDays']);
				$need->setSource('estimate');
				$need->setUpdatedBy($userId);
				$need->setUpdatedAt($now);
				$result[] = $this->needs->insert($need);
			}
			$after = array_map(static fn ($r) => [$r['poolId'], $r['period'], $r['personDays']], array_values($clean));
			if ($before !== $after) {
				$this->audit->log(DemandService::ENTITY, $demandId, 'update', $userId, ['resourceNeeds' => [$before, $after]]);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $result;
	}

	// ---- Auslastung ---------------------------------------------------------------------------

	/**
	 * Heatmap: je Pool und Monat Projekt-Kapazität, Bedarf und Auslastung in Prozent.
	 *
	 * @return array{periods: list<string>, pools: list<array<string, mixed>>}
	 * @throws ForbiddenException|ValidationException
	 */
	public function heatmap(string $userId, string $from, int $months): array {
		if (!$this->canSee($userId)) {
			throw new ForbiddenException('Only governance roles may see the capacity');
		}
		$this->checkPeriod($from);
		$periods = self::periods($from, $months);
		$load = $this->loadByPoolAndPeriod($from, end($periods));
		$result = [];
		foreach ($this->pools->findAll() as $pool) {
			$cells = [];
			foreach ($this->capacityRows($pool, $from, count($periods)) as $row) {
				$demand = round($load[$pool->getId()][$row['period']] ?? 0.0, 1);
				$cells[] = [
					'period' => $row['period'],
					'capacity' => $row['project'],
					'demand' => $demand,
					'load' => $row['project'] > 0 ? (int)round($demand / $row['project'] * 100) : ($demand > 0 ? null : 0),
				];
			}
			$result[] = ['pool' => $pool->jsonSerialize() + ['canEdit' => $this->canEditCapacity($userId, $pool)], 'cells' => $cells];
		}
		return ['periods' => $periods, 'pools' => $result];
	}

	/**
	 * Welche Anträge belasten einen Pool in einem Monat? Nicht lesbare nur als Summe.
	 *
	 * @return array{demands: list<array<string, mixed>>, otherDays: float}
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function cell(string $userId, int $poolId, string $period): array {
		if (!$this->canSee($userId)) {
			throw new ForbiddenException('Only governance roles may see the capacity');
		}
		$this->pool($poolId);
		$this->checkPeriod($period);
		$demands = $this->activeDemands();
		$visible = [];
		$other = 0.0;
		foreach ($this->needs->findRange($period, $period) as $need) {
			$demand = $demands[$need->getDemandId()] ?? null;
			if ($need->getPoolId() !== $poolId || $demand === null || $need->getUpdatedAt() < $demand->getCreatedAt()) {
				continue;
			}
			if ($this->permissions->canReadDemand($userId, $demand)) {
				$visible[] = ['id' => $demand->getId(), 'key' => $demand->getDemandKey(), 'title' => $demand->getTitle(), 'status' => $demand->getStatus(), 'personDays' => $need->getPersonDays()];
			} else {
				$other += $need->getPersonDays();
			}
		}
		usort($visible, static fn ($a, $b) => $b['personDays'] <=> $a['personDays']);
		return ['demands' => $visible, 'otherDays' => round($other, 1)];
	}

	/** @return array<int, array<string, float>> Pool => Monat => Personentage */
	private function loadByPoolAndPeriod(string $from, string $to): array {
		$demands = $this->activeDemands();
		$load = [];
		foreach ($this->needs->findRange($from, $to) as $need) {
			$demand = $demands[$need->getDemandId()] ?? null;
			if ($demand === null || $need->getUpdatedAt() < $demand->getCreatedAt()) {
				continue;
			}
			$load[$need->getPoolId()][$need->getPeriod()] = ($load[$need->getPoolId()][$need->getPeriod()] ?? 0.0) + $need->getPersonDays();
		}
		return $load;
	}

	/** @return array<int, Demand> */
	private function activeDemands(): array {
		$result = [];
		foreach ($this->demands->findAll() as $demand) {
			if (in_array($demand->getStatus(), self::LOAD_STATUSES, true)) {
				$result[$demand->getId()] = $demand;
			}
		}
		return $result;
	}

	// ---- Hilfen -------------------------------------------------------------------------------

	/** @return list<string> JJJJ-MM ab $from */
	public static function periods(string $from, int $months): array {
		$months = max(1, min(self::MAX_MONTHS, $months));
		$date = new \DateTimeImmutable($from . '-01');
		$result = [];
		for ($i = 0; $i < $months; $i++) {
			$result[] = $date->modify("+$i month")->format('Y-m');
		}
		return $result;
	}

	/** Werktage (Mo–Fr) eines Monats JJJJ-MM, ohne Feiertage. */
	public static function weekdays(string $period): int {
		$date = new \DateTimeImmutable($period . '-01');
		$days = (int)$date->format('t');
		$count = 0;
		for ($d = 0; $d < $days; $d++) {
			if ((int)$date->modify("+$d day")->format('N') <= 5) {
				$count++;
			}
		}
		return $count;
	}

	/** @throws ValidationException */
	private function checkPeriod(string $period): void {
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
			throw new ValidationException('Invalid month');
		}
	}

	private function optInt(mixed $value): ?int {
		return $value === null || $value === '' ? null : max(0, (int)$value);
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	private function diff(array $before, array $after): array {
		$changes = [];
		foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
			if ($field !== 'id' && ($before[$field] ?? null) !== ($after[$field] ?? null)) {
				$changes[$field] = [$before[$field] ?? null, $after[$field] ?? null];
			}
		}
		return $changes;
	}
}
