<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Ressourcenpools, Kapazität je Monat und Bedarf je Antrag (POR-004/RES-001, Kapitel 04).
 */
class Version000800Date20261111160000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('nx_pools')) {
			$t = $schema->createTable('nx_pools');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('pool_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('org_unit_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('manager_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('is_bottleneck', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('key_persons', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('wip_limit', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('skills', Types::TEXT, ['notnull' => false]);
			// Quoten in Prozent (Kapitel 04 §2)
			$t->addColumn('absence_pct', Types::FLOAT, ['notnull' => true, 'default' => 16]);
			$t->addColumn('line_pct', Types::FLOAT, ['notnull' => true, 'default' => 30]);
			$t->addColumn('overhead_pct', Types::FLOAT, ['notnull' => true, 'default' => 12]);
			$t->addColumn('reserve_pct', Types::FLOAT, ['notnull' => true, 'default' => 5]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_pools_pk');
			$t->addUniqueIndex(['code'], 'nx_pools_code_uq');
		}

		if (!$schema->hasTable('nx_pool_capacity')) {
			$t = $schema->createTable('nx_pool_capacity');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('period', Types::STRING, ['length' => 7, 'notnull' => true]);
			$t->addColumn('fte', Types::FLOAT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('working_days', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('override_days', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('override_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('updated_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_pool_capacity_pk');
			$t->addUniqueIndex(['pool_id', 'period'], 'nx_pool_cap_period_uq');
		}

		if (!$schema->hasTable('nx_res_demand')) {
			$t = $schema->createTable('nx_res_demand');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('period', Types::STRING, ['length' => 7, 'notnull' => true]);
			$t->addColumn('person_days', Types::FLOAT, ['notnull' => true]);
			$t->addColumn('source', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('updated_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_res_demand_pk');
			$t->addUniqueIndex(['demand_id', 'pool_id', 'period'], 'nx_res_demand_uq');
			$t->addIndex(['pool_id', 'period'], 'nx_res_demand_pool_ix');
		}

		return $schema;
	}
}
