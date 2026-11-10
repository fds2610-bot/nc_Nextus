<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Strategische Ziele (STR-001): Zielhierarchie und M:N-Zuordnung zu Anträgen.
 */
class Version000900Date20261112080000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('nx_objectives')) {
			$t = $schema->createTable('nx_objectives');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('parent_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('owner_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('target_metric', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('valid_from', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('valid_to', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('version', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_objectives_pk');
			$t->addUniqueIndex(['code'], 'nx_objectives_code_uq');
		}
		if (!$schema->hasTable('nx_demand_obj')) {
			$t = $schema->createTable('nx_demand_obj');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('objective_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_demand_obj_pk');
			$t->addUniqueIndex(['demand_id', 'objective_id'], 'nx_demand_obj_uq');
			$t->addIndex(['objective_id'], 'nx_demand_obj_obj_ix');
		}
		return $schema;
	}
}
