<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Beschluss- und Maßnahmenverfolgung (DEC-003): Auflagen (gate-blockierende Sonderform) und
 * Maßnahmen aus Beschlüssen – mit Verantwortlichem, Frist und Nachweis.
 */
class ConditionService {
	public const TABLE = 'nx_conditions';
	public const KINDS = ['condition', 'action'];

	public function __construct(
		private Rows $rows,
		private DemandMapper $demands,
		private PermissionService $permissions,
		private AuditService $audit,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Eingaben prüfen (vor dem Beschluss, damit nichts halb gespeichert wird).
	 *
	 * @param mixed $items Liste {title, ownerId, dueDate, blocking}
	 * @return list<array{title: string, ownerId: string, dueDate: string, blocking: bool}>
	 * @throws ValidationException
	 */
	public function validate(mixed $items): array {
		$result = [];
		foreach (is_array($items) ? $items : [] as $item) {
			$title = trim((string)($item['title'] ?? ''));
			$owner = trim((string)($item['ownerId'] ?? ''));
			$due = (string)($item['dueDate'] ?? '');
			if ($title === '' && $owner === '' && $due === '') {
				continue;
			}
			if ($title === '' || mb_strlen($title) > 255) {
				throw new ValidationException('Every condition needs a title');
			}
			if (!$this->userManager->userExists($owner)) {
				throw new ValidationException('Every condition needs an owner');
			}
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
				throw new ValidationException('Every condition needs a due date');
			}
			$result[] = ['title' => $title, 'ownerId' => $owner, 'dueDate' => $due, 'blocking' => (bool)($item['blocking'] ?? false)];
		}
		return $result;
	}

	/**
	 * Auflagen bzw. Maßnahmen eines Beschlusses anlegen (innerhalb der Transaktion des Beschlusses).
	 *
	 * @param list<array{title: string, ownerId: string, dueDate: string, blocking: bool}> $items
	 */
	public function createFor(string $userId, Demand $demand, ?int $decisionId, ?int $bodyId, string $kind, array $items): void {
		$now = $this->time->getTime();
		foreach ($items as $item) {
			$id = $this->rows->insert(self::TABLE, [
				'kind' => $kind, 'decisionId' => $decisionId, 'demandId' => $demand->getId(), 'bodyId' => $bodyId,
				'title' => $item['title'], 'ownerId' => $item['ownerId'], 'dueDate' => $item['dueDate'],
				'blocking' => $kind === 'condition' && $item['blocking'] ? 1 : 0, 'status' => 'open',
				'createdBy' => $userId, 'createdAt' => $now,
			]);
			$this->audit->log('condition', $id, 'create', $userId, ['title' => [null, $item['title']], 'ownerId' => [null, $item['ownerId']], 'dueDate' => [null, $item['dueDate']]]);
		}
	}

	/**
	 * Maßnahme von Hand anlegen (PMO), z. B. aus einer Sitzung ohne Gate-Beschluss.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function create(string $userId, int $demandId, array $data): array {
		$demand = $this->demand($demandId);
		if (!$this->permissions->canTriage($userId, $demand) && !$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may record actions');
		}
		$kind = in_array($data['kind'] ?? 'action', self::KINDS, true) ? (string)($data['kind'] ?? 'action') : 'action';
		$items = $this->validate([$data]);
		if ($items === []) {
			throw new ValidationException('Every condition needs a title');
		}
		$this->createFor($userId, $demand, null, null, $kind, $items);
		$rows = $this->rows->where(self::TABLE, ['demandId' => $demandId]);
		return $this->decorate($userId, end($rows) ?: [], $demand);
	}

	/**
	 * Alle Auflagen und Maßnahmen, die das Konto sehen darf (lesbare Vorhaben oder eigene Verantwortung).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list(string $userId): array {
		$demands = [];
		foreach ($this->demands->findAll() as $d) {
			$demands[$d->getId()] = $d;
		}
		$result = [];
		foreach ($this->rows->where(self::TABLE, [], 'dueDate') as $row) {
			$demand = $demands[$row['demandId']] ?? null;
			if ($demand === null || $row['createdAt'] < $demand->getCreatedAt()) {
				continue;
			}
			if ($row['ownerId'] !== $userId && !$this->permissions->canReadDemand($userId, $demand)) {
				continue;
			}
			$result[] = $this->decorate($userId, $row, $demand);
		}
		return $result;
	}

	/** @return list<array<string, mixed>> */
	public function forDemand(string $userId, Demand $demand): array {
		return array_values(array_filter($this->list($userId), static fn (array $c) => $c['demandId'] === $demand->getId()));
	}

	/** Offene blockierende Auflagen eines Vorhabens (sperren das nächste Gate). */
	public function openBlocking(Demand $demand): int {
		return count(array_filter(
			$this->rows->where(self::TABLE, ['demandId' => $demand->getId(), 'status' => 'open']),
			static fn (array $c) => $c['blocking'] === 1 && $c['createdAt'] >= $demand->getCreatedAt(),
		));
	}

	/**
	 * Erledigt melden (Verantwortlicher oder PMO) mit Nachweis, oder erlassen (nur PMO, mit Begründung).
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function close(string $userId, int $id, string $status, string $evidence): array {
		$row = $this->rows->find(self::TABLE, $id);
		if ($row === null) {
			throw new NotFoundException('Condition not found');
		}
		$demand = $this->demand((int)$row['demandId']);
		if ($row['status'] !== 'open') {
			throw new ValidationException('The condition is already closed');
		}
		$pmo = $this->permissions->canTriage($userId, $demand) || $this->permissions->canManageOrg($userId);
		if ($status === 'done') {
			if ($row['ownerId'] !== $userId && !$pmo) {
				throw new ForbiddenException('Only the owner or the portfolio office may close the condition');
			}
		} elseif ($status === 'waived') {
			if (!$pmo) {
				throw new ForbiddenException('Only the portfolio office may waive a condition');
			}
		} else {
			throw new ValidationException('Unknown status');
		}
		$evidence = trim($evidence);
		if ($evidence === '') {
			throw new ValidationException($status === 'done' ? 'Please describe the evidence' : 'Waiving needs a reason');
		}
		$now = $this->time->getTime();
		$this->rows->update(self::TABLE, $id, ['status' => $status, 'evidence' => $evidence, 'closedBy' => $userId, 'closedAt' => $now]);
		$this->audit->log('condition', $id, 'status_change', $userId, ['status' => ['open', $status]], $evidence);
		return $this->decorate($userId, (array)$this->rows->find(self::TABLE, $id), $demand);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function decorate(string $userId, array $row, Demand $demand): array {
		$today = date('Y-m-d', $this->time->getTime());
		$pmo = $this->permissions->canTriage($userId, $demand) || $this->permissions->canManageOrg($userId);
		$open = ($row['status'] ?? '') === 'open';
		return $row + [
			'demandKey' => $demand->getProjectKey() ?? $demand->getDemandKey(),
			'demandTitle' => $demand->getTitle(),
			'overdue' => $open && (string)$row['dueDate'] < $today,
			'dueSoon' => $open && (string)$row['dueDate'] >= $today && (string)$row['dueDate'] <= date('Y-m-d', $this->time->getTime() + 7 * 86400),
			'can' => [
				'done' => $open && ($row['ownerId'] === $userId || $pmo),
				'waive' => $open && $pmo,
			],
		];
	}

	/** @throws NotFoundException */
	private function demand(int $id): Demand {
		try {
			return $this->demands->find($id);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			throw new NotFoundException('Demand not found');
		}
	}
}
