<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<AuditEntry>
 */
class AuditMapper extends QBMapper {
	public const TABLE = 'nx_audit_log';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, AuditEntry::class);
	}

	/**
	 * @return list<AuditEntry>
	 */
	public function findForEntity(string $entityType, int $entityId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
			->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}
}
