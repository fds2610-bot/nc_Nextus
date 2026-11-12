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
 * Stufe 5 – Szenarioanalyse (POR-003, Kapitel 04 §5.1, Kapitel 05 SCENARIO/SCENARIO_CHANGE): benannte
 * Was-wäre-wenn-Varianten des Portfolios als Liste von Änderungen; das Ergebnis wird beim Vorlegen
 * eingefroren.
 */
class Version001700Date20261113120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('nx_scenarios')) {
			$t = $schema->createTable('nx_scenarios');
			$this->id($t);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('base_snapshot_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('snapshot', Types::TEXT, ['notnull' => false]);
			$t->addColumn('decision_note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('decided_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('decided_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$this->created($t);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_scenarios_pk');
		}

		if (!$schema->hasTable('nx_scenario_changes')) {
			$t = $schema->createTable('nx_scenario_changes');
			$this->id($t);
			$t->addColumn('scenario_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('change_type', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('target_ref', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('parameters', Types::TEXT, ['notnull' => false]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_scenario_chg_pk');
			$t->addIndex(['scenario_id'], 'nx_scenario_chg_scen');
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
