<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Comment>
 */
class CommentMapper extends QBMapper {
	public const TABLE = 'nx_comments';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Comment::class);
	}

	/**
	 * Kommentare eines Antrags, älteste zuerst; nur ab dem Anlegen des Antrags (eine auf SQLite
	 * neu vergebene ID darf keine Kommentare eines gelöschten Entwurfs erben).
	 *
	 * @return list<Comment>
	 */
	public function findForDemand(int $demandId, int $since): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** Kommentare eines gelöschten Entwurfs entfernen. */
	public function deleteForDemand(int $demandId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
