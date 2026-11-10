<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\AuditEntry;
use OCA\Nexus\Db\CommentMapper;
use OCA\Nexus\Db\CounterMapper;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\OrgUnitMapper;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCA\Nexus\Service\AuditService;
use OCA\Nexus\Service\DemandService;
use OCA\Nexus\Service\PermissionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DemandServiceTest extends TestCase {
	private DemandMapper&MockObject $mapper;
	private AuditService&MockObject $audit;
	private CounterMapper&MockObject $counters;
	private DemandService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(DemandMapper::class);
		$this->audit = $this->createMock(AuditService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794268800); // 10.11.2026
		$permissions = $this->createMock(PermissionService::class);
		// Lesen darf nur der Antragsteller (genügt für diese Tests)
		$permissions->method('canReadDemand')->willReturnCallback(fn (string $uid, Demand $d) => $d->getRequestorId() === $uid);
		$this->service = new DemandService($this->mapper, $this->audit, $this->createMock(IDBConnection::class), $time, $permissions, $this->createMock(OrgUnitMapper::class), $this->counters = $this->createMock(CounterMapper::class), $this->createMock(CommentMapper::class), $this->createMock(ResourceNeedMapper::class));
	}

	private function draft(string $requestor = 'alice', string $status = 'draft'): Demand {
		$demand = new Demand();
		$demand->setId(7);
		$demand->setTitle('Alt');
		$demand->setDemandType('strategic');
		$demand->setRequestorId($requestor);
		$demand->setStatus($status);
		return $demand;
	}

	public function testCreateAssignsKeyAndLogs(): void {
		$this->mapper->method('maxSequence')->with('DEM-2026-')->willReturn(146);
		$this->counters->expects($this->once())->method('next')->with('demand-2026', 146)->willReturn(147);
		$this->mapper->method('insert')->willReturnCallback(function (Demand $d) {
			$d->setId(1);
			return $d;
		});
		$this->audit->expects($this->once())->method('log')
			->with('demand', 1, 'create', 'alice', ['title' => [null, 'Neuer Antrag'], 'demandType' => [null, 'innovation']])
			->willReturn(new AuditEntry());

		$demand = $this->service->create('alice', ['title' => '  Neuer Antrag ', 'demandType' => 'innovation']);

		$this->assertSame('DEM-2026-0147', $demand->getDemandKey());
		$this->assertSame('draft', $demand->getStatus());
		$this->assertSame('alice', $demand->getRequestorId());
	}

	public function testCreateRejectsMissingTitle(): void {
		$this->expectException(ValidationException::class);
		$this->service->create('alice', ['title' => ' ', 'demandType' => 'strategic']);
	}

	public function testCreateRejectsUnknownType(): void {
		$this->expectException(ValidationException::class);
		$this->service->create('alice', ['title' => 'X', 'demandType' => 'wish']);
	}

	public function testUpdateLogsOnlyChangedFields(): void {
		$this->mapper->method('find')->willReturn($this->draft());
		$this->mapper->method('update')->willReturnArgument(0);
		$this->audit->expects($this->once())->method('log')
			->with('demand', 7, 'update', 'alice', ['title' => ['Alt', 'Neu']])
			->willReturn(new AuditEntry());

		$this->service->update('alice', 7, ['title' => 'Neu', 'demandType' => 'strategic']);
	}

	public function testUpdateWithoutChangesWritesNothing(): void {
		$this->mapper->method('find')->willReturn($this->draft());
		$this->mapper->expects($this->never())->method('update');
		$this->audit->expects($this->never())->method('log');

		$this->service->update('alice', 7, ['title' => 'Alt']);
	}

	public function testForeignDemandIsHiddenAsNotFound(): void {
		$this->mapper->method('find')->willReturn($this->draft('bob'));
		$this->expectException(NotFoundException::class);
		$this->service->update('alice', 7, ['title' => 'Neu']);
	}

	public function testSubmittedDemandIsLocked(): void {
		$this->mapper->method('find')->willReturn($this->draft('alice', 'submitted'));
		$this->expectException(ForbiddenException::class);
		$this->service->delete('alice', 7);
	}

	public function testSubmitRequiresProblemStatement(): void {
		$this->mapper->method('find')->willReturn($this->draft());
		$this->expectException(ValidationException::class);
		$this->service->submit('alice', 7);
	}

	public function testSubmitSetsStatusAndTimestamp(): void {
		$draft = $this->draft();
		$draft->setProblemStatement('Sonst droht Bußgeld');
		$this->mapper->method('find')->willReturn($draft);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->audit->expects($this->once())->method('log')
			->with('demand', 7, 'status_change', 'alice', ['status' => ['draft', 'submitted']])
			->willReturn(new AuditEntry());

		$demand = $this->service->submit('alice', 7);

		$this->assertSame('submitted', $demand->getStatus());
		$this->assertSame(1794268800, $demand->getSubmittedAt());
	}
}
