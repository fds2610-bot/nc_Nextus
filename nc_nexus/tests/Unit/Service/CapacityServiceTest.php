<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\Pool;
use OCA\Nexus\Service\CapacityService;
use PHPUnit\Framework\TestCase;

class CapacityServiceTest extends TestCase {
	private function pool(float $absence, float $line, float $overhead, float $reserve): Pool {
		$pool = new Pool();
		$pool->setAbsencePct($absence);
		$pool->setLinePct($line);
		$pool->setOverheadPct($overhead);
		$pool->setReservePct($reserve);
		return $pool;
	}

	public function testCalculationFollowsTheExampleOfChapter04(): void {
		// Pool „IT-Security“, 3,0 FTE, 62 Arbeitstage: 186 → 156 → 101 → 89 → 80 PT
		$result = CapacityService::calculate($this->pool(16, 35, 12, 10), 3.0, 62);
		$this->assertSame(186.0, $result['gross']);
		$this->assertEqualsWithDelta(89.4, $result['net'], 0.1);
		$this->assertEqualsWithDelta(80.4, $result['project'], 0.1);
	}

	public function testWeekdaysOfAMonth(): void {
		$this->assertSame(21, CapacityService::weekdays('2026-11'));
		$this->assertSame(23, CapacityService::weekdays('2026-12'));
		$this->assertSame(20, CapacityService::weekdays('2027-02'));
	}

	public function testPeriodsAreConsecutiveMonths(): void {
		$this->assertSame(['2026-11', '2026-12', '2027-01'], CapacityService::periods('2026-11', 3));
		$this->assertCount(24, CapacityService::periods('2026-11', 99));
	}
}
