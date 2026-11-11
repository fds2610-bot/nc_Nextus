<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Exception\ValidationException;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Einziger Berührungspunkt mit nc_gantt (Planungs-Provider „gantt“, Kapitel 07 §5.1), über dessen
 * öffentliche PHP-Schnittstelle `OCA\NcGantt\PublicApi\IGanttApi` ab Version 1. Rechte prüft
 * nc_gantt im Namen des Kontos. Ohne nc_gantt ist isAvailable() false; Fehler blockieren nie die
 * Governance-Funktionen (Kapitel 07 §4.2).
 */
class GanttGateway {
	public const PROVIDER = 'gantt';
	private const APP_ID = 'nc_gantt';
	private const API = 'OCA\\NcGantt\\PublicApi\\IGanttApi';
	private const API_EXCEPTION = 'OCA\\NcGantt\\PublicApi\\GanttApiException';
	private const MIN_API_VERSION = 1;

	public function __construct(
		private IAppManager $appManager,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	public function isAvailable(string $userId): bool {
		try {
			$user = $this->userManager->get($userId);
			return $user !== null && $this->appManager->isEnabledForUser(self::APP_ID, $user) && $this->api() !== null;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @return list<array{id: int, title: string, url: string}>
	 * @throws ValidationException
	 */
	public function listProjects(string $userId): array {
		return $this->call($userId, fn ($api) => array_map(static fn (array $p) => [
			'id' => (int)$p['id'], 'title' => (string)$p['title'], 'url' => (string)$p['url'],
		], $api->listProjects($userId)));
	}

	/**
	 * @return array{id: int, title: string, url: string}
	 * @throws ValidationException
	 */
	public function createProject(string $userId, string $title, ?string $description): array {
		return $this->call($userId, function ($api) use ($userId, $title, $description) {
			$p = $api->createProject($userId, $title, $description, '#0082c9');
			return ['id' => (int)$p['id'], 'title' => (string)$p['title'], 'url' => (string)$p['url']];
		});
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws ValidationException
	 */
	public function tasks(string $userId, int $projectId): array {
		return $this->call($userId, fn ($api): array => array_values((array)$api->getTasks($userId, $projectId)));
	}

	public function url(int $projectId): string {
		try {
			return (string)$this->api()?->getUrl($projectId);
		} catch (\Throwable) {
			return '';
		}
	}

	private function api(): ?object {
		if (!interface_exists(self::API)) {
			return null;
		}
		$api = Server::get(self::API);
		if ($api->getApiVersion() < self::MIN_API_VERSION) {
			return null;
		}
		return method_exists($api, 'forApp') ? $api->forApp(Application::APP_ID) : $api;
	}

	/**
	 * @template T
	 * @param callable(object): T $fn
	 * @return T
	 * @throws ValidationException
	 */
	private function call(string $userId, callable $fn): mixed {
		if (!$this->isAvailable($userId)) {
			throw new ValidationException('nc_gantt is not available');
		}
		try {
			return $fn($this->api());
		} catch (\Throwable $e) {
			// Fehlercode der GanttApiException; Klasse nur zur Laufzeit vorhanden, daher dynamisch
			$code = is_a($e, self::API_EXCEPTION) && is_callable([$e, 'getErrorCode']) ? (string)call_user_func([$e, 'getErrorCode']) : 'error';
			$this->logger->info('nc_nexus: nc_gantt call failed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			throw new ValidationException('The planning tool reported an error (%1$s)', [preg_match('/^[a-z_]{3,40}$/', $code) ? $code : 'error']);
		}
	}
}
