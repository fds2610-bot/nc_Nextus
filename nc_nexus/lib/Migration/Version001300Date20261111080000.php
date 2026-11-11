<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Planungs-Verknüpfung (PLANNING_LINK, Kapitel 07 §4): Vorhaben ↔ Projekt im Planungstool.
 */
class Version001300Date20261111080000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('nx_planning_links')) {
			$t = $schema->createTable('nx_planning_links');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('provider', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('external_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('url', Types::STRING, ['length' => 1024, 'notnull' => false]);
			$t->addColumn('sync_status', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('summary', Types::TEXT, ['notnull' => false]);
			$t->addColumn('synced_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_planning_links_pk');
			$t->addUniqueIndex(['demand_id', 'provider'], 'nx_planning_uq');
		}
		return $schema;
	}
}
