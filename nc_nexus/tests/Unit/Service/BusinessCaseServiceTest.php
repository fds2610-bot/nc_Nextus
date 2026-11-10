<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Service\BusinessCaseService;
use PHPUnit\Framework\TestCase;

class BusinessCaseServiceTest extends TestCase {
	public function testKpis(): void {
		$kpis = BusinessCaseService::kpis([
			'costs' => [
				['kind' => 'capex', 'amount' => 80000],
				['kind' => 'opex_once', 'amount' => 20000],
				['kind' => 'opex_annual', 'amount' => 10000],
			],
			'benefits' => [
				['type' => 'cost_saving', 'amount' => 40000],
				['type' => 'capacity', 'amount' => 20000],
				['type' => 'qualitative', 'amount' => null],
			],
			'discountRate' => 0,
		]);
		$this->assertSame(100000.0, $kpis['oneTimeCost']);
		$this->assertSame(10000.0, $kpis['annualCost']);
		$this->assertSame(60000.0, $kpis['benefitPerYear']);
		$this->assertSame(150000.0, $kpis['tco']);
		$this->assertSame(2.0, $kpis['paybackYears']); // 100 000 / (60 000 − 10 000)
		$this->assertSame(150000.0, $kpis['npv']); // −100 000 + 5 × 50 000 bei 0 %
	}

	public function testNoPaybackWithoutNetBenefit(): void {
		$kpis = BusinessCaseService::kpis(['costs' => [['kind' => 'opex_annual', 'amount' => 5000]], 'benefits' => [], 'discountRate' => 5]);
		$this->assertNull($kpis['paybackYears']);
		$this->assertLessThan(0, $kpis['npv']);
	}

	public function testMissingForGate(): void {
		$this->assertSame(['benefit', 'cost'], BusinessCaseService::missing(BusinessCaseService::empty()));
	}
}
