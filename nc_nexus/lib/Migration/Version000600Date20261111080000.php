<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * „Analyse geliefert“: Erst danach ist ein Antrag in weiterer Analyse wieder triagierbar
 * (Statusautomat Kapitel 02 §4.1).
 */
class Version000600Date20261111080000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('nx_demands');
		if (!$table->hasColumn('analysis_done_at')) {
			$table->addColumn('analysis_done_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		}
		return $schema;
	}
}
