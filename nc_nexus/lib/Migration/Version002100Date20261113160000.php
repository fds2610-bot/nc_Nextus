<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Stufe 5 – Rolle → Pool (Kapitel 05 ROLE_POOL_MAPPING, Kapitel 07 §4.3): übersetzt den Bedarf
 * aus dem Planungstool (Rolle × Monat) in Pool-Bedarf, je Provider. Rolle „“ = Aufwand ohne Rolle.
 */
class Version002100Date20261113160000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('nx_role_pool_map')) {
			$t = $schema->createTable('nx_role_pool_map');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('provider', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('external_role_ref', Types::STRING, ['length' => 128, 'notnull' => true]);
			$t->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_role_pool_pk');
			$t->addUniqueIndex(['provider', 'external_role_ref'], 'nx_role_pool_uq');
		}
		return $schema;
	}
}
