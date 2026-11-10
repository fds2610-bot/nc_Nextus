<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<OrgUnit>
 */
class OrgUnitMapper extends QBMapper {
	public const TABLE = 'nx_org_units';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, OrgUnit::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): OrgUnit {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<OrgUnit> */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->orderBy('name', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}
}
