<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\OrgUnit;
use OCA\Nexus\Db\OrgUnitMapper;
use OCA\Nexus\Db\ResourceNeed;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Service\CapacityService;
use OCA\Nexus\Service\DemandService;
use OCA\Nexus\Service\PortfolioService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class PortfolioServiceTest extends TestCase {
	private PortfolioService $service;

	protected function setUp(): void {
		$demands = [];
		foreach ([[1, 'submitted', 2], [2, 'deferred', 1], [3, 'rejected', null], [4, 'draft', 2], [5, 'in_qualification', 3]] as [$id, $status, $unit]) {
			$d = new Demand();
			$d->setId($id);
			$d->setStatus($status);
			$d->setOrgUnitId($unit);
			$d->setCreatedAt(1000);
			$demands[] = $d;
		}
		$demandService = $this->createMock(DemandService::class);
		$demandService->method('findAll')->willReturn($demands);

		$units = [];
		foreach ([[1, null], [2, 1], [3, null]] as [$id, $parent]) { // IT › Infrastruktur; Vertrieb
			$u = new OrgUnit();
			$u->setId($id);
			$u->setParentId($parent);
			$units[] = $u;
		}
		$orgUnits = $this->createMock(OrgUnitMapper::class);
		$orgUnits->method('findAll')->willReturn($units);

		$needs = [];
		foreach ([[1, '2026-11', 10.0], [1, '2027-02', 5.0], [2, '2026-12', 8.0], [5, '2026-12', 4.0]] as [$demand, $period, $days]) {
			$n = new ResourceNeed();
			$n->setDemandId($demand);
			$n->setPeriod($period);
			$n->setPersonDays($days);
			$n->setUpdatedAt(2000);
			$needs[] = $n;
		}
		$needMapper = $this->createMock(ResourceNeedMapper::class);
		$needMapper->method('findRange')->willReturn($needs);

		$capacity = $this->createMock(CapacityService::class);
		$capacity->method('canSee')->willReturn(true);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794268800); // 10.11.2026

		$this->service = new PortfolioService($demandService, $orgUnits, $needMapper, $capacity, $time);
	}

	/** @return array<string, mixed> */
	private function row(array $overview, ?int $unit): array {
		foreach ($overview['rows'] as $row) {
			if ($row['orgUnitId'] === $unit) {
				return $row;
			}
		}
		$this->fail('Zeile fehlt');
	}

	public function testParentUnitsRollUpTheirSubunits(): void {
		$o = $this->service->overview('pmo');
		// IT (1) enthält den eigenen zurückgestellten Antrag und den eingereichten aus Infrastruktur (2)
		$it = $this->row($o, 1);
		$this->assertSame(1, $it['phases']['demand']['count']);
		$this->assertSame(1, $it['phases']['on_hold']['count']);
		$this->assertSame(15.0, $it['phases']['demand']['days']);
		$this->assertSame(1, $this->row($o, 2)['total']);
		$this->assertSame(1, $this->row($o, null)['phases']['closed']['count']);
	}

	public function testDraftsDoNotCount(): void {
		$this->assertSame(4, $this->service->overview('pmo')['totals']['total']);
	}

	public function testQuartersContainOnlyOpenDemandNeeds(): void {
		$o = $this->service->overview('pmo');
		$this->assertSame(['2026-Q4', '2027-Q1', '2027-Q2', '2027-Q3'], $o['quarters']);
		// Q4: 10 (Antrag 1) + 4 (Antrag 5); die 8 PT des zurückgestellten Antrags zählen nicht
		$this->assertSame(14.0, $o['totals']['quarters']['2026-Q4']);
		$this->assertSame(5.0, $o['totals']['quarters']['2027-Q1']);
	}
}
