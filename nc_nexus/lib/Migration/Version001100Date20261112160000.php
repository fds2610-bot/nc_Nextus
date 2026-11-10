<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Business Case (FIN-001) mit Nutzenpositionen (BEN-001): ein bearbeitbarer Entwurf je Antrag,
 * an Gates eingefrorene Versionen.
 */
class Version001100Date20261112160000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('nx_business_cases')) {
			$t = $schema->createTable('nx_business_cases');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('version', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 8, 'notnull' => true]);
			$t->addColumn('frozen_reason', Types::STRING, ['length' => 32, 'notnull' => false]);
			$t->addColumn('content', Types::TEXT, ['notnull' => true]);
			$t->addColumn('updated_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_business_cases_pk');
			$t->addIndex(['demand_id'], 'nx_bc_demand_ix');
		}
		return $schema;
	}
}
