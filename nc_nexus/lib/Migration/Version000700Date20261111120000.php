<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Entscheidungsregister (DEC-001): unveränderliche Beschlüsse mit Mandat und Begründung.
 * Korrektur = neuer Beschluss mit supersedes_id (Kapitel 05, DECISION).
 */
class Version000700Date20261111120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('nx_decisions')) {
			$table = $schema->createTable('nx_decisions');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('decision_key', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('subject_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('subject_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('decision_type', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('outcome', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('rationale', Types::TEXT, ['notnull' => false]);
			$table->addColumn('details', Types::TEXT, ['notnull' => false]);
			$table->addColumn('mandate_role', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('mandate_scope_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('mandate_scope_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('out_of_sequence', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('supersedes_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('decided_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('decided_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id'], 'nx_decisions_pk');
			$table->addUniqueIndex(['decision_key'], 'nx_decisions_key_uq');
			$table->addIndex(['subject_type', 'subject_id'], 'nx_decisions_subject_ix');
		}
		return $schema;
	}
}
