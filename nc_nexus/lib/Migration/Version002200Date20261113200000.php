<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Stufe 5 – Hintergrundabgleich der Planungstools (Kapitel 07 §4.2): Abgleichsfehler werden mit
 * Meldung gespeichert (Übersetzungsschlüssel und Werte als JSON), damit das Datenqualitäts-Cockpit
 * und die Projektansicht sie ohne erneuten Abruf zeigen können.
 */
class Version002200Date20261113200000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('nx_planning_links');
		if (!$t->hasColumn('sync_error')) {
			$t->addColumn('sync_error', Types::STRING, ['length' => 1024, 'notnull' => false]);
			return $schema;
		}
		return null;
	}
}
