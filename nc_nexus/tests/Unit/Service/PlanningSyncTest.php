<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\PlanningLinkMapper;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ValidationException;
use OCA\Nexus\Planning\GanttProvider;
use OCA\Nexus\Planning\IPlanningProvider;
use OCA\Nexus\Planning\MsProjectProvider;
use OCA\Nexus\Planning\PlanningConfig;
use OCA\Nexus\Planning\PlanningProviderRegistry;
use OCA\Nexus\Service\AuditService;
use OCA\Nexus\Service\CapacityService;
use OCA\Nexus\Service\DemandService;
use OCA\Nexus\Service\PermissionService;
use OCA\Nexus\Service\PlanningService;
use OCA\Nexus\Service\ProjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Hintergrundabgleich (Kapitel 07 §4.2): fällige Verknüpfungen, Konto der Verknüpfung, Fehler.
 */
class PlanningSyncTest extends TestCase {
	private const NOW = 1794600000;

	private PlanningLinkMapper&MockObject $links;
	private PlanningConfig&MockObject $config;
	private IPlanningProvider&MockObject $live;
	/** @var list<array<string, mixed>> */
	private array $updates = [];
	private PlanningService $service;

	protected function setUp(): void {
		$this->links = $this->createMock(PlanningLinkMapper::class);
		$this->links->method('updateSync')->willReturnCallback(function (int $id, string $status, ?array $summary, int $now, ?array $error = null): void {
			$this->updates[] = ['id' => $id, 'status' => $status, 'summary' => $summary !== null, 'error' => $error];
		});
		$this->config = $this->createMock(PlanningConfig::class);
		$this->live = $this->createMock(IPlanningProvider::class);
		$this->live->method('getCapabilities')->willReturn([IPlanningProvider::CAP_SYNC]);
		$this->live->method('isAvailable')->willReturnCallback(fn (string $uid) => $uid !== 'gone');
		$file = $this->createMock(IPlanningProvider::class);
		$file->method('getCapabilities')->willReturn([IPlanningProvider::CAP_IMPORT]);
		$registry = $this->createMock(PlanningProviderRegistry::class);
		$registry->method('get')->willReturnCallback(fn (string $id) => ['gantt' => $this->live, 'mspdi' => $file][$id] ?? null);
		$registry->method('safeAvailable')->willReturnCallback(fn (IPlanningProvider $p, string $uid) => $p->isAvailable($uid));
		$demands = $this->createMock(DemandMapper::class);
		$demands->method('findAll')->willReturn([
			self::demand(1, 'in_execution'), self::demand(2, 'released'), self::demand(3, 'closed'), self::demand(4, 'in_execution'), self::demand(5, 'paused'),
		]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => $params === [] ? $text : vsprintf($text, $params));
		$this->service = new PlanningService(
			$this->links, $registry, $this->config,
			$this->createMock(GanttProvider::class), $this->createMock(MsProjectProvider::class),
			$this->createMock(ProjectService::class), $this->createMock(ResourceNeedMapper::class),
			$this->createMock(PoolMapper::class), $this->createMock(CapacityService::class),
			$this->createMock(Rows::class), $this->createMock(DemandService::class),
			$this->createMock(PermissionService::class), $this->createMock(AuditService::class),
			$time, $demands, $l10n,
		);
	}

	private static function demand(int $id, string $status): Demand {
		$d = new Demand();
		$d->setId($id);
		$d->setStatus($status);
		$d->setCreatedAt(self::NOW - 100 * 86400);
		return $d;
	}

	/** @return array<string, mixed> */
	private static function link(int $id, int $demandId, string $provider, ?int $syncedAt, string $by = 'pl'): array {
		return [
			'id' => $id, 'demandId' => $demandId, 'provider' => $provider, 'externalId' => 'p' . $id,
			'syncStatus' => 'ok', 'syncError' => null, 'summary' => null, 'syncedAt' => $syncedAt,
			'createdBy' => $by, 'createdAt' => self::NOW - 50 * 86400,
		];
	}

	public function testSyncsOnlyDueLiveLinksOfRunningProjects(): void {
		$this->config->method('syncHours')->willReturn(24);
		$this->links->method('all')->willReturn([
			self::link(10, 1, 'gantt', self::NOW - 25 * 3600),   // fällig
			self::link(11, 2, 'gantt', self::NOW - 3600),        // erst vor einer Stunde
			self::link(12, 3, 'gantt', null),                    // Vorhaben geschlossen
			self::link(13, 4, 'mspdi', null),                    // Datei-Schnappschuss
			self::link(14, 5, 'gantt', null),                    // nie abgeglichen
		]);
		$this->live->expects($this->exactly(2))->method('tasks')->with('pl')->willReturn([
			['title' => 'A', 'start_date' => '2026-11-02', 'end_date' => '2026-11-06', 'status' => 'done'],
		]);
		$this->config->expects($this->once())->method('setLastSync')->with(['at' => self::NOW, 'ok' => 2, 'failed' => 0]);

		$run = $this->service->syncAll();

		$this->assertSame(['at' => self::NOW, 'ok' => 2, 'failed' => 0], $run);
		$this->assertSame([10, 14], array_column($this->updates, 'id'));
		$this->assertSame(['ok', 'ok'], array_column($this->updates, 'status'));
	}

	public function testForceSyncsAlsoRecentLinks(): void {
		$this->config->method('syncHours')->willReturn(0);
		$this->links->method('all')->willReturn([self::link(11, 2, 'gantt', self::NOW - 60)]);
		$this->live->method('tasks')->willReturn([]);
		$this->assertSame(0, $this->service->syncAll()['ok'], 'abgeschaltet: nichts');
		$this->assertSame(1, $this->service->syncAll(true)['ok']);
	}

	public function testErrorsKeepSummaryAndAreStoredForTheCockpit(): void {
		$this->config->method('syncHours')->willReturn(1);
		$this->links->method('all')->willReturnOnConsecutiveCalls(
			[self::link(10, 1, 'gantt', null), self::link(11, 2, 'gantt', null, 'gone')],
			[['syncStatus' => 'error', 'syncError' => ['key' => 'The planning tool reported an error (%1$s)', 'params' => ['not_found']]] + self::link(10, 1, 'gantt', self::NOW)],
		);
		$this->live->method('tasks')->willThrowException(new ValidationException('The planning tool reported an error (%1$s)', ['not_found']));

		$run = $this->service->syncAll();

		$this->assertSame(2, $run['failed']);
		$this->assertSame(['error', 'error'], array_column($this->updates, 'status'));
		$this->assertSame([false, false], array_column($this->updates, 'summary'), 'letzter Stand bleibt');
		$this->assertSame(['gone'], $this->updates[1]['error']['params'], 'Konto der Verknüpfung nicht verfügbar');
		$this->assertSame([1 => 'The planning tool reported an error (not_found)'], $this->service->syncErrors());
	}
}
