<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Schlanker Tabellenzugriff für die Governance-Tabellen der Stufe 3 (Gremien, Gates, Sitzungen …).
 *
 * Nach außen camelCase-Arrays; Ganzzahl-, Kommazahl- und JSON-Spalten werden umgewandelt, damit
 * MariaDB, PostgreSQL und SQLite dieselben Typen liefern.
 */
class Rows {
	/** Spalten mit JSON-Inhalt */
	private const JSON = ['required_seats', 'project_classes', 'demand_types', 'required_checks', 'completeness', 'options'];
	/** Kommazahlen */
	private const FLOAT = ['amount'];
	/** Ganzzahlen außer id, *_id und *_at */
	private const INT = ['valid_from', 'valid_to', 'quorum_min', 'active', 'voting', 'version', 'sort', 'budget_tranche', 'duration_min', 'position', 'conflict', 'blocking', 'fiscal_year'];
	/** Text-IDs von Konten, die wie *_id heißen, aber keine Zahlen sind */
	private const STRING_IDS = ['chair_id', 'user_id', 'deputy_id', 'member_id', 'owner_id'];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param array<string, mixed> $values camelCase
	 * @return int neue ID
	 */
	public function insert(string $table, array $values): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $key => $value) {
			$column = self::snake($key);
			$qb->setValue($column, $value === null
				? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
				: $qb->createNamedParameter(self::toDb($column, $value), self::isInt($column) || is_bool($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
		}
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	/** @param array<string, mixed> $values camelCase */
	public function update(string $table, int $id, array $values): void {
		if ($values === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($table);
		foreach ($values as $key => $value) {
			$column = self::snake($key);
			$qb->set($column, $value === null
				? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
				: $qb->createNamedParameter(self::toDb($column, $value), self::isInt($column) || is_bool($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function delete(string $table, int $id): void {
		$this->deleteWhere($table, ['id' => $id]);
	}

	/** @param array<string, int|string> $where camelCase */
	public function deleteWhere(string $table, array $where): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table);
		$this->conditions($qb, $where);
		$qb->executeStatement();
	}

	/** @return array<string, mixed>|null */
	public function find(string $table, int $id): ?array {
		return $this->where($table, ['id' => $id])[0] ?? null;
	}

	/**
	 * @param array<string, int|string|null|list<int>> $where camelCase; Listen als IN, null als IS NULL
	 * @return list<array<string, mixed>>
	 */
	public function where(string $table, array $where = [], string $orderBy = 'id'): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table);
		$this->conditions($qb, $where);
		$qb->orderBy(self::snake($orderBy), 'ASC')->addOrderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = self::fromDb($row);
		}
		$result->closeCursor();
		return $rows;
	}

	/** @param array<string, int|string|null|list<int>> $where */
	private function conditions(IQueryBuilder $qb, array $where): void {
		foreach ($where as $key => $value) {
			$column = self::snake($key);
			if ($value === null) {
				$qb->andWhere($qb->expr()->isNull($column));
			} elseif (is_array($value)) {
				$qb->andWhere($qb->expr()->in($column, $qb->createNamedParameter($value === [] ? [-1] : $value, IQueryBuilder::PARAM_INT_ARRAY)));
			} else {
				$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
			}
		}
	}

	private static function snake(string $key): string {
		return strtolower((string)preg_replace('/[A-Z]/', '_$0', $key));
	}

	private static function camel(string $column): string {
		return lcfirst(str_replace('_', '', ucwords($column, '_')));
	}

	private static function isInt(string $column): bool {
		return $column === 'id' || in_array($column, self::INT, true)
			|| (str_ends_with($column, '_id') && !in_array($column, self::STRING_IDS, true))
			|| str_ends_with($column, '_at');
	}

	private static function toDb(string $column, mixed $value): mixed {
		if (in_array($column, self::JSON, true)) {
			return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
		}
		if (is_bool($value)) {
			return $value ? 1 : 0;
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function fromDb(array $row): array {
		$out = [];
		foreach ($row as $column => $value) {
			$column = (string)$column;
			if ($value !== null) {
				if (in_array($column, self::JSON, true)) {
					$value = json_decode((string)$value, true);
				} elseif (in_array($column, self::FLOAT, true)) {
					$value = (float)$value;
				} elseif (self::isInt($column)) {
					$value = (int)$value;
				} else {
					$value = (string)$value;
				}
			}
			$out[self::camel($column)] = $value;
		}
		return $out;
	}
}
