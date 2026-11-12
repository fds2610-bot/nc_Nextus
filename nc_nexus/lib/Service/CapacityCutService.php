<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\AllocationMapper;
use OCA\Nexus\Db\Pool;
use OCA\Nexus\Db\PoolMapper;
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
	public const HORIZON = 12;
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

	/** @var array<int, float> einmalige Kosten je Antrag (Cache je Anfrage) */
	private array $costs = [];

	/**
	 * Startplan je Vorhaben (Schlüssel: Antrags-ID).
	 *
	 * @return array{horizon: list<string>, results: array<int, array<string, mixed>>, remaining: array<int, array<string, float>>}
	 * @throws ForbiddenException
	 */
	public function cut(string $userId): array {
		return $this->compute($userId, $this->input($userId));
	}

	/**
	 * Ausgangsdaten des Schnitts – Szenarien verändern eine Kopie davon (Kapitel 04 §5.1).
	 *
	 * @return array{from: string, periods: list<string>, buckets: list<array<string, mixed>>, pools: array<int, Pool>,
	 *               capacity: array<int, array<string, float>>, hard: array<int, array<string, float>>,
	 *               needs: array<int, list<array{poolId: int, period: string, personDays: float}>>}
	 * @throws ForbiddenException
	 */
	public function input(string $userId): array {
		$buckets = $this->ranking->ranking($userId);
		$from = date('Y-m', $this->time->getTime());
		$periods = CapacityService::periods($from, self::HORIZON);
		$pools = [];
		$capacity = [];
		foreach ($this->pools->findAll() as $pool) {
			if ($pool->getIsBottleneck() !== 1) {
				continue;
			}
			$pools[$pool->getId()] = $pool;
			foreach ($this->capacity->capacityRows($pool, $from, self::HORIZON) as $row) {
				$capacity[$pool->getId()][$row['period']] = (float)$row['project'];
			}
		}
		$needs = [];
		foreach ($this->needs->findRange('0000-00', '9999-12') as $need) {
			$needs[$need->getDemandId()][] = ['poolId' => $need->getPoolId(), 'period' => $need->getPeriod(), 'personDays' => $need->getPersonDays()];
		}
		return [
			'from' => $from,
			'periods' => $periods,
			'buckets' => $buckets,
			'pools' => $pools,
			'capacity' => $capacity,
			'hard' => $this->allocations->hardByPoolAndPeriod(),
			'needs' => $needs,
		];
	}

	/**
	 * Schnitt auf gegebenen Ausgangsdaten.
	 *
	 * @param array<string, mixed> $in siehe input()
	 * @return array{horizon: list<string>, results: array<int, array<string, mixed>>, remaining: array<int, array<string, float>>}
	 */
	public function compute(string $userId, array $in): array {
		$periods = $in['periods'];
		$codes = [];
		$free = [];
		foreach ($in['pools'] as $poolId => $pool) {
			$codes[$poolId] = $pool->getCode();
			foreach ($periods as $period) {
				// verfügbare Projekt-Kapazität abzüglich harter Zusagen
				$free[$poolId][$period] = max(0.0, ($in['capacity'][$poolId][$period] ?? 0.0) - ($in['hard'][$poolId][$period] ?? 0.0));
			}
		}
		$buckets = $in['buckets'];
		$needsOf = $in['needs'];

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
		$remaining = [];
		foreach ($free as $poolId => $months) {
			foreach ($months as $period => $days) {
				$remaining[$poolId][$period] = 0.0;
			}
		}
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
			foreach ($own as $poolId => $months) {
				foreach ($months as $period => $days) {
					$remaining[$poolId][$period] += $days;
				}
			}
		}
		foreach ($remaining as $poolId => $months) {
			foreach ($months as $period => $days) {
				$remaining[$poolId][$period] = round($days, 1);
			}
		}
		return ['horizon' => $periods, 'results' => $results, 'remaining' => $remaining];
	}

	/**
	 * Vorhaben einplanen: ohne oder mit Verschiebung; reserviert die Kapazität bei Erfolg.
	 *
	 * @param array<string, mixed> $item
	 * @param list<array{poolId: int, period: string, personDays: float}> $needs
	 * @param array<int, array<string, float>> $free verfügbare Tage je Pool und Monat (wird reduziert)
	 * @param list<string> $periods
	 * @param array<int, string> $codes
	 * @return array<string, mixed>
	 */
	private function place(array $item, array $needs, array &$free, array $periods, array $codes): array {
		$relevant = array_values(array_filter($needs, static fn (array $n) => isset($codes[$n['poolId']])));
		if ($relevant === []) {
			return ['state' => 'startable', 'shift' => 0, 'note' => 'no_bottleneck'];
		}
		$blocking = null;
		for ($shift = 0; $shift <= self::MAX_SHIFT; $shift++) {
			$fits = true;
			$wanted = [];
			foreach ($relevant as $need) {
				$period = self::shift($need['period'], $shift);
				// vor dem Horizont liegender Bedarf zählt im ersten Monat
				if ($period < $periods[0]) {
					$period = $periods[0];
				}
				if (!in_array($period, $periods, true)) {
					$fits = false;
					$blocking ??= ['pool' => $codes[$need['poolId']], 'period' => $period];
					break;
				}
				$key = $need['poolId'] . '|' . $period;
				$wanted[$key] = ($wanted[$key] ?? 0) + $need['personDays'];
				if ($wanted[$key] > ($free[$need['poolId']][$period] ?? 0.0) + 0.001) {
					$fits = false;
					if ($shift === 0) {
						$blocking = ['pool' => $codes[$need['poolId']], 'period' => $period];
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

	public function oneTimeCost(string $userId, int $demandId): float {
		if (!isset($this->costs[$demandId])) {
			try {
				$this->costs[$demandId] = (float)($this->businessCases->get($userId, $demandId)['kpis']['oneTimeCost'] ?? 0.0);
			} catch (\Throwable) {
				$this->costs[$demandId] = 0.0;
			}
		}
		return $this->costs[$demandId];
	}

	public static function shift(string $period, int $months): string {
		return (new \DateTimeImmutable($period . '-01'))->modify("+$months month")->format('Y-m');
	}
}
