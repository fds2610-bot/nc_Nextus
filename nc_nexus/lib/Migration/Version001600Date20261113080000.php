<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use Doctrine\DBAL\Schema\Table;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Stufe 5 – Nutzenreviews (BEN-002, Kapitel 03 §4.1, Kapitel 05 BENEFIT_REVIEW): laufende Prognose und
 * Realisierungsstatus je Nutzenposition, Messzeitpunkte und Reviews mit Ist-Wert und Abweichungsanalyse.
 */
class Version001600Date20261113080000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('nx_benefit_tracking')) {
			$t = $schema->createTable('nx_benefit_tracking');
			$this->id($t);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('benefit_key', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('forecast', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('forecast_amount', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('realization_status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('review_dates', Types::TEXT, ['notnull' => false]);
			$t->addColumn('updated_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$this->created($t);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_benefit_trk_pk');
			$t->addIndex(['demand_id'], 'nx_benefit_trk_demand');
		}

		if (!$schema->hasTable('nx_benefit_reviews')) {
			$t = $schema->createTable('nx_benefit_reviews');
			$this->id($t);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('benefit_key', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('review_date', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('actual_value', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('actual_amount', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('planned_amount', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('achievement_pct', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('assessment', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('explanation', Types::TEXT, ['notnull' => false]);
			$t->addColumn('lessons_learned', Types::TEXT, ['notnull' => false]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_benefit_rev_pk');
			$t->addIndex(['demand_id'], 'nx_benefit_rev_demand');
		}
		return $schema;
	}

	/** @param Table $t (unter NC 35 eine Hülle mit derselben Schnittstelle) */
	private function id($t): void {
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
	}

	/** @param Table $t */
	private function created($t): void {
		$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
		$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
	}
}
