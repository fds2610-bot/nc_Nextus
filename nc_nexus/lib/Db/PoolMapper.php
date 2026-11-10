<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Pool>
 */
class PoolMapper extends QBMapper {
	public const TABLE = 'nx_pools';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Pool::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Pool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<Pool> */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->orderBy('name', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}
}
