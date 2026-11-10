<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Objective>
 */
class ObjectiveMapper extends QBMapper {
	public const TABLE = 'nx_objectives';
	private const LINK = 'nx_demand_obj';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Objective::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Objective {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<Objective> */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->orderBy('code', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<array{demandId: int, objectiveId: int, createdAt: int}> alle Zuordnungen */
	public function links(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('demand_id', 'objective_id', 'created_at')->from(self::LINK);
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = ['demandId' => (int)$row['demand_id'], 'objectiveId' => (int)$row['objective_id'], 'createdAt' => (int)$row['created_at']];
		}
		$result->closeCursor();
		return $rows;
	}

	/** @param list<int> $objectiveIds */
	public function replaceLinks(int $demandId, array $objectiveIds, int $now): void {
		$this->deleteLinksOfDemand($demandId);
		foreach (array_unique($objectiveIds) as $objectiveId) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::LINK)->values([
				'demand_id' => $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT),
				'objective_id' => $qb->createNamedParameter($objectiveId, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		}
	}

	public function deleteLinksOfDemand(int $demandId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::LINK)->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}
}
