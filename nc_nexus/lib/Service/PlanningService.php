<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\PlanningLinkMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Planungs-Integration v1 (INT-001, Kapitel 07 §5.1): freigegebenes Vorhaben in nc_gantt
 * anlegen oder mit einem bestehenden Projekt verknüpfen; Fortschritt und Termine werden aus
 * den Aufgaben verdichtet (Projekt-Events und Meilensteine liefert erst IGanttApi v2).
 * Führend ist nach G3 die Planung für Termine und Fortschritt (Kapitel 07 §3).
 */
class PlanningService {
	/** Status, in denen ein Vorhaben mit dem Planungstool verbunden werden kann */
	public const LINKABLE = ['released', 'in_execution', 'paused', 'completed'];

	public function __construct(
		private PlanningLinkMapper $links,
		private GanttGateway $gantt,
		private DemandService $demands,
		private PermissionService $permissions,
		private AuditService $audit,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Verknüpfung mit aktuellem Stand (beim Abruf neu verdichtet).
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function get(string $userId, int $demandId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$link = $this->link($demand);
		$available = $this->gantt->isAvailable($userId);
		if ($link !== null && $available) {
			$link = $this->sync($userId, $link);
		}
		return [
			'available' => $available,
			'canLink' => $available && $link === null && $this->canManage($userId, $demand),
			'canUnlink' => $link !== null && $this->canManage($userId, $demand),
			'link' => $link,
		];
	}

	/**
	 * Projekte im Planungstool, die das Konto sehen darf (zum Verknüpfen).
	 *
	 * @return list<array<string, mixed>>
	 * @throws ValidationException
	 */
	public function projects(string $userId): array {
		return $this->gantt->listProjects($userId);
	}

	/**
	 * Neues Gantt-Projekt anlegen (projectId null) oder bestehendes verknüpfen.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function connect(string $userId, int $demandId, ?int $projectId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		if (!$this->canManage($userId, $demand)) {
			throw new ForbiddenException('Only released projects can be connected, by the requestor or the portfolio office');
		}
		if ($this->link($demand) !== null) {
			throw new ValidationException('The project is already connected to the planning tool');
		}
		if ($projectId === null) {
			$project = $this->gantt->createProject($userId, trim(($demand->getProjectKey() ?? $demand->getDemandKey()) . ' ' . $demand->getTitle()), $demand->getDescription());
		} else {
			$project = null;
			foreach ($this->gantt->listProjects($userId) as $p) {
				if ($p['id'] === $projectId) {
					$project = $p;
				}
			}
			if ($project === null) {
				throw new ValidationException('Planning project not found');
			}
		}
		$now = $this->time->getTime();
		$this->links->insert($demandId, GanttGateway::PROVIDER, (string)$project['id'], $project['title'], $project['url'], $userId, $now);
		$this->audit->log(DemandService::ENTITY, $demandId, 'planning_link', $userId, ['planning' => [null, 'gantt:' . $project['id']]]);
		return $this->get($userId, $demandId);
	}

	/**
	 * Verknüpfung lösen (das Gantt-Projekt bleibt bestehen).
	 *
	 * @throws NotFoundException|ForbiddenException
	 */
	public function disconnect(string $userId, int $demandId): void {
		$demand = $this->demands->findReadable($userId, $demandId);
		$link = $this->link($demand);
		if ($link === null) {
			return;
		}
		if (!$this->canManage($userId, $demand)) {
			throw new ForbiddenException('Only released projects can be connected, by the requestor or the portfolio office');
		}
		$this->links->delete($link['id']);
		$this->audit->log(DemandService::ENTITY, $demandId, 'planning_link', $userId, ['planning' => ['gantt:' . $link['externalId'], null]]);
	}

	/**
	 * Stand aus den Aufgaben verdichten: Anzahl, erledigt, Fortschritt (nach Dauer gewichtet),
	 * Start, Ende, überfällig. Fehler setzen nur den Sync-Status.
	 *
	 * @param array<string, mixed> $link
	 * @return array<string, mixed>
	 */
	private function sync(string $userId, array $link): array {
		$now = $this->time->getTime();
		try {
			$summary = self::summarize($this->gantt->tasks($userId, (int)$link['externalId']), date('Y-m-d', $now));
			$this->links->updateSync($link['id'], 'ok', $summary, $now);
			return ['summary' => $summary, 'syncStatus' => 'ok', 'syncedAt' => $now] + $link;
		} catch (ValidationException $e) {
			$this->links->updateSync($link['id'], 'error', null, $now);
			return ['syncStatus' => 'error', 'syncError' => $e->getMessage()] + $link;
		}
	}

	/**
	 * @param list<array<string, mixed>> $tasks
	 * @return array<string, mixed>
	 */
	public static function summarize(array $tasks, string $today): array {
		$count = count($tasks);
		$done = 0;
		$overdue = 0;
		$weighted = 0.0;
		$weights = 0.0;
		$start = null;
		$end = null;
		foreach ($tasks as $task) {
			$s = isset($task['start_date']) ? substr((string)$task['start_date'], 0, 10) : null;
			$e = isset($task['end_date']) ? substr((string)$task['end_date'], 0, 10) : null;
			$status = (string)($task['status'] ?? '');
			$progress = (float)($task['progress'] ?? ($status === 'done' ? 100 : 0));
			if ($status === 'done') {
				$done++;
				$progress = 100.0;
			}
			$days = ($s && $e) ? max(1, (int)((strtotime($e) - strtotime($s)) / 86400) + 1) : 1;
			$weighted += $progress * $days;
			$weights += $days;
			if ($s !== null && ($start === null || $s < $start)) {
				$start = $s;
			}
			if ($e !== null && ($end === null || $e > $end)) {
				$end = $e;
			}
			if ($e !== null && $e < $today && $status !== 'done') {
				$overdue++;
			}
		}
		return [
			'tasks' => $count,
			'done' => $done,
			'progress' => $weights > 0 ? (int)round($weighted / $weights) : 0,
			'start' => $start,
			'end' => $end,
			'overdue' => $overdue,
		];
	}

	/** @return array<string, mixed>|null */
	private function link(Demand $demand): ?array {
		$link = $this->links->find($demand->getId(), GanttGateway::PROVIDER);
		return $link !== null && $link['createdAt'] >= $demand->getCreatedAt() ? $link : null;
	}

	private function canManage(string $userId, Demand $demand): bool {
		return in_array($demand->getStatus(), self::LINKABLE, true)
			&& ($demand->getRequestorId() === $userId || $this->permissions->canTriage($userId, $demand) || $this->permissions->isAdmin($userId));
	}
}
