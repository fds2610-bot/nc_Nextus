<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

use OCA\Nexus\Service\GanttGateway;

/**
 * nc_gantt über IGanttApi v1 (Kapitel 07 §5.1).
 */
class GanttProvider implements IPlanningProvider {
	public function __construct(
		private GanttGateway $gateway,
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

	public function isAvailable(string $userId): bool {
		return $this->gateway->isAvailable($userId);
	}

	public function listProjects(string $userId): array {
		return array_map(static fn (array $p) => ['id' => (string)$p['id'], 'title' => $p['title'], 'url' => $p['url']], $this->gateway->listProjects($userId));
	}

	public function createProject(string $userId, string $title, ?string $description): array {
		$p = $this->gateway->createProject($userId, $title, $description);
		return ['id' => (string)$p['id'], 'title' => $p['title'], 'url' => $p['url']];
	}

	public function tasks(string $userId, string $externalId): array {
		return array_map(static fn (array $t) => [
			'title' => (string)($t['title'] ?? ''),
			'start_date' => isset($t['start_date']) && $t['start_date'] !== '' ? substr((string)$t['start_date'], 0, 10) : null,
			'end_date' => isset($t['end_date']) && $t['end_date'] !== '' ? substr((string)$t['end_date'], 0, 10) : null,
			'status' => (string)($t['status'] ?? ''),
			'progress' => (float)($t['progress'] ?? 0),
		], $this->gateway->tasks($userId, (int)$externalId));
	}
}
