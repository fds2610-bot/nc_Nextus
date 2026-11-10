<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Grundschema Stufe 1: Anträge (DEMAND) und fachliches Audit-Log (AUDIT_LOG).
 *
 * Regeln (Kapitel 07, §6): Tabellennamen mit Präfix höchstens 30 Zeichen, Primärschlüssel
 * und Indizes immer explizit benennen. Läuft unter SQLite, PostgreSQL und MySQL/MariaDB.
 */
class Version000100Date20261110000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('nx_demands')) {
			$table = $schema->createTable('nx_demands');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('demand_key', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('problem_statement', Types::TEXT, ['notnull' => false]);
			$table->addColumn('expected_benefit', Types::TEXT, ['notnull' => false]);
			$table->addColumn('demand_type', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'draft']);
			$table->addColumn('intake_channel', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'form']);
			$table->addColumn('requestor_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('submitted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->setPrimaryKey(['id'], 'nx_demands_pk');
			$table->addUniqueIndex(['demand_key'], 'nx_demands_key_uq');
			$table->addIndex(['status'], 'nx_demands_status_ix');
			$table->addIndex(['requestor_id'], 'nx_demands_req_ix');
		}

		if (!$schema->hasTable('nx_audit_log')) {
			$table = $schema->createTable('nx_audit_log');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('entity_type', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('entity_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('action', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('field_changes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('comment', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id'], 'nx_audit_log_pk');
			$table->addIndex(['entity_type', 'entity_id'], 'nx_audit_entity_ix');
		}

		return $schema;
	}
}
