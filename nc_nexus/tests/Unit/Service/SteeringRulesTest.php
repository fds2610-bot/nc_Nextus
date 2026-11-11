<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Service\KpiService;
use OCA\Nexus\Service\ProjectService;
use PHPUnit\Framework\TestCase;

/**
 * Regeln der Steuerung: Ampel der Kennzahlen und Widerspruch zwischen Meldung und Health-Indikator.
 */
class SteeringRulesTest extends TestCase {
	/** @return array<string, mixed> */
	private function kpi(string $direction, ?float $green, ?float $red): array {
		return ['direction' => $direction, 'thresholdGreen' => $green, 'thresholdRed' => $red];
	}

	public function testKpiLevelFollowsTheDirection(): void {
		// höher ist besser: Datenvollständigkeit grün ab 95 %, rot unter 80 %
		$this->assertSame('green', KpiService::level($this->kpi('up', 95, 80), 97.0));
		$this->assertSame('yellow', KpiService::level($this->kpi('up', 95, 80), 85.0));
		$this->assertSame('red', KpiService::level($this->kpi('up', 95, 80), 70.0));
		// niedriger ist besser: Engpassauslastung bis 90 % grün, über 100 % rot
		$this->assertSame('green', KpiService::level($this->kpi('down', 90, 100), 88.0));
		$this->assertSame('yellow', KpiService::level($this->kpi('down', 90, 100), 95.0));
		$this->assertSame('red', KpiService::level($this->kpi('down', 90, 100), 104.0));
	}

	public function testKpiWithoutValueOrThresholdHasNoTrafficLight(): void {
		$this->assertNull(KpiService::level($this->kpi('up', 95, 80), null));
		$this->assertNull(KpiService::level($this->kpi('info', null, null), 12.0));
	}

	public function testGreenReportContradictsRedFindingOfTheSameDimension(): void {
		$service = (new \ReflectionClass(ProjectService::class))->newInstanceWithoutConstructor();
		$health = ['level' => 'red', 'findings' => [
			['rule' => 'milestone_overdue', 'level' => 'red', 'dimension' => 'time', 'source' => 'milestones', 'detail' => ''],
			['rule' => 'effort_deviation', 'level' => 'yellow', 'dimension' => 'cost', 'source' => 'actuals', 'detail' => ''],
		]];
		$report = ['ragScope' => 'green', 'ragTime' => 'green', 'ragCost' => 'green', 'ragRisk' => 'green'];
		$this->assertSame(['time'], $service->contradictions($report, $health), 'gelbe Befunde widersprechen nicht');
		$this->assertSame([], $service->contradictions(['ragTime' => 'yellow'] + $report, $health), 'gelb gemeldet ist kein Widerspruch');
	}
}
