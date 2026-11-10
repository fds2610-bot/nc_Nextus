<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kommentarfaden am Antrag (DEM-003): Kommentare, Rückfragen und Antworten, nur anfügbar.
 */
class Version000500Date20261110220000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('nx_comments')) {
			$table = $schema->createTable('nx_comments');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('message', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id'], 'nx_comments_pk');
			$table->addIndex(['demand_id'], 'nx_comments_demand_ix');
		}
		return $schema;
	}
}
