<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Nur Lesen und Anfügen – Beschlüsse werden nie geändert oder gelöscht.
 *
 * @template-extends QBMapper<Decision>
 */
class DecisionMapper extends QBMapper {
	public const TABLE = 'nx_decisions';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Decision::class);
	}

	/** @return list<Decision> neueste zuerst */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** Letzter Beschluss zu einem Gegenstand (für supersedes). */
	public function findLatest(string $subjectType, int $subjectId, int $since): ?Decision {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('subject_type', $qb->createNamedParameter($subjectType)))
			->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($subjectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('decided_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$found = $this->findEntities($qb);
		return $found[0] ?? null;
	}
}
