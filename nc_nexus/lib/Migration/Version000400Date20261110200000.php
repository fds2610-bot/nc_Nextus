<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Triage (DEM-002): Entscheidungscode mit Pflichtangaben, Rückfrage, Vorhabensklasse.
 */
class Version000400Date20261110200000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('nx_demands');
		$columns = [
			'project_class' => [Types::STRING, ['length' => 4]],
			'triage_code' => [Types::STRING, ['length' => 32]],
			'triage_reason' => [Types::TEXT, []],
			'resubmission_date' => [Types::STRING, ['length' => 10]],
			'duplicate_of_id' => [Types::BIGINT, ['unsigned' => true]],
			'analysis_owner' => [Types::STRING, ['length' => 64]],
			'analysis_due' => [Types::STRING, ['length' => 10]],
			'query_text' => [Types::TEXT, []],
			'query_due' => [Types::STRING, ['length' => 10]],
			'triaged_by' => [Types::STRING, ['length' => 64]],
			'triaged_at' => [Types::BIGINT, ['unsigned' => true]],
		];
		foreach ($columns as $name => [$type, $opts]) {
			if (!$table->hasColumn($name)) {
				$table->addColumn($name, $type, ['notnull' => false] + $opts);
			}
		}
		return $schema;
	}
}
