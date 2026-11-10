<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ResourceNeed>
 */
class ResourceNeedMapper extends QBMapper {
	public const TABLE = 'nx_res_demand';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, ResourceNeed::class);
	}

	/** @return list<ResourceNeed> */
	public function findForDemand(int $demandId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)))
			->orderBy('period', 'ASC')->addOrderBy('pool_id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<ResourceNeed> */
	public function findRange(string $from, string $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->gte('period', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('period', $qb->createNamedParameter($to)));
		return $this->findEntities($qb);
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

	public function deleteForDemand(int $demandId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}
}
