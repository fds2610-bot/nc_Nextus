<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<PoolCapacity>
 */
class PoolCapacityMapper extends QBMapper {
	public const TABLE = 'nx_pool_capacity';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, PoolCapacity::class);
	}

	/** @throws DoesNotExistException */
	public function findFor(int $poolId, string $period): PoolCapacity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('period', $qb->createNamedParameter($period)));
		return $this->findEntity($qb);
	}

	/** @return list<PoolCapacity> Monate von $from bis $to (JJJJ-MM, inklusive) */
	public function findRange(string $from, string $to, ?int $poolId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->gte('period', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('period', $qb->createNamedParameter($to)));
		if ($poolId !== null) {
			$qb->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}

	public function deleteForPool(int $poolId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}
}
