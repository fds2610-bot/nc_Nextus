<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\AllocationMapper;
use OCA\Nexus\Db\Pool;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Service\BusinessCaseService;
use OCA\Nexus\Service\CapacityCutService;
use OCA\Nexus\Service\CapacityService;
use OCA\Nexus\Service\RankingService;
use OCA\Nexus\Service\ScenarioService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Ausgangslage: Engpass-Pool SEC mit 20 PT je Monat, nur Bucket B (100 % des Rests).
 * Vorhaben 2 (Wertdichte 2) und 3 (Wertdichte 1) brauchen je 10 PT im November → beide startfähig.
 */
class ScenarioServiceTest extends TestCase {
	private function pool(): Pool {
		$pool = new Pool();
		$pool->setId(1);
		$pool->setCode('SEC');
		$pool->setName('Security');
		$pool->setIsBottleneck(1);
		$pool->setAbsencePct(0.0);
		$pool->setLinePct(0.0);
		$pool->setOverheadPct(0.0);
		$pool->setReservePct(0.0);
		return $pool;
	}

	/** @return array<string, mixed> */
	private function item(int $id, ?float $density): array {
		return ['id' => $id, 'key' => 'D-' . $id, 'title' => 'Vorhaben ' . $id, 'status' => 'candidate', 'deadline' => null, 'personDays' => 10.0,
			'rankOverride' => null, 'assessment' => $density === null ? null : ['density' => $density, 'effort' => 3]];
	}

	/** @return array<string, mixed> */
	private function input(float $hard = 0.0): array {
		$periods = CapacityService::periods('2026-11', 12);
		$capacity = [];
		foreach ($periods as $p) {
			$capacity[1][$p] = 20.0;
		}
		return [
			'from' => '2026-11',
			'periods' => $periods,
			'buckets' => [
				['code' => 'A', 'name' => 'Pflicht', 'scored' => false, 'targetSharePct' => 0.0, 'targetBudget' => null, 'items' => []],
				['code' => 'B', 'name' => 'Strategie', 'scored' => true, 'targetSharePct' => 100.0, 'targetBudget' => null,
					'items' => RankingService::rank([$this->item(2, 2.0), $this->item(3, 1.0)], true)],
			],
			'pools' => [1 => $this->pool()],
			'capacity' => $capacity,
			'hard' => $hard > 0 ? [1 => ['2026-11' => $hard]] : [],
			'needs' => [
				2 => [['poolId' => 1, 'period' => '2026-11', 'personDays' => 10.0]],
				3 => [['poolId' => 1, 'period' => '2026-11', 'personDays' => 10.0]],
				9 => [['poolId' => 1, 'period' => '2026-11', 'personDays' => 15.0]],
			],
		];
	}

	private function cut(): CapacityCutService {
		$cases = $this->createMock(BusinessCaseService::class);
		$cases->method('get')->willReturn(['kpis' => ['oneTimeCost' => 1000.0]]);
		return new CapacityCutService(
			$this->createMock(RankingService::class),
			$this->createMock(CapacityService::class),
			$this->createMock(PoolMapper::class),
			$this->createMock(ResourceNeedMapper::class),
			$this->createMock(AllocationMapper::class),
			$cases,
			$this->createMock(ITimeFactory::class),
		);
	}

	/**
	 * @param list<array<string, mixed>> $changes
	 * @param array<string, mixed> $extra
	 * @return array<int, array<string, mixed>> Vergleichszeilen je Antrag
	 */
	private function compare(array $changes, array $extra = [], float $hard = 0.0): array {
		$in = $this->input($hard);
		$scenarioIn = ScenarioService::apply($in, $changes, $extra + ['added' => [], 'hard' => []]);
		$cut = $this->cut();
		$result = ScenarioService::compare($in, $cut->compute('pmo', $in), $scenarioIn, $cut->compute('pmo', $scenarioIn), [2 => 1000.0, 3 => 1000.0, 9 => 1000.0]);
		$this->last = $result;
		return array_column($result['items'], null, 'id');
	}

	/** @var array<string, mixed> */
	private array $last = [];

	public function testStatusQuoChangesNothing(): void {
		$rows = $this->compare([]);
		$this->assertSame('unchanged', $rows[2]['effect']);
		$this->assertSame('unchanged', $rows[3]['effect']);
		$this->assertSame(0, $this->last['summary']['falls'] + $this->last['summary']['later']);
	}

	public function testNewProjectDisplacesLowerRankedOnes(): void {
		// Neuaufnahme mit angenommener Wertdichte 5: steht vorn, belegt 15 von 20 PT im November
		$added = [9 => $this->item(9, null) + ['bucket' => 'B']];
		$rows = $this->compare([['changeType' => 'add_project', 'targetRef' => '9', 'parameters' => ['bucket' => 'B', 'density' => 5.0]]], ['added' => $added]);
		$this->assertSame('added', $rows[9]['effect']);
		$this->assertSame(1, $rows[9]['rankScenario']);
		$this->assertSame('startable', $rows[9]['scenario']['state']);
		$this->assertSame('later', $rows[2]['effect']);
		$this->assertSame('2026-12', $rows[2]['scenario']['start']);
		$this->assertSame([1, 2], [$rows[2]['rankBase'], $rows[2]['rankScenario']]);
		$this->assertSame(1, $this->last['summary']['added']);
	}

	public function testRemovingAProjectFreesCapacity(): void {
		$rows = $this->compare([['changeType' => 'remove_project', 'targetRef' => '2', 'parameters' => []]]);
		$this->assertSame('removed', $rows[2]['effect']);
		$this->assertNull($rows[2]['scenario']);
		$this->assertSame(1, $rows[3]['rankScenario']);
		$bucketB = $this->last['buckets'][1];
		$this->assertSame(2, $bucketB['base']['placed']);
		$this->assertSame(1, $bucketB['scenario']['placed']);
	}

	public function testPausingReleasesFirmCommitments(): void {
		// 15 PT hart zugesagt → nur 5 frei: beide warten auf Dezember; Pausieren gibt die Zusage frei
		$hard = [50 => [['poolId' => 1, 'period' => '2026-11', 'personDays' => 15.0, 'level' => 'hard']]];
		$rows = $this->compare([['changeType' => 'pause', 'targetRef' => '50', 'parameters' => ['from' => '2026-11']]], ['hard' => $hard], 15.0);
		$this->assertSame('shifted', $rows[2]['base']['state']);
		$this->assertSame('earlier', $rows[2]['effect']);
		$this->assertSame('startable', $rows[2]['scenario']['state']);
		$this->assertSame(5.0 + 220.0, $this->last['pools'][0]['availableBase']);
	}

	public function testCapacityIncreaseFromAMonth(): void {
		// +1 FTE ab Januar: Projekt-Kapazität je Monat + Arbeitstage
		$rows = $this->compare([['changeType' => 'change_capacity', 'targetRef' => '1', 'parameters' => ['fte' => 1.0, 'from' => '2027-01']]]);
		$this->assertSame('unchanged', $rows[2]['effect']);
		$pool = $this->last['pools'][0];
		$extra = 0;
		foreach (CapacityService::periods('2027-01', 10) as $p) {
			$extra += CapacityService::weekdays($p);
		}
		$this->assertEqualsWithDelta($pool['availableBase'] + $extra, $pool['availableScenario'], 0.01);
	}

	public function testRankChangeAndShift(): void {
		$rows = $this->compare([
			['changeType' => 'change_rank', 'targetRef' => '3', 'parameters' => ['rank' => 1]],
			['changeType' => 'shift_start', 'targetRef' => '2', 'parameters' => ['months' => 2]],
		]);
		$this->assertSame(1, $rows[3]['rankScenario']);
		$this->assertSame('changed', $rows[3]['effect']);
		$this->assertSame('later', $rows[2]['effect']);
		$this->assertSame('2027-01', $rows[2]['scenario']['start']);
	}

	public function testBucketQuotaAndBudget(): void {
		// Budgetrahmen 1500 €: nur ein Vorhaben à 1000 € passt
		$rows = $this->compare([['changeType' => 'change_budget', 'targetRef' => 'B', 'parameters' => ['budget' => 1500.0]]]);
		$this->assertSame('falls', $rows[3]['effect']);
		$this->assertSame('budget', $rows[3]['scenario']['state']);
		$this->assertSame(1500.0, $this->last['buckets'][1]['budgetScenario']);
	}
}
