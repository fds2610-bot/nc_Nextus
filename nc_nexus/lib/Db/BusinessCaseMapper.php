<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Business Cases: ein Entwurf je Antrag (status draft), eingefrorene Versionen (frozen).
 */
class BusinessCaseMapper {
	private const TABLE = 'nx_business_cases';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Alle Versionen, älteste zuerst; optional nur eines Antrags.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function versions(?int $demandId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->orderBy('demand_id', 'ASC')->addOrderBy('version', 'ASC');
		if ($demandId !== null) {
			$qb->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$rows = [];
		while ($r = $result->fetch()) {
			$rows[] = [
				'id' => (int)$r['id'],
				'demandId' => (int)$r['demand_id'],
				'version' => (int)$r['version'],
				'status' => (string)$r['status'],
				'frozenReason' => $r['frozen_reason'],
				'content' => json_decode((string)$r['content'], true) ?: [],
				'updatedBy' => (string)$r['updated_by'],
				'createdAt' => (int)$r['created_at'],
				'updatedAt' => (int)$r['updated_at'],
			];
		}
		$result->closeCursor();
		return $rows;
	}

	/** @param array<string, mixed> $content */
	public function insert(int $demandId, int $version, string $status, ?string $reason, array $content, string $userId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'demand_id' => $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT),
			'version' => $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT),
			'status' => $qb->createNamedParameter($status),
			'frozen_reason' => $qb->createNamedParameter($reason),
			'content' => $qb->createNamedParameter(json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
			'updated_by' => $qb->createNamedParameter($userId),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/** @param array<string, mixed> $content */
	public function updateDraft(int $id, array $content, string $userId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('content', $qb->createNamedParameter(json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))
			->set('updated_by', $qb->createNamedParameter($userId))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('draft')))
			->executeStatement();
	}

	public function deleteForDemand(int $demandId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}
}
