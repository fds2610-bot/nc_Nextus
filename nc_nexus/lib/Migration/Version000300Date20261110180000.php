<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Zähler für menschenlesbare Schlüssel (DEM-JJJJ-NNNN): jede Nummer wird nur einmal vergeben,
 * auch wenn der zuletzt angelegte Entwurf wieder gelöscht wird.
 */
class Version000300Date20261110180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('nx_counters')) {
			$table = $schema->createTable('nx_counters');
			$table->addColumn('name', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('value', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->setPrimaryKey(['name'], 'nx_counters_pk');
		}
		return $schema;
	}
}
