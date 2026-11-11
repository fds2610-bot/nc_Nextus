<?php

declare(strict_types=1);

namespace OCA\Nexus\Migration;

use Closure;
use Doctrine\DBAL\Schema\Table;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Stufe 3 – Entscheidungsprozess (Kapitel 05 §2, §7):
 * Gremien mit Mitgliedern, Entscheidungsrechte, Gate-Sets mit Gate-Definitionen, Gate-Instanzen,
 * Sitzungen mit Tagesordnung und Stimmen, Auflagen/Maßnahmen und Budgettranchen.
 */
class Version001400Date20261112080000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('nx_bodies')) {
			$t = $schema->createTable('nx_bodies');
			$this->id($t);
			$t->addColumn('code', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('body_type', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('chair_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('quorum_min', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('required_seats', Types::TEXT, ['notnull' => false]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_bodies_pk');
		}

		if (!$schema->hasTable('nx_body_members')) {
			$t = $schema->createTable('nx_body_members');
			$this->id($t);
			$t->addColumn('body_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('seat', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('voting', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('deputy_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_body_members_pk');
			$t->addIndex(['body_id'], 'nx_body_members_body');
		}

		if (!$schema->hasTable('nx_decision_rights')) {
			$t = $schema->createTable('nx_decision_rights');
			$this->id($t);
			$t->addColumn('gate_code', Types::STRING, ['length' => 8, 'notnull' => true]);
			$t->addColumn('project_classes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('body_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('quorum_min', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('valid_from', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('valid_to', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_decision_rights_pk');
		}

		if (!$schema->hasTable('nx_gate_sets')) {
			$t = $schema->createTable('nx_gate_sets');
			$this->id($t);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('project_classes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('demand_types', Types::TEXT, ['notnull' => false]);
			$t->addColumn('version', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_gate_sets_pk');
		}

		if (!$schema->hasTable('nx_gate_defs')) {
			$t = $schema->createTable('nx_gate_defs');
			$this->id($t);
			$t->addColumn('set_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 8, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('purpose', Types::TEXT, ['notnull' => false]);
			$t->addColumn('sort', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('required_checks', Types::TEXT, ['notnull' => false]);
			$t->addColumn('budget_tranche', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'nx_gate_defs_pk');
			$t->addIndex(['set_id'], 'nx_gate_defs_set');
		}

		if (!$schema->hasTable('nx_gate_instances')) {
			$t = $schema->createTable('nx_gate_instances');
			$this->id($t);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('gate_code', Types::STRING, ['length' => 8, 'notnull' => true]);
			$t->addColumn('set_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('body_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('completeness', Types::TEXT, ['notnull' => false]);
			$t->addColumn('submitted_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('submitted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('decision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$this->created($t);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_gate_instances_pk');
			$t->addIndex(['demand_id'], 'nx_gate_instances_demand');
		}

		if (!$schema->hasTable('nx_sessions')) {
			$t = $schema->createTable('nx_sessions');
			$this->id($t);
			$t->addColumn('body_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('scheduled_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('duration_min', Types::INTEGER, ['notnull' => true, 'default' => 90]);
			$t->addColumn('location', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('calendar_uid', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('talk_token', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('talk_url', Types::STRING, ['length' => 1024, 'notnull' => false]);
			$t->addColumn('minutes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('closed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_sessions_pk');
		}

		if (!$schema->hasTable('nx_agenda_items')) {
			$t = $schema->createTable('nx_agenda_items');
			$this->id($t);
			$t->addColumn('session_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('gate_instance_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('recommendation', Types::TEXT, ['notnull' => false]);
			$t->addColumn('options', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('decision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_agenda_items_pk');
			$t->addIndex(['session_id'], 'nx_agenda_items_session');
		}

		if (!$schema->hasTable('nx_votes')) {
			$t = $schema->createTable('nx_votes');
			$this->id($t);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('member_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('cast_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('vote', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('conflict', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('comment', Types::TEXT, ['notnull' => false]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_votes_pk');
			$t->addUniqueIndex(['item_id', 'member_id'], 'nx_votes_uq');
		}

		if (!$schema->hasTable('nx_conditions')) {
			$t = $schema->createTable('nx_conditions');
			$this->id($t);
			$t->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('decision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('body_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('owner_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('due_date', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('blocking', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('evidence', Types::TEXT, ['notnull' => false]);
			$t->addColumn('closed_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('closed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_conditions_pk');
			$t->addIndex(['demand_id'], 'nx_conditions_demand');
		}

		if (!$schema->hasTable('nx_tranches')) {
			$t = $schema->createTable('nx_tranches');
			$this->id($t);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('gate_code', Types::STRING, ['length' => 8, 'notnull' => true]);
			$t->addColumn('decision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('amount', Types::FLOAT, ['notnull' => true]);
			$t->addColumn('fiscal_year', Types::INTEGER, ['notnull' => true]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_tranches_pk');
			$t->addIndex(['demand_id'], 'nx_tranches_demand');
		}
		return $schema;
	}

	/** @param Table $t (unter NC 35 eine Hülle mit derselben Schnittstelle) */
	private function id($t): void {
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
	}

	/** @param Table $t (unter NC 35 eine Hülle mit derselben Schnittstelle) */
	private function created($t): void {
		$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
		$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
	}
}
