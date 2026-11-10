<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Organisationsmodell (GOV-001/002): Org-Einheiten, kontextgebundene Rollen, Org-Einheit am Antrag.
 */
class Version000200Date20261110120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('nx_org_units')) {
			$table = $schema->createTable('nx_org_units');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('parent_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('cost_center', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('head_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id'], 'nx_org_units_pk');
			$table->addIndex(['parent_id'], 'nx_org_units_parent_ix');
		}

		if (!$schema->hasTable('nx_role_assign')) {
			$table = $schema->createTable('nx_role_assign');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('role', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('scope_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('scope_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('principal_type', Types::STRING, ['length' => 8, 'notnull' => true]);
			$table->addColumn('principal_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('valid_from', Types::STRING, ['length' => 10, 'notnull' => false]);
			$table->addColumn('valid_to', Types::STRING, ['length' => 10, 'notnull' => false]);
			$table->addColumn('deputy_for', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id'], 'nx_role_assign_pk');
			$table->addIndex(['principal_type', 'principal_id'], 'nx_role_assign_princ_ix');
			$table->addIndex(['scope_type', 'scope_id'], 'nx_role_assign_scope_ix');
		}

		$demands = $schema->getTable('nx_demands');
		if (!$demands->hasColumn('org_unit_id')) {
			$demands->addColumn('org_unit_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$demands->addIndex(['org_unit_id'], 'nx_demands_org_ix');
		}

		return $schema;
	}
}
