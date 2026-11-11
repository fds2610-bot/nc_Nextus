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
 * Stufe 4 – Steuerung im Betrieb (Kapitel 05 §4, §9): Projektstammdaten, Meilensteine, Baselines,
 * Statusberichte, Ist-Werte, Risiken (auch projektübergreifend), Issues, Change Requests,
 * Ressourcenkonflikte und KPI-Definitionen; Verrechnungssatz je Pool.
 */
class Version001500Date20261112120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$pools = $schema->getTable('nx_pools');
		if (!$pools->hasColumn('pd_rate')) {
			$pools->addColumn('pd_rate', Types::FLOAT, ['notnull' => false]);
		}

		// Maßnahmen aus Risiken, Issues und Statusberichten: Herkunft an der Maßnahme
		$conditions = $schema->getTable('nx_conditions');
		if (!$conditions->hasColumn('origin_type')) {
			$conditions->addColumn('origin_type', Types::STRING, ['length' => 16, 'notnull' => false]);
			$conditions->addColumn('origin_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		}
		// Change Requests oberhalb der Schwelle kommen auf die Tagesordnung
		$items = $schema->getTable('nx_agenda_items');
		if (!$items->hasColumn('change_id')) {
			$items->addColumn('change_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		}
		// Benachrichtigungen nur einmal je Anlass
		if (!$schema->hasTable('nx_notified')) {
			$t = $schema->createTable('nx_notified');
			$this->id($t);
			$t->addColumn('fingerprint', Types::STRING, ['length' => 191, 'notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_notified_pk');
			$t->addUniqueIndex(['fingerprint'], 'nx_notified_uq');
		}

		if (!$schema->hasTable('nx_projects')) {
			$t = $schema->createTable('nx_projects');
			$this->id($t);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('manager_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('sponsor_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('planned_start', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('planned_end', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('committed', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('benefit_review_due', Types::STRING, ['length' => 10, 'notnull' => false]);
			$this->created($t);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_projects_pk');
			$t->addIndex(['demand_id'], 'nx_projects_demand');
		}

		if (!$schema->hasTable('nx_milestones')) {
			$t = $schema->createTable('nx_milestones');
			$this->id($t);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('due_date', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('forecast_date', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('actual_date', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('external', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$this->created($t);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_milestones_pk');
			$t->addIndex(['demand_id'], 'nx_milestones_demand');
		}

		if (!$schema->hasTable('nx_baselines')) {
			$t = $schema->createTable('nx_baselines');
			$this->id($t);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('version', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('decision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('change_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('planned_start', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('planned_end', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('budget_frame', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('person_days', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('milestones', Types::TEXT, ['notnull' => false]);
			$t->addColumn('resource_demand', Types::TEXT, ['notnull' => false]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_baselines_pk');
			$t->addIndex(['demand_id'], 'nx_baselines_demand');
		}

		if (!$schema->hasTable('nx_status_reports')) {
			$t = $schema->createTable('nx_status_reports');
			$this->id($t);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('period', Types::STRING, ['length' => 7, 'notnull' => true]);
			foreach (['rag_scope', 'rag_time', 'rag_cost', 'rag_risk'] as $rag) {
				$t->addColumn($rag, Types::STRING, ['length' => 8, 'notnull' => true]);
			}
			$t->addColumn('rag_comment', Types::TEXT, ['notnull' => false]);
			$t->addColumn('progress_pct', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('achievements', Types::TEXT, ['notnull' => false]);
			$t->addColumn('next_steps', Types::TEXT, ['notnull' => false]);
			$t->addColumn('impediments', Types::TEXT, ['notnull' => false]);
			$t->addColumn('forecast_end_date', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('forecast_cost', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('health_snapshot', Types::TEXT, ['notnull' => false]);
			$t->addColumn('contradiction_reason', Types::TEXT, ['notnull' => false]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_status_reports_pk');
			$t->addIndex(['demand_id'], 'nx_status_reports_demand');
		}

		if (!$schema->hasTable('nx_actuals')) {
			$t = $schema->createTable('nx_actuals');
			$this->id($t);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('period', Types::STRING, ['length' => 7, 'notnull' => true]);
			$t->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('pool_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('amount', Types::FLOAT, ['notnull' => true]);
			$t->addColumn('source', Types::STRING, ['length' => 32, 'notnull' => true]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_actuals_pk');
			$t->addIndex(['demand_id'], 'nx_actuals_demand');
		}

		if (!$schema->hasTable('nx_risks')) {
			$t = $schema->createTable('nx_risks');
			$this->id($t);
			$t->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('category', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('probability', Types::SMALLINT, ['notnull' => true]);
			$t->addColumn('impact', Types::SMALLINT, ['notnull' => true]);
			$t->addColumn('mitigation', Types::TEXT, ['notnull' => false]);
			$t->addColumn('residual_probability', Types::SMALLINT, ['notnull' => false]);
			$t->addColumn('residual_impact', Types::SMALLINT, ['notnull' => false]);
			$t->addColumn('owner_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('due_date', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('escalated', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$this->created($t);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_risks_pk');
		}

		if (!$schema->hasTable('nx_risk_links')) {
			$t = $schema->createTable('nx_risk_links');
			$this->id($t);
			$t->addColumn('risk_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('is_primary', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'nx_risk_links_pk');
			$t->addUniqueIndex(['risk_id', 'demand_id'], 'nx_risk_links_uq');
			$t->addIndex(['demand_id'], 'nx_risk_links_demand');
		}

		if (!$schema->hasTable('nx_issues')) {
			$t = $schema->createTable('nx_issues');
			$this->id($t);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('severity', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('owner_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('due_date', Types::STRING, ['length' => 10, 'notnull' => false]);
			$t->addColumn('escalation_level', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$this->created($t);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_issues_pk');
			$t->addIndex(['demand_id'], 'nx_issues_demand');
		}

		if (!$schema->hasTable('nx_changes')) {
			$t = $schema->createTable('nx_changes');
			$this->id($t);
			$t->addColumn('demand_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('change_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('delta_budget', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('delta_days', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('delta_person_days', Types::TEXT, ['notnull' => false]);
			$t->addColumn('delta_risk', Types::TEXT, ['notnull' => false]);
			$t->addColumn('delta_benefit', Types::TEXT, ['notnull' => false]);
			$t->addColumn('impact_analysis', Types::TEXT, ['notnull' => false]);
			$t->addColumn('above_threshold', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('threshold_reasons', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('body_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('decision_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('baseline_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('origin', Types::STRING, ['length' => 16, 'notnull' => true]);
			$this->created($t);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_changes_pk');
			$t->addIndex(['demand_id'], 'nx_changes_demand');
		}

		if (!$schema->hasTable('nx_conflicts')) {
			$t = $schema->createTable('nx_conflicts');
			$this->id($t);
			$t->addColumn('conflict_type', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('fingerprint', Types::STRING, ['length' => 128, 'notnull' => true]);
			$t->addColumn('pool_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('period', Types::STRING, ['length' => 7, 'notnull' => false]);
			$t->addColumn('demand_ids', Types::TEXT, ['notnull' => false]);
			$t->addColumn('detail', Types::TEXT, ['notnull' => false]);
			$t->addColumn('escalation_level', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('escalate_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('resolution_option', Types::STRING, ['length' => 32, 'notnull' => false]);
			$t->addColumn('rejected_options', Types::TEXT, ['notnull' => false]);
			$t->addColumn('resolution_note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('resolved_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('resolved_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$this->created($t);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'nx_conflicts_pk');
			$t->addIndex(['fingerprint'], 'nx_conflicts_fp');
		}

		if (!$schema->hasTable('nx_kpis')) {
			$t = $schema->createTable('nx_kpis');
			$this->id($t);
			$t->addColumn('code', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('definition', Types::TEXT, ['notnull' => false]);
			$t->addColumn('data_source', Types::TEXT, ['notnull' => false]);
			$t->addColumn('owner_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('direction', Types::STRING, ['length' => 8, 'notnull' => true]);
			$t->addColumn('threshold_green', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('threshold_red', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('unit', Types::STRING, ['length' => 16, 'notnull' => false]);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$this->created($t);
			$t->setPrimaryKey(['id'], 'nx_kpis_pk');
		}
		return $schema;
	}

	/** @param Table $t (unter NC 35 eine Hülle mit derselben Schnittstelle) */
	private function id($t): void {
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
	}

	/** @param Table $t */
	private function created($t): void {
		$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
		$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
	}
}
