<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RoleAssignment>
 */
class RoleAssignmentMapper extends QBMapper {
	public const TABLE = 'nx_role_assign';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, RoleAssignment::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): RoleAssignment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<RoleAssignment> */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Zuweisungen an das Konto selbst oder an eine seiner Gruppen.
	 *
	 * @param list<string> $groupIds
	 * @return list<RoleAssignment>
	 */
	public function findForPrincipal(string $userId, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();
		$or = [$qb->expr()->andX(
			$qb->expr()->eq('principal_type', $qb->createNamedParameter('user')),
			$qb->expr()->eq('principal_id', $qb->createNamedParameter($userId)),
		)];
		if ($groupIds !== []) {
			$or[] = $qb->expr()->andX(
				$qb->expr()->eq('principal_type', $qb->createNamedParameter('group')),
				$qb->expr()->in('principal_id', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
			);
		}
		$qb->select('*')->from(self::TABLE)->where($qb->expr()->orX(...$or));
		return $this->findEntities($qb);
	}

	public function countForScope(string $scopeType, int $scopeId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from(self::TABLE)
			->where($qb->expr()->eq('scope_type', $qb->createNamedParameter($scopeType)))
			->andWhere($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
