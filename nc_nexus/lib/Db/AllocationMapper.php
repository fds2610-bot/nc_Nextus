<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Allokationen (ALLOCATION, Kapitel 05): verbindliche Zusagen (hard) je Pool und Monat.
 */
class AllocationMapper {
	private const TABLE = 'nx_allocations';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return array<int, array<string, float>> Pool => Monat => Σ harte Zusagen */
	public function hardByPoolAndPeriod(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('pool_id', 'period')
			->selectAlias($qb->func()->sum('person_days'), 'days')
			->from(self::TABLE)
			->where($qb->expr()->eq('level', $qb->createNamedParameter('hard')))
			->groupBy('pool_id', 'period');
		$result = $qb->executeQuery();
		$sums = [];
		while ($r = $result->fetch()) {
			$sums[(int)$r['pool_id']][(string)$r['period']] = (float)$r['days'];
		}
		$result->closeCursor();
		return $sums;
	}

	/** @return list<array<string, mixed>> */
	public function forDemand(int $demandId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)))
			->orderBy('period', 'ASC');
		$result = $qb->executeQuery();
		$rows = [];
		while ($r = $result->fetch()) {
			$rows[] = ['poolId' => (int)$r['pool_id'], 'period' => (string)$r['period'], 'personDays' => (float)$r['person_days'], 'level' => (string)$r['level'], 'decisionId' => $r['decision_id'] === null ? null : (int)$r['decision_id']];
		}
		$result->closeCursor();
		return $rows;
	}

	public function insert(int $demandId, int $poolId, string $period, float $days, string $level, ?int $decisionId, string $userId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'demand_id' => $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT),
			'pool_id' => $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT),
			'period' => $qb->createNamedParameter($period),
			'person_days' => $qb->createNamedParameter($days),
			'level' => $qb->createNamedParameter($level),
			'decision_id' => $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT),
			'created_by' => $qb->createNamedParameter($userId),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/**
	 * Zusagen ab einem Monat freigeben (Abbruch, Abnahme): die Kapazität steht wieder zur Verfügung.
	 *
	 * @return float freigegebene Personentage
	 */
	public function releaseFrom(int $demandId, string $period): float {
		$released = 0.0;
		foreach ($this->forDemand($demandId) as $row) {
			if ($row['period'] >= $period) {
				$released += $row['personDays'];
			}
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('period', $qb->createNamedParameter($period)))
			->executeStatement();
		return $released;
	}

	public function countForPool(int $poolId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from(self::TABLE)
			->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery();
		$n = (int)$r->fetchOne();
		$r->closeCursor();
		return $n;
	}
}
