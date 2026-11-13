<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Planning;

use OCA\Nexus\Planning\PlanDemand;
use PHPUnit\Framework\TestCase;

class PlanDemandTest extends TestCase {
	public function testSpreadsByWorkdaysOverMonths(): void {
		// Do 26.11. bis Do 3.12.2026: je 3 Arbeitstage im November und Dezember
		$this->assertSame(['2026-11' => 5.0, '2026-12' => 5.0], PlanDemand::spread('2026-11-26', '2026-12-03', 10));
		$this->assertSame(['2026-11' => 3.0], PlanDemand::spread('', '2026-11-27', 3));
		// nur Wochenende: Monat des Beginns
		$this->assertSame(['2026-11' => 2.0], PlanDemand::spread('2026-11-28', '2026-11-29', 2));
		$this->assertSame([], PlanDemand::spread('', '', 5));
	}

	public function testAggregatesAndMapsRoles(): void {
		$demand = PlanDemand::aggregate([
			['start_date' => '2026-11-26', 'end_date' => '2026-12-03', 'demand' => [['role' => 'Entwicklung', 'days' => 10], ['role' => '', 'days' => 1]]],
			['start_date' => '2026-12-07', 'end_date' => '2026-12-07', 'demand' => [['role' => ' Entwicklung ', 'days' => 2]]],
			['start_date' => '2026-12-07', 'end_date' => '2026-12-07'],
		]);
		$this->assertSame([
			['role' => '', 'period' => '2026-11', 'days' => 0.5],
			['role' => '', 'period' => '2026-12', 'days' => 0.5],
			['role' => 'Entwicklung', 'period' => '2026-11', 'days' => 5.0],
			['role' => 'Entwicklung', 'period' => '2026-12', 'days' => 7.0],
		], $demand);

		$mapped = PlanDemand::map($demand, ['Entwicklung' => 3]);
		$this->assertSame([3 => ['2026-11' => 5.0, '2026-12' => 7.0]], $mapped['pools']);
		$this->assertSame(['' => 1.0], $mapped['unmapped']);
		$this->assertSame(['2026-11', '2026-12'], $mapped['periods']);
	}

	public function testIsoDurations(): void {
		$this->assertSame(2.0, PlanDemand::isoDays('PT16H0M0S'));
		$this->assertSame(3.5, PlanDemand::isoDays('P1DT4H'));
		$this->assertSame(0.0, PlanDemand::isoDays('16h'));
		$this->assertSame(0.0, PlanDemand::isoDays(null));
	}
}
