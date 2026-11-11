<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\AllocationMapper;
use OCA\Nexus\Db\Pool;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\ResourceNeed;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Service\BusinessCaseService;
use OCA\Nexus\Service\CapacityCutService;
use OCA\Nexus\Service\CapacityService;
use OCA\Nexus\Service\RankingService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Szenario: ein Engpass-Pool SEC mit 20 PT je Monat, 5 PT bereits hart zugesagt.
 * Pflicht-Bucket A zieht zuerst ab; B bekommt 100 % des Rests (einziger bewerteter Bucket).
 */
class CapacityCutServiceTest extends TestCase {
	private function need(int $demand, string $period, float $days): ResourceNeed {
		$n = new ResourceNeed();
		$n->setDemandId($demand);
		$n->setPoolId(1);
		$n->setPeriod($period);
		$n->setPersonDays($days);
		return $n;
	}

	/** @param array<string, mixed> $extra */
	private function item(int $id, bool $assessed = true): array {
		return ['id' => $id, 'assessment' => $assessed ? ['density' => 1.0] : null];
	}

	private function service(?float $budget, float $cost): CapacityCutService {
		$pool = new Pool();
		$pool->setId(1);
		$pool->setCode('SEC');
		$pool->setIsBottleneck(1);
		$pools = $this->createMock(PoolMapper::class);
		$pools->method('findAll')->willReturn([$pool]);

		$capacity = $this->createMock(CapacityService::class);
		$capacity->method('capacityRows')->willReturnCallback(fn (Pool $p, string $from, int $months) => array_map(
			static fn (string $period) => ['period' => $period, 'project' => 20.0],
			CapacityService::periods($from, $months),
		));

		$ranking = $this->createMock(RankingService::class);
		$ranking->method('ranking')->willReturn([
			['code' => 'A', 'scored' => false, 'targetSharePct' => 20.0, 'targetBudget' => null, 'items' => [$this->item(1)]],
			['code' => 'B', 'scored' => true, 'targetSharePct' => 80.0, 'targetBudget' => $budget, 'items' => [$this->item(2), $this->item(3), $this->item(4), $this->item(5, false)]],
		]);

		$needs = $this->createMock(ResourceNeedMapper::class);
		$needs->method('findRange')->willReturn([
			$this->need(1, '2026-11', 10),  // Pflicht: passt (20 − 5 hart = 15)
			$this->need(2, '2026-11', 5),   // passt in den Rest (5)
			$this->need(3, '2026-11', 12),  // November voll → um 1 Monat verschoben
			$this->need(4, '2026-11', 100), // passt nie → wartet auf SEC
		]);
		$alloc = $this->createMock(AllocationMapper::class);
		$alloc->method('hardByPoolAndPeriod')->willReturn([1 => ['2026-11' => 5.0]]);

		$cases = $this->createMock(BusinessCaseService::class);
		$cases->method('get')->willReturn(['kpis' => ['oneTimeCost' => $cost]]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794268800); // 10.11.2026
		return new CapacityCutService($ranking, $capacity, $pools, $needs, $alloc, $cases, $time);
	}

	public function testStartPlanWithShiftAndWaiting(): void {
		$r = $this->service(null, 0)->cut('pmo')['results'];
		$this->assertSame('startable', $r[1]['state']);
		$this->assertSame('startable', $r[2]['state']);
		$this->assertSame(['state' => 'shifted', 'shift' => 1, 'start' => '2026-12'], $r[3]);
		$this->assertSame('waiting', $r[4]['state']);
		$this->assertSame('SEC', $r[4]['pool']);
		$this->assertSame('unassessed', $r[5]['state']);
	}

	public function testBudgetFrameOfTheBucket(): void {
		// 50 000 € Rahmen, jedes Vorhaben kostet 30 000 €: das zweite wartet auf Budget
		$r = $this->service(50000.0, 30000.0)->cut('pmo')['results'];
		$this->assertSame('startable', $r[2]['state']);
		$this->assertSame('budget', $r[3]['state']);
	}

	public function testShift(): void {
		$this->assertSame('2027-02', CapacityCutService::shift('2026-11', 3));
	}
}
