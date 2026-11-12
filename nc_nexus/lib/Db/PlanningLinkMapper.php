<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Verknüpfungen Vorhaben ↔ Projekt im Planungstool.
 */
class PlanningLinkMapper {
	private const TABLE = 'nx_planning_links';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return array<string, mixed>|null */
	public function find(int $demandId, string $provider): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('provider', $qb->createNamedParameter($provider)));
		$result = $qb->executeQuery();
		$r = $result->fetch();
		$result->closeCursor();
		if ($r === false) {
			return null;
		}
		return [
			'id' => (int)$r['id'],
			'demandId' => (int)$r['demand_id'],
			'provider' => (string)$r['provider'],
			'externalId' => (string)$r['external_id'],
			'title' => $r['title'],
			'url' => $r['url'],
			'syncStatus' => (string)$r['sync_status'],
			'summary' => $r['summary'] === null ? null : json_decode((string)$r['summary'], true),
			'syncedAt' => $r['synced_at'] === null ? null : (int)$r['synced_at'],
			'createdBy' => (string)$r['created_by'],
			'createdAt' => (int)$r['created_at'],
		];
	}

	/**
	 * Verknüpfung eines Vorhabens, gleich mit welchem Planungstool (je Vorhaben höchstens eine).
	 *
	 * @return array<string, mixed>|null
	 */
	public function findByDemand(int $demandId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('provider')->from(self::TABLE)
			->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$provider = $result->fetchOne();
		$result->closeCursor();
		return $provider === false ? null : $this->find($demandId, (string)$provider);
	}

	/** Ziel einer Verknüpfung ersetzen (neuer Datei-Schnappschuss) */
	public function updateTarget(int $id, string $externalId, string $title, string $url): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('external_id', $qb->createNamedParameter($externalId))
			->set('title', $qb->createNamedParameter($title))
			->set('url', $qb->createNamedParameter($url))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function insert(int $demandId, string $provider, string $externalId, string $title, string $url, string $userId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'demand_id' => $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT),
			'provider' => $qb->createNamedParameter($provider),
			'external_id' => $qb->createNamedParameter($externalId),
			'title' => $qb->createNamedParameter($title),
			'url' => $qb->createNamedParameter($url),
			'sync_status' => $qb->createNamedParameter('new'),
			'created_by' => $qb->createNamedParameter($userId),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/** @param array<string, mixed>|null $summary */
	public function updateSync(int $id, string $status, ?array $summary, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('sync_status', $qb->createNamedParameter($status))
			->set('synced_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		if ($summary !== null) {
			$qb->set('summary', $qb->createNamedParameter(json_encode($summary, JSON_THROW_ON_ERROR)));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function delete(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}
}
