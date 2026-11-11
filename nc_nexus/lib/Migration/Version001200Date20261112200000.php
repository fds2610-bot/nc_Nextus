<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Gates G2/G3 und Allokationen (Kapitel 02 §3, 04 §3.2): Projektschlüssel am Vorhaben,
 * verbindliche Kapazitätszusagen je Pool und Monat.
 */
class Version001200Date20261112200000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$demands = $schema->getTable('nx_demands');
		foreach ([
			'project_key' => [Types::STRING, ['length' => 32]],
			'released_at' => [Types::BIGINT, ['unsigned' => true]],
		] as $name => [$type, $opts]) {
			if (!$demands->hasColumn($name)) {
				$demands->addColumn($name, $type, ['notnull' => false] + $opts);
			}
		}
		if (!$schema->hasTable('nx_allocations')) {
			$t = $schema->createTable('nx_allocations');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('period', Types::STRING, ['length' => 7, 'notnull' => true]);
			$t->addColumn('person_days', Types::FLOAT, ['notnull' => true]);
			$t->addColumn('level', Types::STRING, ['length' => 8, 'notnull' => true]);
			$t->addColumn('decision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_allocations_pk');
			$t->addIndex(['pool_id', 'period'], 'nx_alloc_pool_ix');
			$t->addIndex(['demand_id'], 'nx_alloc_demand_ix');
		}
		return $schema;
	}
}
