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
	 * Einträge eines Objekts, beginnend beim letzten „create“. Ältere Einträge gehören zu einem
	 * früheren Objekt mit derselben ID (SQLite setzt den Zähler beim Tabellenumbau zurück).
	 *
	 * @return list<AuditEntry>
	 */
	public function findForEntity(string $entityType, int $entityId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
			->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		$entries = $this->findEntities($qb);
		$start = 0;
		foreach ($entries as $i => $entry) {
			if ($entry->getAction() === 'create') {
				$start = $i;
			}
		}
		return array_slice($entries, $start);
	}
}
