<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Demand>
 */
class DemandMapper extends QBMapper {
	public const TABLE = 'nx_demands';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Demand::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Demand {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<Demand>
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Höchste laufende Nummer der Schlüssel mit dem Präfix (z. B. „DEM-2026-“), 0 wenn keiner existiert.
	 */
	public function maxSequence(string $prefix): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('demand_key')
			->from(self::TABLE)
			->where($qb->expr()->like('demand_key', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%')));
		$max = 0;
		$result = $qb->executeQuery();
		while (($key = $result->fetchOne()) !== false) {
			$max = max($max, (int)substr((string)$key, strlen($prefix)));
		}
		$result->closeCursor();
		return $max;
	}
}
