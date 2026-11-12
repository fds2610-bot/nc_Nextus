<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

use OCA\Nexus\Exception\ValidationException;
use OCA\Nexus\Service\GanttGateway;

/**
 * nc_gantt (Kapitel 07 §5.1): auf dieser Instanz über IGanttApi v1; ist im Bereich
 * „Planungstools“ eine andere Nextcloud eingetragen, über deren OCS-Schnittstelle mit einem
 * technischen Konto und App-Passwort. Dann sehen alle Nexus-Konten die Gantt-Projekte dieses
 * Kontos; die Rechte vergibt man dort.
 */
class GanttProvider implements IPlanningProvider {
	private const OCS = '/ocs/v2.php/apps/nc_gantt/api/v1';

	public function __construct(
		private GanttGateway $gateway,
		private PlanningConfig $config,
		private OcsClient $client,
	) {
	}

	public function getId(): string {
		return GanttGateway::PROVIDER;
	}

	public function getName(): string {
		return 'Gantt';
	}

	public function getCapabilities(): array {
		return [self::CAP_CREATE, self::CAP_LINK, self::CAP_SYNC];
	}

	public function isRemote(): bool {
		return $this->config->ganttUrl() !== '';
	}

	/** nc_gantt ist auf dieser Instanz installiert und für das Konto nutzbar */
	public function isLocalAvailable(string $userId): bool {
		return $this->gateway->isAvailable($userId);
	}

	public function isAvailable(string $userId): bool {
		if ($this->isRemote()) {
			return $this->config->ganttUser() !== '' && $this->config->ganttPassword() !== '';
		}
		return $this->gateway->isAvailable($userId);
	}

	public function listProjects(string $userId): array {
		if ($this->isRemote()) {
			$data = $this->remote('GET', '/projects');
			return array_values(array_map(fn (array $p) => $this->project($p), array_filter($data, 'is_array')));
		}
		return array_map(static fn (array $p) => ['id' => (string)$p['id'], 'title' => $p['title'], 'url' => $p['url']], $this->gateway->listProjects($userId));
	}

	public function createProject(string $userId, string $title, ?string $description): array {
		if ($this->isRemote()) {
			return $this->project($this->remote('POST', '/projects', ['title' => $title, 'description' => (string)$description, 'color' => '#0082c9']));
		}
		$p = $this->gateway->createProject($userId, $title, $description);
		return ['id' => (string)$p['id'], 'title' => $p['title'], 'url' => $p['url']];
	}

	public function tasks(string $userId, string $externalId): array {
		if (!ctype_digit($externalId)) {
			throw new ValidationException('Planning project not found');
		}
		$tasks = $this->isRemote() ? array_filter($this->remote('GET', '/projects/' . $externalId . '/tasks'), 'is_array') : $this->gateway->tasks($userId, (int)$externalId);
		return self::normalize(array_values($tasks));
	}

	/**
	 * @param list<array<string, mixed>> $tasks Aufgaben aus IGanttApi oder der OCS-Schnittstelle
	 * @return list<array{title: string, start_date: ?string, end_date: ?string, status: string, progress: float}>
	 */
	public static function normalize(array $tasks): array {
		$day = static fn ($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : null;
		return array_map(static fn (array $t) => [
			'title' => (string)($t['title'] ?? ''),
			'start_date' => $day($t['start_date'] ?? null),
			'end_date' => $day($t['end_date'] ?? null),
			'status' => (string)($t['status'] ?? ''),
			'progress' => (float)($t['progress'] ?? $t['manual_progress'] ?? 0),
		], $tasks);
	}

	/**
	 * @param array<string, mixed> $p
	 * @return array{id: string, title: string, url: string}
	 */
	private function project(array $p): array {
		$id = (string)($p['id'] ?? '');
		return ['id' => $id, 'title' => (string)($p['title'] ?? ''), 'url' => $this->config->ganttUrl() . '/index.php/apps/nc_gantt/?project=' . rawurlencode($id)];
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<array-key, mixed> ocs.data
	 * @throws ValidationException
	 */
	private function remote(string $method, string $path, ?array $body = null): array {
		if (!$this->isAvailable('')) {
			throw new ValidationException('The planning tool is not available');
		}
		$data = $this->client->request($method, $this->config->ganttUrl() . self::OCS . $path, [$this->config->ganttUser(), $this->config->ganttPassword()], ['OCS-APIRequest' => 'true'], ['format' => 'json'], $body);
		$payload = $data['ocs']['data'] ?? null;
		return is_array($payload) ? $payload : [];
	}
}
