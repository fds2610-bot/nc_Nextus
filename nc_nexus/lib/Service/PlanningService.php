<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\PlanningLinkMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCA\Nexus\Planning\IPlanningCredentials;
use OCA\Nexus\Planning\IPlanningFileImport;
use OCA\Nexus\Planning\IPlanningProvider;
use OCA\Nexus\Planning\OpenProjectProvider;
use OCA\Nexus\Planning\PlanningProviderRegistry;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Planungs-Integration (INT-001, Kapitel 07 §4/§5): freigegebenes Vorhaben in einem Planungstool
 * anlegen, mit einem bestehenden Projekt verknüpfen oder einen Projektplan als Datei importieren.
 * Fortschritt, Termine und Meilensteine werden aus den Vorgängen verdichtet. Führend ist nach G3
 * die Planung für Termine und Fortschritt (Kapitel 07 §3). Je Vorhaben gibt es höchstens eine
 * Verknüpfung.
 */
class PlanningService {
	/** Status, in denen ein Vorhaben mit dem Planungstool verbunden werden kann */
	public const LINKABLE = ['released', 'in_execution', 'paused', 'completed'];
	/** angezeigte Meilensteine je Vorhaben */
	private const MAX_MILESTONES = 12;

	public function __construct(
		private PlanningLinkMapper $links,
		private PlanningProviderRegistry $registry,
		private OpenProjectProvider $openProject,
		private DemandService $demands,
		private PermissionService $permissions,
		private AuditService $audit,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Verknüpfung mit aktuellem Stand (live abgeglichene Provider werden beim Abruf neu verdichtet)
	 * und die für das Konto nutzbaren Planungstools.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function get(string $userId, int $demandId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$link = $this->link($demand);
		$canManage = $this->canManage($userId, $demand);
		$providers = [];
		foreach ($this->registry->all() as $provider) {
			if ($this->registry->safeAvailable($provider, $userId)) {
				$providers[] = [
					'id' => $provider->getId(),
					'name' => $provider->getName(),
					'capabilities' => $provider->getCapabilities(),
					'hasCredentials' => $provider instanceof IPlanningCredentials ? $provider->hasCredentials($userId) : null,
				];
			}
		}
		$provider = $link !== null ? $this->registry->get((string)$link['provider']) : null;
		if ($link !== null) {
			if ($provider !== null && $this->has($provider, IPlanningProvider::CAP_SYNC) && $this->registry->safeAvailable($provider, $userId)) {
				$link = $this->sync($userId, $provider, $link);
			}
			$link['providerName'] = $provider?->getName() ?? (string)$link['provider'];
			$link['capabilities'] = $provider?->getCapabilities() ?? [];
		}
		return [
			'available' => $providers !== [],
			'providers' => $providers,
			'canLink' => $providers !== [] && $link === null && $canManage,
			'canUnlink' => $link !== null && $canManage,
			'canImport' => $link !== null && $canManage && $provider !== null && $this->has($provider, IPlanningProvider::CAP_IMPORT),
			'link' => $link,
		];
	}

	/**
	 * Projekte im Planungstool, die das Konto sehen darf (zum Verknüpfen).
	 *
	 * @return list<array<string, mixed>>
	 * @throws ValidationException
	 */
	public function projects(string $userId, string $providerId): array {
		return $this->provider($userId, $providerId, IPlanningProvider::CAP_LINK)->listProjects($userId);
	}

	/**
	 * Neues Projekt anlegen (projectId null) oder bestehendes verknüpfen.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function connect(string $userId, int $demandId, string $providerId, ?string $projectId): array {
		$demand = $this->managed($userId, $demandId);
		if ($this->link($demand) !== null) {
			throw new ValidationException('The project is already connected to the planning tool');
		}
		if ($projectId === null || $projectId === '') {
			$provider = $this->provider($userId, $providerId, IPlanningProvider::CAP_CREATE);
			$project = $provider->createProject($userId, trim(($demand->getProjectKey() ?? $demand->getDemandKey()) . ' ' . $demand->getTitle()), $demand->getDescription());
		} else {
			$provider = $this->provider($userId, $providerId, IPlanningProvider::CAP_LINK);
			$project = null;
			foreach ($provider->listProjects($userId) as $p) {
				if ((string)$p['id'] === $projectId) {
					$project = $p;
				}
			}
			if ($project === null) {
				throw new ValidationException('Planning project not found');
			}
		}
		$now = $this->time->getTime();
		$this->links->insert($demandId, $provider->getId(), (string)$project['id'], mb_substr((string)$project['title'], 0, 255), (string)$project['url'], $userId, $now);
		$this->audit->log(DemandService::ENTITY, $demandId, 'planning_link', $userId, ['planning' => [null, $provider->getId() . ':' . $project['id']]]);
		return $this->get($userId, $demandId);
	}

	/**
	 * Projektplan als Datei importieren (Schnappschuss); ein erneuter Import ersetzt den Stand.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function import(string $userId, int $demandId, string $providerId, string $fileName, string $content): array {
		$demand = $this->managed($userId, $demandId);
		$provider = $this->provider($userId, $providerId, IPlanningProvider::CAP_IMPORT);
		if (!$provider instanceof IPlanningFileImport) {
			throw new ValidationException('This planning tool only supports file import');
		}
		$link = $this->link($demand);
		if ($link !== null && $link['provider'] !== $provider->getId()) {
			throw new ValidationException('The project is already connected to the planning tool');
		}
		$plan = $provider->parseFile($fileName, $content);
		$now = $this->time->getTime();
		$summary = self::summarize($plan['tasks'], date('Y-m-d', $now)) + ['fileName' => mb_substr(basename($fileName), 0, 200)];
		$externalId = sha1($content);
		if ($link === null) {
			$this->links->insert($demandId, $provider->getId(), $externalId, $plan['title'], '', $userId, $now);
			$link = $this->links->find($demandId, $provider->getId());
			$before = null;
		} else {
			$this->links->updateTarget($link['id'], $externalId, $plan['title'], '');
			$before = $provider->getId() . ':' . $link['externalId'];
		}
		if ($link !== null) {
			$this->links->updateSync($link['id'], 'ok', $summary, $now);
		}
		$this->audit->log(DemandService::ENTITY, $demandId, 'planning_import', $userId, ['planning' => [$before, $provider->getId() . ':' . $externalId]]);
		return $this->get($userId, $demandId);
	}

	/**
	 * Verknüpfung lösen (das Projekt im Planungstool bleibt bestehen).
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
		$this->audit->log(DemandService::ENTITY, $demandId, 'planning_link', $userId, ['planning' => [$link['provider'] . ':' . $link['externalId'], null]]);
	}

	/**
	 * Persönliche Zugangsdaten für ein Planungstool hinterlegen oder löschen (null/leer).
	 *
	 * @throws ValidationException
	 */
	public function setCredentials(string $userId, string $providerId, ?string $secret): void {
		$provider = $this->registry->get($providerId);
		if (!$provider instanceof IPlanningCredentials) {
			throw new ValidationException('Unknown planning tool');
		}
		$provider->setCredentials($userId, $secret);
	}

	/**
	 * Einstellungen der Planungstools (Adresse der OpenProject-Instanz).
	 *
	 * @return array<string, mixed>
	 */
	public function config(string $userId): array {
		return [
			'canManage' => $this->permissions->canManageOrg($userId),
			'openprojectUrl' => $this->openProject->baseUrl(),
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException
	 */
	public function saveConfig(string $userId, array $data): array {
		if (!$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may change the governance configuration');
		}
		if (array_key_exists('openprojectUrl', $data)) {
			$before = $this->openProject->baseUrl();
			$this->openProject->setBaseUrl((string)$data['openprojectUrl']);
			if ($before !== $this->openProject->baseUrl()) {
				$this->audit->log('settings', 0, 'update', $userId, ['openprojectUrl' => [$before, $this->openProject->baseUrl()]]);
			}
		}
		return $this->config($userId);
	}

	/**
	 * Stand verdichten: Anzahl, erledigt, Fortschritt (nach Dauer gewichtet), Start, Ende,
	 * überfällig und die Meilensteine. Fehler setzen nur den Sync-Status.
	 *
	 * @param array<string, mixed> $link
	 * @return array<string, mixed>
	 */
	private function sync(string $userId, IPlanningProvider $provider, array $link): array {
		$now = $this->time->getTime();
		try {
			$summary = self::summarize($provider->tasks($userId, (string)$link['externalId']), date('Y-m-d', $now));
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
		$milestones = [];
		foreach ($tasks as $task) {
			$s = isset($task['start_date']) && $task['start_date'] !== '' ? substr((string)$task['start_date'], 0, 10) : null;
			$e = isset($task['end_date']) && $task['end_date'] !== '' ? substr((string)$task['end_date'], 0, 10) : null;
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
			if (!empty($task['milestone'])) {
				$milestones[] = ['title' => (string)($task['title'] ?? ''), 'date' => $e ?? $s, 'done' => $status === 'done'];
			}
		}
		usort($milestones, static fn (array $a, array $b) => strcmp((string)$a['date'], (string)$b['date']));
		return [
			'tasks' => $count,
			'done' => $done,
			'progress' => $weights > 0 ? (int)round($weighted / $weights) : 0,
			'start' => $start,
			'end' => $end,
			'overdue' => $overdue,
			'milestones' => array_slice($milestones, 0, self::MAX_MILESTONES),
		];
	}

	/** @return array<string, mixed>|null */
	private function link(Demand $demand): ?array {
		$link = $this->links->findByDemand($demand->getId());
		return $link !== null && $link['createdAt'] >= $demand->getCreatedAt() ? $link : null;
	}

	/**
	 * @throws NotFoundException|ForbiddenException
	 */
	private function managed(string $userId, int $demandId): Demand {
		$demand = $this->demands->findReadable($userId, $demandId);
		if (!$this->canManage($userId, $demand)) {
			throw new ForbiddenException('Only released projects can be connected, by the requestor or the portfolio office');
		}
		return $demand;
	}

	/**
	 * @throws ValidationException
	 */
	private function provider(string $userId, string $id, string $capability): IPlanningProvider {
		$provider = $this->registry->get($id);
		if ($provider === null || !$this->has($provider, $capability)) {
			throw new ValidationException('Unknown planning tool');
		}
		if (!$this->registry->safeAvailable($provider, $userId)) {
			throw new ValidationException('The planning tool is not available');
		}
		return $provider;
	}

	private function has(IPlanningProvider $provider, string $capability): bool {
		return in_array($capability, $provider->getCapabilities(), true);
	}

	private function canManage(string $userId, Demand $demand): bool {
		return in_array($demand->getStatus(), self::LINKABLE, true)
			&& ($demand->getRequestorId() === $userId || $this->permissions->canTriage($userId, $demand) || $this->permissions->isAdmin($userId));
	}
}
