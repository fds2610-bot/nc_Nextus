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
		return self::row($r);
	}

	/**
	 * Alle Verknüpfungen (für die Rollenübersicht).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = [];
		while ($r = $result->fetch()) {
			$rows[] = self::row($r);
		}
		$result->closeCursor();
		return $rows;
	}

	/** @return array{key: string, params: list<string>}|null */
	private static function error(mixed $raw): ?array {
		if (!is_string($raw) || $raw === '') {
			return null;
		}
		$e = json_decode($raw, true);
		return is_array($e) && isset($e['key']) ? ['key' => (string)$e['key'], 'params' => array_values(array_map('strval', (array)($e['params'] ?? [])))] : null;
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	private static function row(array $r): array {
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
			'syncError' => self::error($r['sync_error'] ?? null),
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

	/**
	 * @param array<string, mixed>|null $summary null = letzten Stand behalten
	 * @param array{key: string, params: list<string>}|null $error Meldung bei Status „error“
	 */
	public function updateSync(int $id, string $status, ?array $summary, int $now, ?array $error = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('sync_status', $qb->createNamedParameter($status))
			->set('synced_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('sync_error', $qb->createNamedParameter($error === null ? null : mb_substr(json_encode($error, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 0, 1024)));
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
