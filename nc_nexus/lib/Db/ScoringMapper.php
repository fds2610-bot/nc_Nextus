<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Datenzugriff für Buckets, Kriterienkataloge und Bewertungen (Kapitel 03). Bewertungen und
 * Katalogversionen werden nur angefügt, nie geändert.
 */
class ScoringMapper {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Startmodell anlegen, falls Buckets oder Kriterienkatalog fehlen (Neuinstallation: Nextcloud
	 * führt dort nur das Schema der Migrationen aus).
	 */
	public function ensureDefaults(): void {
		if ($this->count('nx_buckets') === 0) {
			foreach ([
				['A', 'Pflicht', 'Gesetz, Regulatorik, Vertrag, Audit-Findings, akute Sicherheitslücken', 20, 0],
				['B', 'Betrieb & Lifecycle', 'End-of-Life-Ablösungen, technische Schulden, Stabilität, Skalierung', 25, 1],
				['C', 'Wachstum & Effizienz', 'Umsatzsteigerung, Kostensenkung, Prozessautomatisierung', 45, 1],
				['D', 'Innovation & Exploration', 'Neue Geschäftsmodelle, Technologie-Erprobung, unsichere Wetten', 10, 1],
			] as [$code, $name, $description, $share, $scored]) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('nx_buckets')->values([
					'code' => $qb->createNamedParameter($code),
					'name' => $qb->createNamedParameter($name),
					'description' => $qb->createNamedParameter($description),
					'target_share_pct' => $qb->createNamedParameter($share),
					'scored' => $qb->createNamedParameter($scored, IQueryBuilder::PARAM_INT),
				])->executeStatement();
			}
		}
		if ($this->count('nx_criterion_sets') === 0) {
			$criteria = array_map(static fn (array $c) => [
				'code' => $c[0], 'name' => $c[1], 'dimension' => $c[2], 'weight' => (float)$c[3],
				'anchors' => array_combine(['0', '1', '3', '5', '8'], $c[4]),
			], \OCA\Nexus\Migration\Version001000Date20261112120000::defaultCriteria());
			$this->insertSet(1, 'Kriterienkatalog', 'Startmodell aus dem Konzept (Kapitel 03)', 'system', time(), $criteria);
		}
	}

	private function count(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($table);
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	/** @return list<array<string, mixed>> */
	public function buckets(): array {
		$this->ensureDefaults();
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('nx_buckets')->orderBy('code', 'ASC');
		return array_map(static fn (array $r) => [
			'id' => (int)$r['id'],
			'code' => (string)$r['code'],
			'name' => (string)$r['name'],
			'description' => $r['description'],
			'targetSharePct' => (float)$r['target_share_pct'],
			'targetBudget' => $r['target_budget'] === null ? null : (float)$r['target_budget'],
			'scored' => (int)$r['scored'] === 1,
		], $this->rows($qb));
	}

	public function updateBucket(string $code, float $share, ?float $budget): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('nx_buckets')
			->set('target_share_pct', $qb->createNamedParameter($share))
			->set('target_budget', $qb->createNamedParameter($budget))
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
			->executeStatement();
	}

	/** @return list<array<string, mixed>> alle Katalogversionen, neueste zuerst */
	public function sets(): array {
		$this->ensureDefaults();
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('nx_criterion_sets')->orderBy('version', 'DESC');
		return array_map(static fn (array $r) => [
			'id' => (int)$r['id'],
			'version' => (int)$r['version'],
			'name' => (string)$r['name'],
			'status' => (string)$r['status'],
			'note' => $r['note'],
			'createdBy' => (string)$r['created_by'],
			'createdAt' => (int)$r['created_at'],
		], $this->rows($qb));
	}

	/** @return list<array<string, mixed>> */
	public function criteria(int $setId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('nx_criteria')
			->where($qb->expr()->eq('set_id', $qb->createNamedParameter($setId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort', 'ASC');
		return array_map(static fn (array $r) => [
			'code' => (string)$r['code'],
			'name' => (string)$r['name'],
			'dimension' => (string)$r['dimension'],
			'weight' => (float)$r['weight'],
			'anchors' => json_decode((string)$r['anchors'], true) ?: [],
		], $this->rows($qb));
	}

	/**
	 * Neue Katalogversion anlegen und aktiv schalten; die bisher aktive wird abgelöst.
	 *
	 * @param list<array<string, mixed>> $criteria
	 */
	public function insertSet(int $version, string $name, ?string $note, string $userId, int $now, array $criteria): int {
		$retire = $this->db->getQueryBuilder();
		$retire->update('nx_criterion_sets')
			->set('status', $retire->createNamedParameter('retired'))
			->where($retire->expr()->eq('status', $retire->createNamedParameter('active')))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('nx_criterion_sets')->values([
			'version' => $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT),
			'name' => $qb->createNamedParameter($name),
			'status' => $qb->createNamedParameter('active'),
			'note' => $qb->createNamedParameter($note),
			'created_by' => $qb->createNamedParameter($userId),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$setId = $qb->getLastInsertId();
		$sort = 0;
		foreach ($criteria as $c) {
			$ins = $this->db->getQueryBuilder();
			$ins->insert('nx_criteria')->values([
				'set_id' => $ins->createNamedParameter($setId, IQueryBuilder::PARAM_INT),
				'code' => $ins->createNamedParameter($c['code']),
				'name' => $ins->createNamedParameter($c['name']),
				'dimension' => $ins->createNamedParameter($c['dimension']),
				'weight' => $ins->createNamedParameter($c['weight']),
				'sort' => $ins->createNamedParameter(++$sort, IQueryBuilder::PARAM_INT),
				'anchors' => $ins->createNamedParameter(json_encode($c['anchors'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
			])->executeStatement();
		}
		return $setId;
	}

	/** @param array<string, mixed> $a */
	public function insertAssessment(array $a): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('nx_assessments')->values([
			'demand_id' => $qb->createNamedParameter($a['demandId'], IQueryBuilder::PARAM_INT),
			'set_id' => $qb->createNamedParameter($a['setId'], IQueryBuilder::PARAM_INT),
			'kind' => $qb->createNamedParameter($a['kind']),
			'scores' => $qb->createNamedParameter(json_encode($a['scores'], JSON_THROW_ON_ERROR)),
			'effort' => $qb->createNamedParameter($a['effort'], IQueryBuilder::PARAM_INT),
			'estimate_quality' => $qb->createNamedParameter($a['estimateQuality']),
			'comment' => $qb->createNamedParameter($a['comment']),
			'benefit' => $qb->createNamedParameter($a['benefit']),
			'urgency' => $qb->createNamedParameter($a['urgency']),
			'risk' => $qb->createNamedParameter($a['risk']),
			'density' => $qb->createNamedParameter($a['density']),
			'created_by' => $qb->createNamedParameter($a['createdBy']),
			'created_at' => $qb->createNamedParameter($a['createdAt'], IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return $qb->getLastInsertId();
	}

	/**
	 * Bewertungen, älteste zuerst; optional nur eines Antrags.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function assessments(?int $demandId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('nx_assessments')->orderBy('id', 'ASC');
		if ($demandId !== null) {
			$qb->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)));
		}
		return array_map(static fn (array $r) => [
			'id' => (int)$r['id'],
			'demandId' => (int)$r['demand_id'],
			'setId' => (int)$r['set_id'],
			'kind' => (string)$r['kind'],
			'scores' => json_decode((string)$r['scores'], true) ?: [],
			'effort' => (int)$r['effort'],
			'estimateQuality' => (string)$r['estimate_quality'],
			'comment' => $r['comment'],
			'benefit' => (float)$r['benefit'],
			'urgency' => (float)$r['urgency'],
			'risk' => (float)$r['risk'],
			'density' => (float)$r['density'],
			'createdBy' => (string)$r['created_by'],
			'createdAt' => (int)$r['created_at'],
		], $this->rows($qb));
	}

	public function deleteAssessmentsOfDemand(int $demandId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('nx_assessments')->where($qb->expr()->eq('demand_id', $qb->createNamedParameter($demandId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** @return list<array<string, mixed>> */
	private function rows(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}
}
