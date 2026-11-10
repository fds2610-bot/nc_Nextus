<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\OrgUnit;
use OCA\Nexus\Db\OrgUnitMapper;
use OCA\Nexus\Db\RoleAssignment;
use OCA\Nexus\Db\RoleAssignmentMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Organisationsmodell (GOV-001) und Rollenvergabe (GOV-002).
 */
class OrgService {
	private const SCOPES = ['global', 'org_unit'];

	public function __construct(
		private OrgUnitMapper $units,
		private RoleAssignmentMapper $roles,
		private PermissionService $permissions,
		private AuditService $audit,
		private IDBConnection $db,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private ITimeFactory $time,
	) {
	}

	/** @return list<OrgUnit> */
	public function units(): array {
		return $this->units->findAll();
	}

	/** @throws NotFoundException */
	public function unit(int $id): OrgUnit {
		try {
			return $this->units->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Organizational unit not found');
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function createUnit(string $userId, array $data): OrgUnit {
		$this->requireManage($userId);
		$unit = new OrgUnit();
		$this->applyUnit($unit, $data, true);
		$now = $this->time->getTime();
		$unit->setCreatedAt($now);
		$unit->setUpdatedAt($now);
		$unit = $this->units->insert($unit);
		$this->audit->log('org_unit', $unit->getId(), 'create', $userId, $this->changes([], $this->unitSnapshot($unit)));
		$this->permissions->reset();
		return $unit;
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function updateUnit(string $userId, int $id, array $data): OrgUnit {
		$this->requireManage($userId);
		$unit = $this->unit($id);
		$before = $this->unitSnapshot($unit);
		$this->applyUnit($unit, $data, false);
		$changes = $this->changes($before, $this->unitSnapshot($unit));
		if ($changes === []) {
			return $unit;
		}
		$unit->setUpdatedAt($this->time->getTime());
		$unit = $this->units->update($unit);
		$this->audit->log('org_unit', $id, 'update', $userId, $changes);
		$this->permissions->reset();
		return $unit;
	}

	/** @throws ForbiddenException|ValidationException|NotFoundException */
	public function deleteUnit(string $userId, int $id): void {
		$this->requireManage($userId);
		$unit = $this->unit($id);
		foreach ($this->units->findAll() as $other) {
			if ($other->getParentId() === $id) {
				throw new ValidationException('The unit still has subunits');
			}
		}
		if ($this->countDemands($id) > 0) {
			throw new ValidationException('Demands are still assigned to the unit');
		}
		if ($this->roles->countForScope('org_unit', $id) > 0) {
			throw new ValidationException('Roles are still assigned for the unit');
		}
		$this->units->delete($unit);
		$this->audit->log('org_unit', $id, 'delete', $userId, $this->changes($this->unitSnapshot($unit), []));
		$this->permissions->reset();
	}

	/**
	 * @return list<RoleAssignment>
	 * @throws ForbiddenException
	 */
	public function assignments(string $userId): array {
		$this->requireManage($userId);
		return $this->roles->findAll();
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function assign(string $userId, array $data): RoleAssignment {
		$this->requireManage($userId);
		$a = new RoleAssignment();
		$role = (string)($data['role'] ?? '');
		if (!in_array($role, PermissionService::ROLES, true)) {
			throw new ValidationException('Unknown role');
		}
		$scopeType = (string)($data['scopeType'] ?? 'global');
		if (!in_array($scopeType, self::SCOPES, true)) {
			throw new ValidationException('Unknown scope');
		}
		$scopeId = null;
		if ($scopeType === 'org_unit') {
			$scopeId = (int)($data['scopeId'] ?? 0);
			$this->unit($scopeId);
		}
		$principalType = (string)($data['principalType'] ?? 'user');
		$principalId = trim((string)($data['principalId'] ?? ''));
		if ($principalType === 'user' ? !$this->userManager->userExists($principalId)
			: ($principalType !== 'group' || !$this->groupManager->groupExists($principalId))) {
			throw new ValidationException('Unknown account or group');
		}
		$validFrom = $this->date($data['validFrom'] ?? null);
		$validTo = $this->date($data['validTo'] ?? null);
		if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
			throw new ValidationException('The end date is before the start date');
		}
		$deputyFor = trim((string)($data['deputyFor'] ?? ''));
		if ($deputyFor !== '' && !$this->userManager->userExists($deputyFor)) {
			throw new ValidationException('Unknown account or group');
		}

		$a->setRole($role);
		$a->setScopeType($scopeType);
		$a->setScopeId($scopeId);
		$a->setPrincipalType($principalType);
		$a->setPrincipalId($principalId);
		$a->setValidFrom($validFrom);
		$a->setValidTo($validTo);
		$a->setDeputyFor($deputyFor === '' ? null : $deputyFor);
		$a->setCreatedBy($userId);
		$a->setCreatedAt($this->time->getTime());
		$a = $this->roles->insert($a);
		$this->audit->log('role_assignment', $a->getId(), 'create', $userId, $this->changes([], $a->jsonSerialize()));
		$this->permissions->reset();
		return $a;
	}

	/** @throws ForbiddenException|NotFoundException */
	public function unassign(string $userId, int $id): void {
		$this->requireManage($userId);
		try {
			$a = $this->roles->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Role assignment not found');
		}
		$this->roles->delete($a);
		$this->audit->log('role_assignment', $id, 'delete', $userId, $this->changes($a->jsonSerialize(), []));
		$this->permissions->reset();
	}

	/** @throws ForbiddenException */
	private function requireManage(string $userId): void {
		if (!$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may change the organization');
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws ValidationException|NotFoundException
	 */
	private function applyUnit(OrgUnit $unit, array $data, bool $isNew): void {
		if ($isNew || array_key_exists('name', $data)) {
			$name = trim((string)($data['name'] ?? ''));
			if ($name === '' || mb_strlen($name) > 255) {
				throw new ValidationException('A name is required');
			}
			$unit->setName($name);
		}
		if (array_key_exists('parentId', $data)) {
			$parentId = $data['parentId'] === null || $data['parentId'] === '' ? null : (int)$data['parentId'];
			if ($parentId !== null) {
				$this->unit($parentId);
				if (!$isNew && in_array($unit->getId(), $this->permissions->ancestorsInclusive($parentId), true)) {
					throw new ValidationException('A unit cannot be placed below itself');
				}
			}
			$unit->setParentId($parentId);
		}
		if (array_key_exists('costCenter', $data)) {
			$value = trim((string)$data['costCenter']);
			$unit->setCostCenter($value === '' ? null : mb_substr($value, 0, 64));
		}
		if (array_key_exists('headUserId', $data)) {
			$value = trim((string)$data['headUserId']);
			if ($value !== '' && !$this->userManager->userExists($value)) {
				throw new ValidationException('Unknown account or group');
			}
			$unit->setHeadUserId($value === '' ? null : $value);
		}
	}

	private function countDemands(int $orgUnitId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('nx_demands')
			->where($qb->expr()->eq('org_unit_id', $qb->createNamedParameter($orgUnitId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/** @throws ValidationException */
	private function date(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		$value = (string)$value;
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
			throw new ValidationException('Invalid date');
		}
		return $value;
	}

	/** @return array<string, mixed> */
	private function unitSnapshot(OrgUnit $unit): array {
		$values = [];
		foreach (OrgUnit::EDITABLE as $field) {
			$values[$field] = $unit->{'get' . ucfirst($field)}();
		}
		return $values;
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	private function changes(array $before, array $after): array {
		$changes = [];
		foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
			$old = $before[$field] ?? null;
			$new = $after[$field] ?? null;
			if ($old !== $new && $field !== 'id') {
				$changes[$field] = [$old, $new];
			}
		}
		return $changes;
	}
}
