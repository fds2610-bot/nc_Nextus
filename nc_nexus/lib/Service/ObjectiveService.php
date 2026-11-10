<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\Objective;
use OCA\Nexus\Db\ObjectiveMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Strategische Ziele (STR-001): Zielhierarchie pflegen, Anträgen zuordnen, Abdeckung zeigen.
 * Jede inhaltliche Änderung erhöht die Version des Ziels; das Audit-Log hält alt/neu fest.
 */
class ObjectiveService {
	public function __construct(
		private ObjectiveMapper $objectives,
		private DemandService $demands,
		private PermissionService $permissions,
		private AuditService $audit,
		private IDBConnection $db,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Alle Ziele mit Abdeckung: Anzahl zugeordneter Anträge, die das Konto lesen darf.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(string $userId): array {
		$readable = [];
		foreach ($this->demands->findAll($userId) as $demand) {
			if ($demand->getStatus() !== DemandService::STATUS_DRAFT || $demand->getRequestorId() === $userId) {
				$readable[$demand->getId()] = $demand;
			}
		}
		$count = [];
		foreach ($this->objectives->links() as $link) {
			$demand = $readable[$link['demandId']] ?? null;
			if ($demand !== null && $link['createdAt'] >= $demand->getCreatedAt()) {
				$count[$link['objectiveId']] = ($count[$link['objectiveId']] ?? 0) + 1;
			}
		}
		return array_map(static fn (Objective $o) => $o->jsonSerialize() + ['demandCount' => $count[$o->getId()] ?? 0], $this->objectives->findAll());
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function save(string $userId, ?int $id, array $data): Objective {
		$this->requireManage($userId);
		$objective = $id === null ? new Objective() : $this->find($id);
		$before = $id === null ? [] : $objective->jsonSerialize();
		$code = strtoupper(trim((string)($data['code'] ?? $objective->getCode())));
		$title = trim((string)($data['title'] ?? $objective->getTitle()));
		if ($code === '' || strlen($code) > 32 || !preg_match('/^[A-Z0-9._-]+$/', $code)) {
			throw new ValidationException('The code may contain letters, digits, ., - and _ (max. 32)');
		}
		if ($title === '' || mb_strlen($title) > 255) {
			throw new ValidationException('Title is required');
		}
		foreach ($this->objectives->findAll() as $other) {
			if ($other->getCode() === $code && $other->getId() !== $id) {
				throw new ValidationException('The code is already in use');
			}
		}
		$objective->setCode($code);
		$objective->setTitle($title);
		foreach (['description', 'targetMetric'] as $field) {
			if (array_key_exists($field, $data)) {
				$value = trim((string)$data[$field]);
				$objective->{'set' . ucfirst($field)}($value === '' ? null : $value);
			}
		}
		if (array_key_exists('parentId', $data)) {
			$parentId = $data['parentId'] === null || $data['parentId'] === '' ? null : (int)$data['parentId'];
			if ($parentId !== null) {
				$this->find($parentId);
				if ($id !== null && in_array($id, $this->ancestors($parentId), true)) {
					throw new ValidationException('An objective cannot be placed below itself');
				}
			}
			$objective->setParentId($parentId);
		}
		if (array_key_exists('ownerUserId', $data)) {
			$owner = trim((string)$data['ownerUserId']);
			if ($owner !== '' && !$this->userManager->userExists($owner)) {
				throw new ValidationException('Unknown account or group');
			}
			$objective->setOwnerUserId($owner === '' ? null : $owner);
		}
		foreach (['validFrom', 'validTo'] as $field) {
			if (array_key_exists($field, $data)) {
				$objective->{'set' . ucfirst($field)}($this->date($data[$field]));
			}
		}
		if ($objective->getValidFrom() !== null && $objective->getValidTo() !== null && $objective->getValidTo() < $objective->getValidFrom()) {
			throw new ValidationException('The end date is before the start date');
		}
		$now = $this->time->getTime();
		$objective->setUpdatedAt($now);
		if ($id === null) {
			$objective->setVersion(1);
			$objective->setCreatedAt($now);
			$objective = $this->objectives->insert($objective);
			$this->audit->log('objective', $objective->getId(), 'create', $userId, $this->diff([], $objective->jsonSerialize()));
			return $objective;
		}
		$changes = $this->diff($before, $objective->jsonSerialize());
		if ($changes === []) {
			return $objective;
		}
		// inhaltliche Änderung = neue Version (alt/neu steht im Audit-Log)
		$objective->setVersion($objective->getVersion() + 1);
		$objective = $this->objectives->update($objective);
		$this->audit->log('objective', $id, 'update', $userId, $changes + ['version' => [$before['version'], $objective->getVersion()]]);
		return $objective;
	}

	/** @throws ForbiddenException|ValidationException|NotFoundException */
	public function delete(string $userId, int $id): void {
		$this->requireManage($userId);
		$objective = $this->find($id);
		foreach ($this->objectives->findAll() as $other) {
			if ($other->getParentId() === $id) {
				throw new ValidationException('The objective still has sub-objectives');
			}
		}
		foreach ($this->objectives->links() as $link) {
			if ($link['objectiveId'] === $id) {
				throw new ValidationException('Demands are still assigned to the objective');
			}
		}
		$this->objectives->delete($objective);
		$this->audit->log('objective', $id, 'delete', $userId, $this->diff($objective->jsonSerialize(), []));
	}

	/**
	 * Ziele eines Antrags.
	 *
	 * @return list<int>
	 * @throws NotFoundException
	 */
	public function ofDemand(string $userId, int $demandId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		return $this->linked($demand);
	}

	/**
	 * Zuordnung eines Antrags ersetzen.
	 *
	 * @param list<int> $objectiveIds
	 * @return list<int>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function assign(string $userId, int $demandId, array $objectiveIds): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		if (!$this->demands->canChange($userId, $demand)) {
			throw new ForbiddenException('Only the requestor may change this demand');
		}
		$known = array_map(static fn (Objective $o) => $o->getId(), $this->objectives->findAll());
		$ids = array_values(array_unique(array_map('intval', $objectiveIds)));
		if (array_diff($ids, $known) !== []) {
			throw new ValidationException('Unknown objective');
		}
		sort($ids);
		$before = $this->linked($demand);
		if ($before === $ids) {
			return $ids;
		}
		$this->db->beginTransaction();
		try {
			$this->objectives->replaceLinks($demandId, $ids, $this->time->getTime());
			$this->audit->log(DemandService::ENTITY, $demandId, 'update', $userId, ['objectives' => [$before, $ids]]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $ids;
	}

	/** @return list<int> */
	private function linked(Demand $demand): array {
		$ids = [];
		foreach ($this->objectives->links() as $link) {
			if ($link['demandId'] === $demand->getId() && $link['createdAt'] >= $demand->getCreatedAt()) {
				$ids[] = $link['objectiveId'];
			}
		}
		sort($ids);
		return $ids;
	}

	/** @throws NotFoundException */
	private function find(int $id): Objective {
		try {
			return $this->objectives->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Objective not found');
		}
	}

	/** @return list<int> das Ziel und alle übergeordneten */
	private function ancestors(int $id): array {
		$parents = [];
		foreach ($this->objectives->findAll() as $o) {
			$parents[$o->getId()] = $o->getParentId();
		}
		$chain = [];
		for ($u = $id; $u !== null && !in_array($u, $chain, true); $u = $parents[$u] ?? null) {
			$chain[] = $u;
		}
		return $chain;
	}

	/** @throws ForbiddenException */
	private function requireManage(string $userId): void {
		if (!$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may maintain the objectives');
		}
	}

	/** @throws ValidationException */
	private function date(mixed $value): ?string {
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
			throw new ValidationException('Invalid date');
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	private function diff(array $before, array $after): array {
		$changes = [];
		foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
			if (!in_array($field, ['id', 'version'], true) && ($before[$field] ?? null) !== ($after[$field] ?? null)) {
				$changes[$field] = [$before[$field] ?? null, $after[$field] ?? null];
			}
		}
		return $changes;
	}
}
