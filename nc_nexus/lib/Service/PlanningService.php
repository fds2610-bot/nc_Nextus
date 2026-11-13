<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\PlanningLinkMapper;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCA\Nexus\Planning\IPlanningCredentials;
use OCA\Nexus\Planning\IPlanningFileImport;
use OCA\Nexus\Planning\GanttProvider;
use OCA\Nexus\Planning\IPlanningProvider;
use OCA\Nexus\Planning\MsProjectProvider;
use OCA\Nexus\Planning\PlanDemand;
use OCA\Nexus\Planning\PlanningConfig;
use OCA\Nexus\Planning\PlanningProviderRegistry;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

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
	private const MAP = 'nx_role_pool_map';

	public function __construct(
		private PlanningLinkMapper $links,
		private PlanningProviderRegistry $registry,
		private PlanningConfig $config,
		private GanttProvider $gantt,
		private MsProjectProvider $msProject,
		private ProjectService $projects,
		private ResourceNeedMapper $needs,
		private PoolMapper $pools,
		private CapacityService $capacity,
		private Rows $rows,
		private DemandService $demands,
		private PermissionService $permissions,
		private AuditService $audit,
		private ITimeFactory $time,
		private DemandMapper $demandMapper,
		private IL10N $l10n,
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
			$link['syncError'] = $this->errorText($link['syncError'] ?? null);
			$link['providerName'] = $provider?->getName() ?? (string)$link['provider'];
			$link['capabilities'] = $provider?->getCapabilities() ?? [];
			$link['demand'] = $this->planDemand($link, $demand);
			$link['canApplyDemand'] = $link['demand'] !== null && $link['demand']['pools'] !== [] && $this->demands->canChange($userId, $demand);
			unset($link['summary']['demand']);
		}
		return [
			'available' => $providers !== [],
			'providers' => $providers,
			'canLink' => $providers !== [] && $link === null && $canManage,
			'canUnlink' => $link !== null && $canManage,
			'canImport' => $link !== null && $canManage && $provider !== null && $this->has($provider, IPlanningProvider::CAP_IMPORT) && $this->registry->safeAvailable($provider, $userId),
			'canExport' => $this->msProject->canExport() && in_array($demand->getStatus(), self::LINKABLE, true),
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
	 * Startplan für MS Project (MSPDI): Projektrahmen und Governance-Meilensteine.
	 *
	 * @return array{fileName: string, content: string}
	 * @throws NotFoundException|ValidationException
	 */
	public function export(string $userId, int $demandId, string $frameName): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		if (!$this->msProject->canExport() || !in_array($demand->getStatus(), self::LINKABLE, true)) {
			throw new ValidationException('The export is not available');
		}
		$row = $this->projects->projectRow($demand);
		$start = $row['plannedStart'] ?? null;
		$end = $row['plannedEnd'] ?? null;
		if ($start === null || $end === null) {
			// ohne Plantermine: Monate des Ressourcenbedarfs, sonst ab heute drei Monate
			$periods = [];
			foreach ($this->needs->findForDemand($demand->getId()) as $need) {
				if ($need->getUpdatedAt() >= $demand->getCreatedAt()) {
					$periods[] = $need->getPeriod();
				}
			}
			sort($periods);
			$today = date('Y-m-d', $this->time->getTime());
			$start ??= $periods !== [] ? $periods[0] . '-01' : $today;
			$end ??= $periods !== [] ? date('Y-m-t', (int)strtotime(end($periods) . '-01')) : date('Y-m-d', (int)strtotime($today . ' +3 months'));
		}
		if ($end < $start) {
			$end = $start;
		}
		$milestones = array_map(static fn (array $m) => ['name' => (string)$m['name'], 'date' => (string)$m['dueDate'], 'external' => (int)$m['external'] === 1], $this->projects->milestones($demand));
		$title = trim(($demand->getProjectKey() ?? $demand->getDemandKey()) . ' ' . $demand->getTitle());
		$this->audit->log(DemandService::ENTITY, $demandId, 'planning_export', $userId, null);
		return [
			'fileName' => (string)preg_replace('/[^A-Za-z0-9_-]+/', '-', $demand->getProjectKey() ?? $demand->getDemandKey()) . '.xml',
			'content' => MsProjectProvider::write($title, (string)$start, (string)$end, $frameName, $milestones),
		];
	}

	/**
	 * Planbedarf über das Rollen-Mapping in Pools übersetzt, verglichen mit dem Ressourcenbedarf
	 * des Vorhabens; null, wenn der Plan keinen Aufwand enthält.
	 *
	 * @param array<string, mixed> $link
	 * @return array<string, mixed>|null
	 */
	private function planDemand(array $link, Demand $demand): ?array {
		$rows = $link['summary']['demand'] ?? [];
		if (!is_array($rows) || $rows === []) {
			return null;
		}
		$mapped = PlanDemand::map($rows, $this->mapping((string)$link['provider']));
		$current = [];
		foreach ($this->needs->findForDemand($demand->getId()) as $need) {
			if ($need->getUpdatedAt() >= $demand->getCreatedAt()) {
				$current[$need->getPoolId()] = ($current[$need->getPoolId()] ?? 0) + $need->getPersonDays();
			}
		}
		$names = $this->poolNames();
		$pools = [];
		foreach ($mapped['pools'] as $poolId => $periods) {
			$pools[] = [
				'poolId' => $poolId,
				'pool' => $names[$poolId] ?? '#' . $poolId,
				'periods' => $periods,
				'total' => round(array_sum($periods), 1),
				'current' => round($current[$poolId] ?? 0, 1),
			];
		}
		usort($pools, static fn (array $a, array $b) => strcmp($a['pool'], $b['pool']));
		$unmapped = [];
		foreach ($mapped['unmapped'] as $role => $days) {
			$unmapped[] = ['role' => (string)$role, 'total' => $days];
		}
		return [
			'periods' => $mapped['periods'],
			'pools' => $pools,
			'unmapped' => $unmapped,
			'total' => round(array_sum(array_column($pools, 'total')) + array_sum(array_column($unmapped, 'total')), 1),
		];
	}

	/**
	 * Planbedarf (nur zugeordnete Rollen) als Ressourcenbedarf des Vorhabens übernehmen; ersetzt
	 * den bisherigen Bedarf.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function applyDemand(string $userId, int $demandId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$link = $this->link($demand);
		$plan = $link !== null ? $this->planDemand($link, $demand) : null;
		if ($plan === null || $plan['pools'] === []) {
			throw new ValidationException('The plan contains no effort for roles assigned to a pool');
		}
		$rows = [];
		foreach ($plan['pools'] as $pool) {
			foreach ($pool['periods'] as $period => $days) {
				$rows[] = ['poolId' => $pool['poolId'], 'period' => $period, 'personDays' => $days];
			}
		}
		$this->capacity->replaceNeeds($userId, $demandId, $rows, 'plan');
		$this->audit->log(DemandService::ENTITY, $demandId, 'planning_demand', $userId, null);
		return $this->get($userId, $demandId);
	}

	/**
	 * Rollen aus allen verknüpften Plänen und ihre Pool-Zuordnung (Bereich „Planungstools“).
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException
	 */
	public function roles(string $userId): array {
		$this->requireManage($userId);
		$found = [];
		foreach ($this->links->all() as $link) {
			foreach ($link['summary']['demand'] ?? [] as $d) {
				$key = $link['provider'] . "\n" . $d['role'];
				$found[$key]['projects'][$link['demandId']] = true;
				$found[$key]['days'] = ($found[$key]['days'] ?? 0) + (float)$d['days'];
			}
		}
		$mapped = [];
		foreach ($this->rows->where(self::MAP) as $m) {
			$mapped[$m['provider'] . "\n" . $m['externalRoleRef']] = (int)$m['poolId'];
		}
		$result = [];
		foreach (array_unique([...array_keys($found), ...array_keys($mapped)]) as $key) {
			[$providerId, $role] = explode("\n", (string)$key, 2);
			$provider = $this->registry->get($providerId);
			$result[] = [
				'provider' => $providerId,
				'providerName' => $provider?->getName() ?? $providerId,
				'role' => $role,
				'poolId' => $mapped[$key] ?? null,
				'projects' => count($found[$key]['projects'] ?? []),
				'days' => round($found[$key]['days'] ?? 0, 1),
			];
		}
		usort($result, static fn (array $a, array $b) => [$a['providerName'], $a['role']] <=> [$b['providerName'], $b['role']]);
		$pools = [];
		foreach ($this->poolNames() as $id => $name) {
			$pools[] = ['id' => $id, 'name' => $name];
		}
		return ['roles' => $result, 'pools' => $pools];
	}

	/**
	 * Rolle einem Pool zuordnen; poolId null löscht die Zuordnung.
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException
	 */
	public function saveRole(string $userId, string $providerId, string $role, ?int $poolId): array {
		$this->requireManage($userId);
		$role = PlanDemand::role($role);
		if ($providerId === '' || strlen($providerId) > 32) {
			throw new ValidationException('Unknown planning tool');
		}
		if ($poolId !== null && !isset($this->poolNames()[$poolId])) {
			throw new ValidationException('Pool not found');
		}
		$existing = $this->rows->where(self::MAP, ['provider' => $providerId, 'externalRoleRef' => $role])[0] ?? null;
		$before = $existing !== null ? (int)$existing['poolId'] : null;
		if ($before !== $poolId) {
			if ($existing !== null) {
				$this->rows->delete(self::MAP, (int)$existing['id']);
			}
			if ($poolId !== null) {
				$this->rows->insert(self::MAP, ['provider' => $providerId, 'externalRoleRef' => $role, 'poolId' => $poolId, 'createdBy' => $userId, 'createdAt' => $this->time->getTime()]);
			}
			$this->audit->log('settings', 0, 'update', $userId, ['rolePool' => [$before === null ? null : $providerId . ':' . $role . ' → ' . $before, $poolId === null ? null : $providerId . ':' . $role . ' → ' . $poolId]]);
		}
		return $this->roles($userId);
	}

	/** @return array<string, int> Rolle => Pool-ID (gelöschte Pools zählen als nicht zugeordnet) */
	private function mapping(string $providerId): array {
		$pools = $this->poolNames();
		$result = [];
		foreach ($this->rows->where(self::MAP, ['provider' => $providerId]) as $m) {
			if (isset($pools[(int)$m['poolId']])) {
				$result[(string)$m['externalRoleRef']] = (int)$m['poolId'];
			}
		}
		return $result;
	}

	/** @return array<int, string> aktive Pools, Name mit Kürzel */
	private function poolNames(): array {
		$names = [];
		foreach ($this->pools->findAll() as $pool) {
			$names[$pool->getId()] = $pool->getName() . ' (' . $pool->getCode() . ')';
		}
		return $names;
	}

	/**
	 * Einstellungen der Planungstools für den Bereich „Planungstools“.
	 *
	 * @return array<string, mixed>
	 */
	public function config(string $userId): array {
		$canManage = $this->permissions->canManageOrg($userId);
		return [
			'canManage' => $canManage,
			'ganttLocal' => $this->gantt->isLocalAvailable($userId),
			'ganttUrl' => $this->config->ganttUrl(),
			'ganttUser' => $canManage ? $this->config->ganttUser() : '',
			'ganttHasPassword' => $this->config->ganttPassword() !== '',
			'openprojectUrl' => $this->config->openprojectUrl(),
			'mspdiImport' => $this->config->mspdiImport(),
			'mspdiExport' => $this->config->mspdiExport(),
			'syncHours' => $this->config->syncHours(),
			'syncChoices' => PlanningConfig::SYNC_CHOICES,
			'lastSync' => $this->config->lastSync(),
		];
	}

	/**
	 * @param array<string, mixed> $data ganttUrl, ganttUser, ganttPassword (leer = behalten), openprojectUrl, mspdiImport, mspdiExport
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException
	 */
	public function saveConfig(string $userId, array $data): array {
		$this->requireManage($userId);
		$before = $this->config($userId);
		if (array_key_exists('ganttUrl', $data)) {
			$password = isset($data['ganttPassword']) && is_string($data['ganttPassword']) && trim($data['ganttPassword']) !== '' ? $data['ganttPassword'] : null;
			if (trim((string)$data['ganttUrl']) !== '' && $password === null && $this->config->ganttPassword() === '') {
				throw new ValidationException('Enter the app password of the account at the Gantt instance');
			}
			$this->config->setGantt((string)$data['ganttUrl'], (string)($data['ganttUser'] ?? ''), $password);
		}
		if (array_key_exists('openprojectUrl', $data)) {
			$this->config->setOpenprojectUrl((string)$data['openprojectUrl']);
		}
		if (array_key_exists('syncHours', $data)) {
			$this->config->setSyncHours((int)$data['syncHours']);
		}
		if (array_key_exists('mspdiImport', $data) || array_key_exists('mspdiExport', $data)) {
			$this->config->setMspdi((bool)($data['mspdiImport'] ?? $before['mspdiImport']), (bool)($data['mspdiExport'] ?? $before['mspdiExport']));
		}
		$after = $this->config($userId);
		$changes = [];
		foreach (['ganttUrl', 'ganttUser', 'openprojectUrl', 'mspdiImport', 'mspdiExport', 'syncHours'] as $key) {
			if ($before[$key] !== $after[$key]) {
				$changes[$key] = [$before[$key], $after[$key]];
			}
		}
		if (isset($data['ganttPassword']) && is_string($data['ganttPassword']) && trim($data['ganttPassword']) !== '') {
			$changes['ganttPassword'] = ['***', '***'];
		}
		if ($changes !== []) {
			$this->audit->log('settings', 0, 'update', $userId, $changes);
		}
		return $after;
	}

	/**
	 * Verbindung zur eingetragenen Gantt-Instanz prüfen.
	 *
	 * @return array{projects: int}
	 * @throws ForbiddenException|ValidationException
	 */
	public function testGantt(string $userId): array {
		$this->requireManage($userId);
		if (!$this->gantt->isAvailable($userId)) {
			throw new ValidationException('The planning tool is not available');
		}
		return ['projects' => count($this->gantt->listProjects($userId))];
	}

	/**
	 * @throws ForbiddenException
	 */
	private function requireManage(string $userId): void {
		if (!$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may change the governance configuration');
		}
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
			return ['summary' => $summary, 'syncStatus' => 'ok', 'syncError' => null, 'syncedAt' => $now] + $link;
		} catch (ValidationException $e) {
			$error = ['key' => $e->key, 'params' => $e->params];
			$this->links->updateSync($link['id'], 'error', null, $now, $error);
			return ['syncStatus' => 'error', 'syncError' => $error, 'syncedAt' => $now] + $link;
		}
	}

	/**
	 * Hintergrundabgleich (Kapitel 07 §4.2): alle live abgeglichenen Verknüpfungen, deren letzter
	 * Abgleich älter als der eingestellte Abstand ist, im Namen des Kontos abgleichen, das die
	 * Verknüpfung angelegt hat (dessen Rechte und Zugangsdaten im Planungstool gelten).
	 * Fehler setzen nur den Sync-Status und blockieren nichts.
	 *
	 * @param bool $force alle fälligen und nicht fälligen abgleichen („Jetzt abgleichen“)
	 * @return array{at: int, ok: int, failed: int}
	 */
	public function syncAll(bool $force = false): array {
		$now = $this->time->getTime();
		$hours = $this->config->syncHours();
		$run = ['at' => $now, 'ok' => 0, 'failed' => 0];
		if ($hours === 0 && !$force) {
			return $run;
		}
		$demands = [];
		foreach ($this->demandMapper->findAll() as $d) {
			$demands[$d->getId()] = $d;
		}
		// 5 Minuten Spielraum, damit ein stündlicher Job nicht um Sekunden zu früh kommt
		$due = $now - $hours * 3600 + 300;
		foreach ($this->links->all() as $link) {
			$demand = $demands[$link['demandId']] ?? null;
			$provider = $this->registry->get((string)$link['provider']);
			if ($demand === null || $provider === null || !$this->has($provider, IPlanningProvider::CAP_SYNC)
				|| !in_array($demand->getStatus(), self::LINKABLE, true) || $link['createdAt'] < $demand->getCreatedAt()) {
				continue;
			}
			if (!$force && $link['syncedAt'] !== null && $link['syncedAt'] > $due) {
				continue;
			}
			$account = (string)$link['createdBy'];
			if (!$this->registry->safeAvailable($provider, $account)) {
				$this->links->updateSync($link['id'], 'error', null, $now, ['key' => 'The planning tool is not available for the account %1$s that connected the project', 'params' => [$account]]);
				$run['failed']++;
				continue;
			}
			$result = $this->sync($account, $provider, $link);
			if ($result['syncStatus'] === 'ok') {
				$run['ok']++;
			} else {
				$run['failed']++;
			}
		}
		$this->config->setLastSync($run);
		return $run;
	}

	/**
	 * „Jetzt abgleichen“ im Bereich Planungstools (nur PMO).
	 *
	 * @return array<string, mixed> Einstellungen mit dem Ergebnis des Laufs
	 * @throws ForbiddenException
	 */
	public function syncNow(string $userId): array {
		$this->requireManage($userId);
		$this->syncAll(true);
		return $this->config($userId);
	}

	/**
	 * Verknüpfungen mit fehlgeschlagenem Abgleich (Datenqualitäts-Cockpit).
	 *
	 * @return array<int, string> Vorhaben-ID => übersetzte Meldung
	 */
	public function syncErrors(): array {
		$errors = [];
		foreach ($this->links->all() as $link) {
			if ($link['syncStatus'] === 'error') {
				$errors[$link['demandId']] = $this->errorText($link['syncError']) ?? '';
			}
		}
		return $errors;
	}

	/** @param array{key: string, params: list<string>}|string|null $error */
	private function errorText(array|string|null $error): ?string {
		if (is_array($error)) {
			return $this->l10n->t($error['key'], $error['params']);
		}
		return $error;
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
			'demand' => PlanDemand::aggregate($tasks),
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
