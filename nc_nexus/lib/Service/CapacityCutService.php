<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\AllocationMapper;
use OCA\Nexus\Db\Pool;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\ResourceNeed;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Kapazitätsschnitt (Kapitel 04 §5): von der Rangliste zum Startplan.
 *
 *   1. Bedarf von Bucket A (Pflicht) zuerst abziehen
 *   2. verbindliche Zusagen (hard) freigegebener Vorhaben abziehen
 *   3. Restkapazität gemäß Zielquoten auf B, C, D verteilen
 *   4. je Bucket die Rangliste durchgehen: passt der Bedarf in allen Engpass-Pools in allen
 *      benötigten Monaten? ja → startfähig; sonst Start um 1 … 6 Monate verschieben;
 *      sonst „wartet auf Pool X“; reicht der Budgetrahmen des Buckets nicht → „wartet auf Budget“
 *
 * Geprüft werden nur Engpass-Pools – sie bestimmen die Governance-Härte (Kapitel 04 §1).
 */
class CapacityCutService {
	private const HORIZON = 12;
	private const MAX_SHIFT = 6;

	public function __construct(
		private RankingService $ranking,
		private CapacityService $capacity,
		private PoolMapper $pools,
		private ResourceNeedMapper $needs,
		private AllocationMapper $allocations,
		private BusinessCaseService $businessCases,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Startplan je Vorhaben (Schlüssel: Antrags-ID).
	 *
	 * @return array{horizon: list<string>, results: array<int, array<string, mixed>>}
	 * @throws ForbiddenException
	 */
	public function cut(string $userId): array {
		$buckets = $this->ranking->ranking($userId);
		$from = date('Y-m', $this->time->getTime());
		$periods = CapacityService::periods($from, self::HORIZON);
		$bottlenecks = array_filter($this->pools->findAll(), static fn (Pool $p) => $p->getIsBottleneck() === 1);

		// verfügbare Projekt-Kapazität je Engpass-Pool und Monat, abzüglich harter Zusagen
		$hard = $this->allocations->hardByPoolAndPeriod();
		$free = [];
		foreach ($bottlenecks as $pool) {
			foreach ($this->capacity->capacityRows($pool, $from, self::HORIZON) as $row) {
				$free[$pool->getId()][$row['period']] = max(0.0, $row['project'] - ($hard[$pool->getId()][$row['period']] ?? 0.0));
			}
		}
		$codes = [];
		foreach ($bottlenecks as $pool) {
			$codes[$pool->getId()] = $pool->getCode();
		}

		$needsOf = [];
		foreach ($this->needs->findRange('0000-00', '9999-12') as $need) {
			$needsOf[$need->getDemandId()][] = $need;
		}

		$results = [];
		// 1. Pflicht-Bucket zuerst aus der Gesamtkapazität
		foreach ($buckets as $bucket) {
			if (!$bucket['scored']) {
				foreach ($bucket['items'] as $item) {
					$results[$item['id']] = $this->place($item, $needsOf[$item['id']] ?? [], $free, $periods, $codes);
				}
			}
		}
		// 3. Rest nach Zielquoten auf die übrigen Buckets verteilen
		$scored = array_values(array_filter($buckets, static fn (array $b) => $b['scored']));
		$shareSum = array_sum(array_map(static fn (array $b) => $b['targetSharePct'], $scored)) ?: 1.0;
		foreach ($scored as $bucket) {
			$share = $bucket['targetSharePct'] / $shareSum;
			$own = [];
			foreach ($free as $poolId => $months) {
				foreach ($months as $period => $days) {
					$own[$poolId][$period] = $days * $share;
				}
			}
			$budget = $bucket['targetBudget'];
			foreach ($bucket['items'] as $item) {
				if ($item['assessment'] === null) {
					$results[$item['id']] = ['state' => 'unassessed'];
					continue;
				}
				// Budgetrahmen des Buckets (einmalige Kosten aus dem Business Case)
				$cost = $this->oneTimeCost($userId, $item['id']);
				if ($budget !== null && $cost > $budget) {
					$results[$item['id']] = ['state' => 'budget', 'cost' => $cost, 'budgetLeft' => round($budget, 2)];
					continue;
				}
				$result = $this->place($item, $needsOf[$item['id']] ?? [], $own, $periods, $codes);
				if ($budget !== null && in_array($result['state'], ['startable', 'shifted'], true)) {
					$budget -= $cost;
				}
				$results[$item['id']] = $result;
			}
		}
		return ['horizon' => $periods, 'results' => $results];
	}

	/**
	 * Vorhaben einplanen: ohne oder mit Verschiebung; reserviert die Kapazität bei Erfolg.
	 *
	 * @param array<string, mixed> $item
	 * @param list<ResourceNeed> $needs
	 * @param array<int, array<string, float>> $free verfügbare Tage je Pool und Monat (wird reduziert)
	 * @param list<string> $periods
	 * @param array<int, string> $codes
	 * @return array<string, mixed>
	 */
	private function place(array $item, array $needs, array &$free, array $periods, array $codes): array {
		$relevant = array_values(array_filter($needs, static fn (ResourceNeed $n) => isset($codes[$n->getPoolId()])));
		if ($relevant === []) {
			return ['state' => 'startable', 'shift' => 0, 'note' => 'no_bottleneck'];
		}
		$blocking = null;
		for ($shift = 0; $shift <= self::MAX_SHIFT; $shift++) {
			$fits = true;
			$wanted = [];
			foreach ($relevant as $need) {
				$period = self::shift($need->getPeriod(), $shift);
				// vor dem Horizont liegender Bedarf zählt im ersten Monat
				if ($period < $periods[0]) {
					$period = $periods[0];
				}
				if (!in_array($period, $periods, true)) {
					$fits = false;
					$blocking ??= ['pool' => $codes[$need->getPoolId()], 'period' => $period];
					break;
				}
				$key = $need->getPoolId() . '|' . $period;
				$wanted[$key] = ($wanted[$key] ?? 0) + $need->getPersonDays();
				if ($wanted[$key] > ($free[$need->getPoolId()][$period] ?? 0.0) + 0.001) {
					$fits = false;
					if ($shift === 0) {
						$blocking = ['pool' => $codes[$need->getPoolId()], 'period' => $period];
					}
					break;
				}
			}
			if ($fits) {
				foreach ($wanted as $key => $days) {
					[$poolId, $period] = explode('|', $key);
					$free[(int)$poolId][$period] -= $days;
				}
				$start = min(array_map(static fn (string $k) => explode('|', $k)[1], array_keys($wanted)));
				return ['state' => $shift === 0 ? 'startable' : 'shifted', 'shift' => $shift, 'start' => $start];
			}
		}
		return ['state' => 'waiting', 'pool' => $blocking['pool'] ?? null, 'period' => $blocking['period'] ?? null];
	}

	private function oneTimeCost(string $userId, int $demandId): float {
		try {
			return (float)($this->businessCases->get($userId, $demandId)['kpis']['oneTimeCost'] ?? 0.0);
		} catch (\Throwable) {
			return 0.0;
		}
	}

	public static function shift(string $period, int $months): string {
		return (new \DateTimeImmutable($period . '-01'))->modify("+$months month")->format('Y-m');
	}
}
