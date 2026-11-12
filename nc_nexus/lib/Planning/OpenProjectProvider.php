<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Exception\ValidationException;
use OCP\Security\ICredentialsManager;

/**
 * OpenProject über die REST-API v3 (Kapitel 07 §4.2, externer Provider). Die Adresse der Instanz
 * legt das PMO im Bereich „Planungstools“ fest, jedes Konto hinterlegt seinen eigenen
 * API-Schlüssel im Credential-Store – OpenProject prüft damit die Rechte des Kontos.
 */
class OpenProjectProvider implements IPlanningProvider, IPlanningCredentials {
	public const ID = 'openproject';
	private const CREDENTIALS = Application::APP_ID . '::openproject';
	private const PAGE_SIZE = 500;

	public function __construct(
		private PlanningConfig $config,
		private ICredentialsManager $credentials,
		private OcsClient $client,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getName(): string {
		return 'OpenProject';
	}

	public function getCapabilities(): array {
		return [self::CAP_CREATE, self::CAP_LINK, self::CAP_SYNC, self::CAP_CREDENTIALS];
	}

	public function isAvailable(string $userId): bool {
		return $this->baseUrl() !== '';
	}

	public function baseUrl(): string {
		return $this->config->openprojectUrl();
	}

	public function hasCredentials(string $userId): bool {
		return $this->token($userId) !== '';
	}

	public function setCredentials(string $userId, ?string $secret): void {
		$secret = trim((string)$secret);
		if ($secret === '') {
			$this->credentials->delete($userId, self::CREDENTIALS);
			return;
		}
		$this->credentials->store($userId, self::CREDENTIALS, $secret);
	}

	public function listProjects(string $userId): array {
		$data = $this->request($userId, 'GET', '/api/v3/projects', ['pageSize' => self::PAGE_SIZE]);
		return array_map(fn (array $p) => $this->project($p), self::elements($data));
	}

	public function createProject(string $userId, string $title, ?string $description): array {
		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
		$body = [
			'name' => mb_substr($title, 0, 255),
			'identifier' => substr($slug === '' ? 'nexus' : $slug, 0, 80) . '-' . bin2hex(random_bytes(2)),
			'description' => ['raw' => (string)$description],
		];
		return $this->project($this->request($userId, 'POST', '/api/v3/projects', [], $body));
	}

	public function tasks(string $userId, string $externalId): array {
		if (!ctype_digit($externalId)) {
			throw new ValidationException('Planning project not found');
		}
		$path = '/api/v3/projects/' . $externalId . '/work_packages';
		// ohne Filter liefert OpenProject nur offene Arbeitspakete; erledigt = geschlossener Status
		$all = $this->request($userId, 'GET', $path, ['pageSize' => self::PAGE_SIZE, 'filters' => '[]']);
		$closed = $this->request($userId, 'GET', $path, ['pageSize' => self::PAGE_SIZE, 'filters' => '[{"status":{"operator":"c","values":[]}}]']);
		return self::normalize(self::elements($all), array_map(static fn (array $w) => (int)($w['id'] ?? 0), self::elements($closed)));
	}

	/**
	 * Arbeitspakete in die einheitliche Form bringen. Meilensteine haben in OpenProject nur „date“.
	 *
	 * @param list<array<string, mixed>> $workPackages
	 * @param list<int> $closedIds
	 * @return list<array{title: string, start_date: ?string, end_date: ?string, status: string, progress: float, milestone: bool}>
	 */
	public static function normalize(array $workPackages, array $closedIds): array {
		$day = static fn ($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : null;
		$tasks = [];
		foreach ($workPackages as $w) {
			$milestone = array_key_exists('date', $w) && !array_key_exists('startDate', $w);
			$start = $milestone ? $day($w['date'] ?? null) : $day($w['startDate'] ?? null);
			$end = $milestone ? $start : $day($w['dueDate'] ?? null);
			$closed = in_array((int)($w['id'] ?? 0), $closedIds, true);
			$progress = (float)($w['percentageDone'] ?? $w['derivedPercentageDone'] ?? 0);
			$tasks[] = [
				'title' => (string)($w['subject'] ?? ''),
				'start_date' => $start ?? $end,
				'end_date' => $end ?? $start,
				'status' => $closed ? 'done' : (string)($w['_links']['status']['title'] ?? ''),
				'progress' => $closed ? 100.0 : $progress,
				'milestone' => $milestone,
			];
		}
		return $tasks;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return list<array<string, mixed>>
	 */
	private static function elements(array $data): array {
		$elements = $data['_embedded']['elements'] ?? [];
		return is_array($elements) ? array_values(array_filter($elements, 'is_array')) : [];
	}

	/**
	 * @param array<string, mixed> $p
	 * @return array{id: string, title: string, url: string}
	 */
	private function project(array $p): array {
		$identifier = (string)($p['identifier'] ?? $p['id'] ?? '');
		return [
			'id' => (string)($p['id'] ?? ''),
			'title' => (string)($p['name'] ?? ''),
			'url' => $this->baseUrl() . '/projects/' . rawurlencode($identifier),
		];
	}

	private function token(string $userId): string {
		try {
			$value = $this->credentials->retrieve($userId, self::CREDENTIALS);
			return is_string($value) ? $value : '';
		} catch (\Throwable) {
			return '';
		}
	}

	/**
	 * @param array<string, string|int> $query
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 * @throws ValidationException
	 */
	private function request(string $userId, string $method, string $path, array $query = [], ?array $body = null): array {
		$base = $this->baseUrl();
		if ($base === '') {
			throw new ValidationException('OpenProject is not configured');
		}
		$token = $this->token($userId);
		if ($token === '') {
			throw new ValidationException('Store your OpenProject API key first');
		}
		return $this->client->request($method, $base . $path, ['apikey', $token], ['Accept' => 'application/hal+json'], $query, $body);
	}
}
