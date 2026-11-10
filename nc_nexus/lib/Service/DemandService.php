<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\AuditEntry;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Antragseingang (DEM-001): Entwurf anlegen, bearbeiten, einreichen.
 *
 * Statusautomat (Kapitel 02, §4.1) – in dieser Version nur Entwurf → Eingereicht.
 */
class DemandService {
	public const ENTITY = 'demand';

	/** Antragstypen (Kapitel 05, DEMAND.demand_type). */
	public const TYPES = ['mandatory', 'strategic', 'efficiency', 'lifecycle', 'innovation'];

	public const STATUS_DRAFT = 'draft';
	public const STATUS_SUBMITTED = 'submitted';

	private const TITLE_MAX = 255;
	private const KEY_ATTEMPTS = 5;

	public function __construct(
		private DemandMapper $mapper,
		private AuditService $audit,
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/** @return list<Demand> */
	public function findAll(): array {
		return $this->mapper->findAll();
	}

	/** @throws NotFoundException */
	public function find(int $id): Demand {
		try {
			return $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Demand not found');
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws ValidationException
	 */
	public function create(string $userId, array $data): Demand {
		$demand = new Demand();
		$this->apply($demand, $data, true);
		$now = $this->time->getTime();
		$demand->setStatus(self::STATUS_DRAFT);
		$demand->setIntakeChannel('form');
		$demand->setRequestorId($userId);
		$demand->setCreatedAt($now);
		$demand->setUpdatedAt($now);

		$prefix = 'DEM-' . date('Y', $now) . '-';
		for ($attempt = 1; ; $attempt++) {
			$demand->setDemandKey($prefix . str_pad((string)($this->mapper->maxSequence($prefix) + 1), 4, '0', STR_PAD_LEFT));
			$this->db->beginTransaction();
			try {
				$demand = $this->mapper->insert($demand);
				$this->audit->log(self::ENTITY, $demand->getId(), 'create', $userId, $this->diff(array_fill_keys(Demand::EDITABLE, null), $this->snapshot($demand)));
				$this->db->commit();
				return $demand;
			} catch (DbException $e) {
				$this->db->rollBack();
				// Gleichzeitig vergebener Schlüssel → neu nummerieren
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= self::KEY_ATTEMPTS) {
					throw $e;
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function update(string $userId, int $id, array $data): Demand {
		$demand = $this->editable($userId, $id);
		$before = $this->snapshot($demand);
		$this->apply($demand, $data, false);
		$changes = $this->diff($before, $this->snapshot($demand));
		if ($changes === []) {
			return $demand;
		}
		$demand->setUpdatedAt($this->time->getTime());
		$this->db->beginTransaction();
		try {
			$demand = $this->mapper->update($demand);
			$this->audit->log(self::ENTITY, $id, 'update', $userId, $changes);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $demand;
	}

	/**
	 * Entwurf einreichen; ab dann nicht mehr durch den Antragsteller änderbar.
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function submit(string $userId, int $id): Demand {
		$demand = $this->editable($userId, $id);
		if (trim((string)$demand->getProblemStatement()) === '') {
			throw new ValidationException('A problem statement is required before submitting');
		}
		$now = $this->time->getTime();
		$demand->setStatus(self::STATUS_SUBMITTED);
		$demand->setSubmittedAt($now);
		$demand->setUpdatedAt($now);
		$this->db->beginTransaction();
		try {
			$demand = $this->mapper->update($demand);
			$this->audit->log(self::ENTITY, $id, 'status_change', $userId, ['status' => [self::STATUS_DRAFT, self::STATUS_SUBMITTED]]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $demand;
	}

	/**
	 * Nur Entwürfe dürfen gelöscht werden; eingereichte Anträge bleiben im Register.
	 *
	 * @throws NotFoundException|ForbiddenException
	 */
	public function delete(string $userId, int $id): void {
		$demand = $this->editable($userId, $id);
		$this->db->beginTransaction();
		try {
			$this->audit->log(self::ENTITY, $id, 'delete', $userId, array_map(static fn ($v) => [$v, null], $this->snapshot($demand)));
			$this->mapper->delete($demand);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @return list<AuditEntry>
	 * @throws NotFoundException
	 */
	public function history(int $id): array {
		$this->find($id);
		return $this->audit->history(self::ENTITY, $id);
	}

	/** @throws NotFoundException|ForbiddenException */
	private function editable(string $userId, int $id): Demand {
		$demand = $this->find($id);
		if ($demand->getRequestorId() !== $userId) {
			throw new ForbiddenException('Only the requestor may change this demand');
		}
		if ($demand->getStatus() !== self::STATUS_DRAFT) {
			throw new ForbiddenException('Only drafts can be changed');
		}
		return $demand;
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws ValidationException
	 */
	private function apply(Demand $demand, array $data, bool $isNew): void {
		if ($isNew || array_key_exists('title', $data)) {
			$title = trim((string)($data['title'] ?? ''));
			if ($title === '') {
				throw new ValidationException('Title is required');
			}
			if (mb_strlen($title) > self::TITLE_MAX) {
				throw new ValidationException('Title is too long');
			}
			$demand->setTitle($title);
		}
		if ($isNew || array_key_exists('demandType', $data)) {
			$type = (string)($data['demandType'] ?? '');
			if (!in_array($type, self::TYPES, true)) {
				throw new ValidationException('Unknown demand type');
			}
			$demand->setDemandType($type);
		}
		foreach (['description', 'problemStatement', 'expectedBenefit'] as $field) {
			if (array_key_exists($field, $data)) {
				$value = $data[$field] === null ? null : trim((string)$data[$field]);
				$demand->{'set' . ucfirst($field)}($value === '' ? null : $value);
			}
		}
	}

	/** @return array<string, mixed> */
	private function snapshot(Demand $demand): array {
		$values = [];
		foreach (Demand::EDITABLE as $field) {
			$values[$field] = $demand->{'get' . ucfirst($field)}();
		}
		return $values;
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	private function diff(array $before, array $after): array {
		$changes = [];
		foreach ($after as $field => $value) {
			if (($before[$field] ?? null) !== $value) {
				$changes[$field] = [$before[$field] ?? null, $value];
			}
		}
		return $changes;
	}
}
