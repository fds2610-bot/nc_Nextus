<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Service\DashboardService;
use PHPUnit\Framework\TestCase;

class DashboardServiceTest extends TestCase {
	private function demand(int $id, string $status): Demand {
		$d = new Demand();
		$d->setId($id);
		$d->setStatus($status);
		return $d;
	}

	/** @return array<string, mixed> */
	private function project(int $id, string $status, ?array $rag, ?float $frame, ?float $forecast, ?float $deviationPct, float $actual = 0.0): array {
		return ['id' => $id, 'key' => 'P-' . $id, 'title' => 'Projekt ' . $id, 'status' => $status, 'rag' => $rag,
			'budgetFrame' => $frame, 'forecast' => $forecast, 'deviationPct' => $deviationPct, 'actual' => $actual];
	}

	public function testPortfolioCountsPhasesTrafficLightsAndCoverage(): void {
		$demands = [$this->demand(1, 'submitted'), $this->demand(2, 'in_execution'), $this->demand(3, 'in_execution'), $this->demand(4, 'cancelled'), $this->demand(5, 'prioritized')];
		$projects = [$this->project(2, 'in_execution', ['scope' => 'green', 'time' => 'red', 'cost' => 'yellow', 'risk' => 'green'], null, null, null), $this->project(3, 'in_execution', null, null, null, null), $this->project(4, 'cancelled', ['scope' => 'green', 'time' => 'green', 'cost' => 'green', 'risk' => 'green'], null, null, null)];
		$objectives = [
			['id' => 1, 'code' => 'O1', 'title' => 'Digital', 'validFrom' => null, 'validTo' => null, 'demandCount' => 2],
			['id' => 2, 'code' => 'O2', 'title' => 'Kosten', 'validFrom' => '2026-01-01', 'validTo' => null, 'demandCount' => 0],
			// abgelaufen: zählt nicht
			['id' => 3, 'code' => 'O3', 'title' => 'Alt', 'validFrom' => null, 'validTo' => '2025-12-31', 'demandCount' => 0],
		];
		$p = DashboardService::portfolio($demands, $projects, $objectives, '2026-11-12');
		$this->assertSame([2, 1, 1, 1], [$p['active'], $p['planned'], $p['phases']['demand'], $p['cancelled']]);
		$this->assertSame(['green' => 0, 'yellow' => 0, 'red' => 1, 'none' => 1], $p['rag']);
		$this->assertSame([2, 1], [$p['objectives'], $p['objectivesCovered']]);
		$this->assertSame('O2', $p['uncovered'][0]['code']);
	}

	public function testFinanceSumsRunningProjectsAndFlagsOverruns(): void {
		$f = DashboardService::finance([
			$this->project(1, 'in_execution', null, 1000.0, 1200.0, 20.0, 500.0),
			// ohne Forecast: Rahmen gilt als Prognose
			$this->project(2, 'released', null, 1000.0, null, null, 100.0),
			$this->project(3, 'in_execution', null, 1000.0, 1050.0, 5.0),
			$this->project(4, 'closed', null, 9999.0, 9999.0, 50.0),
		]);
		$this->assertSame(3, $f['projects']);
		$this->assertSame([3000.0, 600.0, 3250.0], [$f['frame'], $f['actual'], $f['forecast']]);
		$this->assertSame(8.3, $f['deviationPct']);
		$this->assertSame(1, $f['overBudget']);
		$this->assertSame(1, $f['top'][0]['id']);
	}

	public function testCapacityLooksAtBottlenecksOnly(): void {
		$heatmap = ['periods' => ['2026-11', '2026-12'], 'pools' => [
			['pool' => ['id' => 1, 'code' => 'SEC', 'name' => 'Security', 'isBottleneck' => 1], 'cells' => [['load' => 80], ['load' => 130]]],
			['pool' => ['id' => 2, 'code' => 'DEV', 'name' => 'Entwicklung', 'isBottleneck' => 0], 'cells' => [['load' => 300], ['load' => 300]]],
			// ohne Kapazität, aber mit Bedarf
			['pool' => ['id' => 3, 'code' => 'NET', 'name' => 'Netz', 'isBottleneck' => 1], 'cells' => [['load' => 0], ['load' => null]]],
		]];
		$cut = ['results' => [1 => ['state' => 'startable'], 2 => ['state' => 'waiting'], 3 => ['state' => 'waiting'], 4 => ['state' => 'shifted']]];
		$c = DashboardService::capacity($heatmap, $cut);
		$this->assertSame(['NET', 'SEC'], array_column($c['pools'], 'code'));
		$this->assertSame([130, 105, 1], [$c['pools'][1]['peak'], $c['pools'][1]['average'], $c['pools'][1]['overloadedMonths']]);
		$this->assertSame(2, $c['bottlenecks']);
		$this->assertSame([1, 1, 2], [$c['cut']['startable'], $c['cut']['shifted'], $c['cut']['waiting']]);
	}

	public function testRisksCountCriticalFromTheMatrix(): void {
		$r = DashboardService::risks([
			'open' => 4,
			'threshold' => 15,
			'matrix' => [5 => [4 => 1, 2 => 1], 3 => [5 => 1], 1 => [1 => 1]],
			'risks' => [
				['id' => 1, 'title' => 'A', 'score' => 15, 'escalated' => 0, 'escalationHint' => true, 'projects' => [['id' => 9, 'key' => 'P-9']]],
				['id' => 2, 'title' => 'B', 'score' => 20, 'escalated' => 1, 'escalationHint' => false, 'projects' => []],
			],
		]);
		$this->assertSame([4, 2, 1, 1], [$r['open'], $r['critical'], $r['escalated'], $r['escalationHints']]);
		$this->assertSame([2, 1], array_column($r['top'], 'id'));
		$this->assertNull($r['top'][0]['project']);
	}

	public function testDecisionAgesAndOverdueActions(): void {
		$now = 100 * 86400;
		$pending = [
			['kind' => 'triage', 'id' => 1, 'key' => 'D-1', 'title' => 'A', 'gate' => null, 'since' => $now - 3 * 86400],
			['kind' => 'gate', 'id' => 2, 'key' => 'P-2', 'title' => 'B', 'gate' => 'G2', 'since' => $now - 40 * 86400],
			['kind' => 'triage', 'id' => 3, 'key' => 'D-3', 'title' => 'C', 'gate' => null, 'since' => $now - 10 * 86400],
		];
		$d = DashboardService::decisionSummary($pending, [['overdue' => true], ['overdue' => false]], null, $now);
		$this->assertSame([3, 2, 1, 40, 10], [$d['pending'], $d['triage'], $d['gates'], $d['oldestDays'], $d['medianDays']]);
		$this->assertSame([2, 3, 1], array_column($d['oldest'], 'id'));
		$this->assertArrayNotHasKey('since', $d['oldest'][0]);
		$this->assertSame([2, 1], [$d['openActions'], $d['overdueActions']]);
	}

	public function testBenefitRealizationOnlyOverMeasuredPositions(): void {
		$b = DashboardService::benefits([
			'rows' => [
				['committed' => true, 'reviewDue' => true, 'demandId' => 1, 'demandKey' => 'D-1', 'demandTitle' => 'A', 'nextReview' => '2026-10-01'],
				['committed' => true, 'reviewDue' => false, 'demandId' => 2, 'demandKey' => 'D-2', 'demandTitle' => 'B', 'nextReview' => null],
				['committed' => false, 'reviewDue' => false, 'demandId' => 3, 'demandKey' => 'D-3', 'demandTitle' => 'C', 'nextReview' => null],
			],
			'balance' => ['total' => ['committed' => 3000.0, 'forecast' => 2800.0, 'realized' => 800.0, 'reviewedCommitted' => 1000.0], 'statuses' => ['on_track' => 2]],
			'factors' => [],
		]);
		$this->assertSame(80, $b['realizationPct']);
		$this->assertSame([2, 1], [$b['positions'], $b['reviewsDue']]);
		$this->assertSame('D-1', $b['due'][0]['key']);
	}

	public function testMaturityMatrixPlacesActiveDemandsByStage(): void {
		$demands = [];
		foreach ([[1, 'submitted'], [2, 'in_qualification'], [3, 'in_execution'], [4, 'paused'], [5, 'rejected'], [6, 'deferred'], [7, 'candidate']] as [$id, $status]) {
			$d = $this->demand($id, $status);
			$d->setDemandKey('D-' . $id);
			$d->setTitle('Vorhaben ' . $id);
			if ($id >= 3 && $id <= 4) {
				$d->setProjectKey('P-' . $id);
				$d->setBucket('D');
			}
			$demands[] = $d;
		}
		$m = DashboardService::maturity($demands, [1 => 10.0, 2 => 50.0, 3 => 300.0, 4 => 20.0, 5 => 99.0], [2 => 1000.0, 3 => 90000.0], ['S' => 5.0, 'M' => 30.0, 'L' => 200.0]);
		$this->assertSame(['intake', 'analysis', 'candidate', 'prioritized', 'released', 'execution', 'completion'], $m['stages']);
		// abgelehnt und zurückgestellt fehlen; ohne Aufwand (7) gesondert
		$this->assertSame([3, 2, 1, 4], array_column($m['points'], 'id'));
		$this->assertSame([7], array_column($m['withoutDays'], 'id'));
		$byId = array_column($m['points'], null, 'id');
		$this->assertSame(['execution', true, 'P-3', 90000.0], [$byId[3]['stage'], $byId[3]['project'], $byId[3]['key'], $byId[3]['cost']]);
		$this->assertSame(['execution', 'paused'], [$byId[4]['stage'], $byId[4]['status']]);
		// ohne gesetzten Bucket: Standard-Bucket des Antragstyps
		$this->assertSame(['C', 'D'], [$byId[1]['bucket'], $byId[3]['bucket']]);
		$this->assertSame(['analysis', false, 'D-2', null], [$byId[2]['stage'], $byId[2]['project'], $byId[2]['key'], $byId[1]['cost']]);
	}

	public function testTimelineUsesPlannedDatesOrDemandMonths(): void {
		$demands = [];
		foreach ([[1, 'candidate'], [2, 'in_execution'], [3, 'prioritized'], [4, 'submitted'], [5, 'rejected']] as [$id, $status]) {
			$d = $this->demand($id, $status);
			$d->setDemandKey('D-' . $id);
			$d->setTitle('Vorhaben ' . $id);
			if ($id === 2) {
				$d->setProjectKey('P-2');
			}
			$demands[] = $d;
		}
		$spans = [2 => ['start' => '2026-03-01', 'end' => '2026-12-31'], 3 => ['start' => '2027-01-15', 'end' => null]];
		$months = [1 => ['2026-11', '2026-09'], 3 => ['2027-04', '2027-02'], 5 => ['2026-01']];
		$reports = [2 => [
			['date' => '2026-06-30', 'period' => '2026-06', 'rag' => 'yellow'],
			['date' => '2026-05-31', 'period' => '2026-05', 'rag' => 'green'],
		]];
		$t = DashboardService::timeline($demands, $spans, $months, $reports, '2026-11-12');
		$this->assertSame('2026-11-12', $t['today']);
		// nach Beginn sortiert; ohne Termine und Bedarf (4) nur gezählt, abgelehnt (5) fehlt
		$this->assertSame([2, 1, 3], array_column($t['bars'], 'id'));
		$this->assertSame(1, $t['withoutDates']);
		$byId = array_column($t['bars'], null, 'id');
		$this->assertSame(['P-2', true, 'plan', '2026-03-01', '2026-12-31'], [$byId[2]['key'], $byId[2]['released'], $byId[2]['source'], $byId[2]['start'], $byId[2]['end']]);
		$this->assertSame(['2026-05-31', '2026-06-30'], array_column($byId[2]['reports'], 'date'));
		// Bedarfsmonate: erster Monatsanfang bis letztes Monatsende
		$this->assertSame([false, 'demand', '2026-09-01', '2026-11-30'], [$byId[1]['released'], $byId[1]['source'], $byId[1]['start'], $byId[1]['end']]);
		// gesetzter Beginn bleibt, fehlendes Ende aus dem Bedarf
		$this->assertSame(['2027-01-15', '2027-04-30', false], [$byId[3]['start'], $byId[3]['end'], $byId[3]['released']]);
	}
}
