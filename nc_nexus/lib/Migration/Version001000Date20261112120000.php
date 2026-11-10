<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Bewertung und Priorisierung (POR-002, GOV-003): Buckets, versionierter Kriterienkatalog,
 * unveränderliche Bewertungen; Bucket, Frist und Rang-Korrektur am Antrag.
 * Befüllt Buckets und Kriterienkatalog v1 mit dem Startmodell aus Kapitel 03.
 */
class Version001000Date20261112120000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('nx_buckets')) {
			$t = $schema->createTable('nx_buckets');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 4, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('target_share_pct', Types::FLOAT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('target_budget', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('scored', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'nx_buckets_pk');
			$t->addUniqueIndex(['code'], 'nx_buckets_code_uq');
		}

		if (!$schema->hasTable('nx_criterion_sets')) {
			$t = $schema->createTable('nx_criterion_sets');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_criterion_sets_pk');
		}

		if (!$schema->hasTable('nx_criteria')) {
			$t = $schema->createTable('nx_criteria');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('set_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 8, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('dimension', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('weight', Types::FLOAT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('sort', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('anchors', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'nx_criteria_pk');
			$t->addIndex(['set_id'], 'nx_criteria_set_ix');
		}

		if (!$schema->hasTable('nx_assessments')) {
			$t = $schema->createTable('nx_assessments');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('set_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['length' => 8, 'notnull' => true]);
			$t->addColumn('scores', Types::TEXT, ['notnull' => true]);
			$t->addColumn('effort', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('estimate_quality', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('comment', Types::TEXT, ['notnull' => false]);
			$t->addColumn('benefit', Types::FLOAT, ['notnull' => true]);
			$t->addColumn('urgency', Types::FLOAT, ['notnull' => true]);
			$t->addColumn('risk', Types::FLOAT, ['notnull' => true]);
			$t->addColumn('density', Types::FLOAT, ['notnull' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_assessments_pk');
			$t->addIndex(['demand_id'], 'nx_assessments_demand_ix');
		}

		$demands = $schema->getTable('nx_demands');
		foreach ([
			'bucket' => [Types::STRING, ['length' => 4]],
			'deadline' => [Types::STRING, ['length' => 10]],
			'rank_override' => [Types::INTEGER, []],
			'override_reason' => [Types::TEXT, []],
		] as $name => [$type, $opts]) {
			if (!$demands->hasColumn($name)) {
				$demands->addColumn($name, $type, ['notnull' => false] + $opts);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$count = $this->db->getQueryBuilder();
		$count->select($count->func()->count('*', 'n'))->from('nx_buckets');
		$r = $count->executeQuery();
		$hasBuckets = (int)$r->fetchOne() > 0;
		$r->closeCursor();
		if (!$hasBuckets) {
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

		$count = $this->db->getQueryBuilder();
		$count->select($count->func()->count('*', 'n'))->from('nx_criterion_sets');
		$r = $count->executeQuery();
		$hasSets = (int)$r->fetchOne() > 0;
		$r->closeCursor();
		if ($hasSets) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('nx_criterion_sets')->values([
			'version' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'name' => $qb->createNamedParameter('Kriterienkatalog'),
			'status' => $qb->createNamedParameter('active'),
			'note' => $qb->createNamedParameter('Startmodell aus dem Konzept (Kapitel 03)'),
			'created_by' => $qb->createNamedParameter('system'),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$setId = $qb->getLastInsertId();
		$sort = 0;
		foreach (self::defaultCriteria() as [$code, $name, $dimension, $weight, $anchors]) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('nx_criteria')->values([
				'set_id' => $qb->createNamedParameter($setId, IQueryBuilder::PARAM_INT),
				'code' => $qb->createNamedParameter($code),
				'name' => $qb->createNamedParameter($name),
				'dimension' => $qb->createNamedParameter($dimension),
				'weight' => $qb->createNamedParameter($weight),
				'sort' => $qb->createNamedParameter(++$sort, IQueryBuilder::PARAM_INT),
				'anchors' => $qb->createNamedParameter(json_encode(array_combine(['0', '1', '3', '5', '8'], $anchors), JSON_UNESCAPED_UNICODE)),
			])->executeStatement();
		}
	}

	/**
	 * Kriterien mit Ankertexten für 0/1/3/5/8 (Kapitel 03 §2).
	 *
	 * @return list<array{0: string, 1: string, 2: string, 3: int, 4: list<string>}>
	 */
	public static function defaultCriteria(): array {
		return [
			['N1', 'Strategiebeitrag', 'benefit', 25, ['kein Bezug', 'mittelbar', 'zahlt auf ein strategisches Ziel ein', 'wesentlicher Beitrag zu einem Ziel', 'Voraussetzung für ein strategisches Ziel']],
			['N2', 'Finanzieller Nutzen', 'benefit', 25, ['kein finanzieller Nutzen', 'unter 50 T€ pro Jahr', '50–200 T€ pro Jahr', '200–500 T€ pro Jahr', 'über 500 T€ pro Jahr']],
			['N3', 'Kunden-/Nutzernutzen', 'benefit', 15, ['keiner', 'einzelne Anwender', 'ein Fachbereich', 'mehrere Bereiche oder Endkunden', 'messbare Verbesserung der Kundenerfahrung im Kerngeschäft']],
			['N4', 'Risikominderung', 'benefit', 15, ['keine', 'geringes Restrisiko', 'mittleres operatives Risiko gemindert', 'hohes Risiko gemindert', 'existenzielles Risiko gemindert']],
			['N5', 'Mitarbeiterwirkung', 'benefit', 10, ['keine', 'kosmetisch', 'spürbare Entlastung', 'deutliche Entlastung mehrerer Teams', 'beseitigt einen belastenden Engpass']],
			['N6', 'Synergie / Enabler', 'benefit', 10, ['isoliert', 'geringe Wiederverwendung', 'ein Folgevorhaben profitiert', 'mehrere Folgevorhaben profitieren', 'Plattform, ohne die andere Vorhaben nicht startbar sind']],
			['Z1', 'Termindruck', 'urgency', 0, ['beliebig verschiebbar', 'wünschenswert im Jahr', 'Fenster durch Abhängigkeit', 'harte interne Zusage', 'gesetzliche oder vertragliche Frist mit Sanktion']],
			['Z2', 'Nutzenverfall', 'urgency', 0, ['kein Nutzenverfall', 'gering abnehmend', 'linear abnehmend', 'deutlich abnehmend', 'Nutzen entfällt vollständig (Marktfenster)']],
			['R1', 'Umsetzungsrisiko', 'risk', 0, ['erprobte Technik, erfahrenes Team', 'geringe Neuartigkeit', 'teilweise neu', 'weitgehend neu', 'unerprobte Technik, keine Erfahrung']],
			['R2', 'Abhängigkeitsrisiko', 'risk', 0, ['keine Vorbedingungen', 'eine unkritische Abhängigkeit', 'mehrere Abhängigkeiten', 'kritische Abhängigkeit von Dritten', 'mehrere kritische Abhängigkeiten']],
			['R3', 'Organisatorisches Risiko', 'risk', 0, ['kein Change-Bedarf', 'wenige Betroffene', 'ein Bereich muss umlernen', 'mehrere Bereiche, Widerstand erwartet', 'unternehmensweiter Change mit starkem Widerstand']],
			['R4', 'Sicherheits-/Compliance-Risiko', 'risk', 0, ['keines', 'gering', 'mittel, mit Standardmaßnahmen beherrschbar', 'hoch, eigene Maßnahmen nötig', 'sehr hoch, Freigabe durch Sicherheitsboard nötig']],
		];
	}
}
