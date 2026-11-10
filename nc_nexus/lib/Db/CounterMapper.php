<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Fortlaufende Zähler (nx_counters), z. B. für die Antragsschlüssel eines Jahres.
 */
class CounterMapper {
	private const TABLE = 'nx_counters';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Zähler erhöhen und den neuen Wert liefern. Fehlt der Zähler, beginnt er bei $floor + 1
	 * (Übernahme bestehender Daten). Innerhalb einer Transaktion aufrufen.
	 */
	public function next(string $name, int $floor = 0): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('value', $qb->func()->add('value', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		if ($qb->executeStatement() === 0) {
			try {
				$insert = $this->db->getQueryBuilder();
				$insert->insert(self::TABLE)->values([
					'name' => $insert->createNamedParameter($name),
					'value' => $insert->createNamedParameter($floor + 1, IQueryBuilder::PARAM_INT),
				])->executeStatement();
				return $floor + 1;
			} catch (DbException $e) {
				// Parallel angelegt: dann einfach erhöhen
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				return $this->next($name, $floor);
			}
		}
		$select = $this->db->getQueryBuilder();
		$select->select('value')->from(self::TABLE)
			->where($select->expr()->eq('name', $select->createNamedParameter($name)));
		$result = $select->executeQuery();
		$value = (int)$result->fetchOne();
		$result->closeCursor();
		return $value;
	}
}
