<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\ResourceNeed;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Projektsteuerung (PRJ-001/002, FIN-002, Kapitel 02 §4.5, 03 §7.2): Projektstammdaten,
 * governance-relevante Meilensteine, Baselines, Statusberichte mit gemeldeter Ampel und
 * berechnetem Health-Indikator, Finanzen (Rahmen, Tranchen, gebunden, Ist, FAC) und Ist-Werte.
 *
 * Gemeldet und berechnet bleiben getrennt (Prinzip P11); widerspricht die Meldung dem Indikator,
 * verlangt der Bericht eine Begründung.
 */
class ProjectService {
	/** Status, in denen ein Vorhaben ein laufendes Projekt ist */
	public const ACTIVE = ['released', 'in_execution', 'paused'];
	/** Status, in denen das Projekt-Cockpit verfügbar ist */
	public const PROJECT = ['prioritized', 'released', 'in_execution', 'paused', 'completed', 'closed', 'cancelled'];
	public const RAG = ['green', 'yellow', 'red'];
	private const PROJECTS = 'nx_projects';
	private const MILESTONES = 'nx_milestones';
	private const BASELINES = 'nx_baselines';
	private const REPORTS = 'nx_status_reports';
	private const ACTUALS = 'nx_actuals';
	public const ACTUAL_KINDS = ['cost', 'effort'];

	public function __construct(
		private Rows $rows,
		private DemandService $demands,
		private DemandMapper $demandMapper,
		private BusinessCaseService $businessCases,
		private ResourceNeedMapper $needs,
		private PoolMapper $pools,
		private PermissionService $permissions,
		private SettingsService $settings,
		private AuditService $audit,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	// ---- Rechte -------------------------------------------------------------------------------

	/** Projektleitung, PMO des Bereichs und Administration pflegen das Projekt. */
	public function canManage(string $userId, Demand $demand): bool {
		if (!in_array($demand->getStatus(), self::PROJECT, true)) {
			return false;
		}
		$project = $this->projectRow($demand);
		return ($project['managerId'] ?? null) === $userId
			|| $this->permissions->canTriage($userId, $demand)
			|| $this->permissions->isAdmin($userId);
	}

	/**
	 * @throws NotFoundException|ForbiddenException
	 */
	public function manageable(string $userId, int $demandId): Demand {
		$demand = $this->demands->findReadable($userId, $demandId);
		if (!$this->canManage($userId, $demand)) {
			throw new ForbiddenException('Only the project manager or the portfolio office may change the project');
		}
		return $demand;
	}

	// ---- Cockpit ------------------------------------------------------------------------------

	/**
	 * Projekte, die das Konto lesen darf, mit Ampel, Health und Finanzkennzahlen (Portfolio-Sicht).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list(string $userId): array {
		$result = [];
		foreach ($this->demands->findAll($userId) as $demand) {
			if (!in_array($demand->getStatus(), self::PROJECT, true) || $demand->getStatus() === 'prioritized') {
				continue;
			}
			$report = $this->latestReport($demand);
			$health = $this->health($demand);
			$finance = $this->finance($demand);
			$project = $this->projectRow($demand);
			$result[] = [
				'id' => $demand->getId(),
				'key' => $demand->getProjectKey() ?? $demand->getDemandKey(),
				'title' => $demand->getTitle(),
				'status' => $demand->getStatus(),
				'projectClass' => $demand->getProjectClass(),
				'orgUnitId' => $demand->getOrgUnitId(),
				'managerId' => $project['managerId'] ?? null,
				'plannedEnd' => $project['plannedEnd'] ?? null,
				'rag' => $report === null ? null : $this->rag($report),
				'reportPeriod' => $report['period'] ?? null,
				'health' => $health['level'],
				'findings' => count($health['findings']),
				'contradiction' => $report !== null && $this->contradictions($report, $health) !== [],
				'budgetFrame' => $finance['frame'],
				'forecast' => $finance['forecast'],
				'deviationPct' => $finance['deviationPct'],
				'escalation' => $this->escalationLevel($demand),
			];
		}
		return $result;
	}

	/**
	 * Projekt-Cockpit eines Vorhabens.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException
	 */
	public function cockpit(string $userId, int $demandId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		if (!in_array($demand->getStatus(), self::PROJECT, true)) {
			throw new ForbiddenException('The demand is not a project yet');
		}
		$health = $this->health($demand);
		$reports = $this->reports($demand);
		$latest = $reports[0] ?? null;
		return [
			'demand' => $demand->jsonSerialize(),
			'project' => $this->projectRow($demand) ?? ['managerId' => null, 'sponsorId' => null, 'plannedStart' => null, 'plannedEnd' => null, 'committed' => null, 'benefitReviewDue' => null],
			'milestones' => $this->milestones($demand),
			'baselines' => $this->baselines($demand),
			'reports' => $reports,
			'rag' => $latest === null ? null : $this->rag($latest),
			'health' => $health,
			'contradictions' => $latest === null ? [] : $this->contradictions($latest, $health),
			'finance' => $this->finance($demand),
			'effort' => $this->effort($demand),
			'actuals' => $this->actuals($demand),
			'reportDue' => $this->reportDue($demand),
			'escalation' => $this->escalationLevel($demand),
			'can' => ['manage' => $this->canManage($userId, $demand), 'import' => $this->permissions->canManageOrg($userId)],
		];
	}

	/**
	 * Stammdaten pflegen: Projektleitung, Sponsor, Plantermine, gebundenes Budget.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function saveProject(string $userId, int $demandId, array $data): array {
		$demand = $this->manageable($userId, $demandId);
		$before = $this->projectRow($demand) ?? [];
		$values = [];
		foreach (['managerId', 'sponsorId'] as $field) {
			if (array_key_exists($field, $data)) {
				$user = trim((string)$data[$field]);
				if ($user !== '' && !$this->userManager->userExists($user)) {
					throw new ValidationException('Unknown account or group');
				}
				$values[$field] = $user === '' ? null : $user;
			}
		}
		foreach (['plannedStart', 'plannedEnd', 'benefitReviewDue'] as $field) {
			if (array_key_exists($field, $data)) {
				$values[$field] = $this->date($data[$field]);
			}
		}
		$start = $values['plannedStart'] ?? $before['plannedStart'] ?? null;
		$end = $values['plannedEnd'] ?? $before['plannedEnd'] ?? null;
		if ($start !== null && $end !== null && $end < $start) {
			throw new ValidationException('The end date is before the start date');
		}
		if (array_key_exists('committed', $data)) {
			$values['committed'] = $data['committed'] === null || $data['committed'] === '' ? null : max(0.0, (float)$data['committed']);
		}
		$this->upsertProject($userId, $demand, $values);
		$changes = [];
		foreach ($values as $k => $v) {
			if (($before[$k] ?? null) !== $v) {
				$changes[$k] = [$before[$k] ?? null, $v];
			}
		}
		if ($changes !== []) {
			$this->audit->log(DemandService::ENTITY, $demand->getId(), 'project_update', $userId, $changes);
		}
		return $this->cockpit($userId, $demandId);
	}

	// ---- Meilensteine -------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $data name, dueDate, forecastDate, actualDate, external
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function saveMilestone(string $userId, int $demandId, ?int $id, array $data): array {
		$demand = $this->manageable($userId, $demandId);
		$before = null;
		if ($id !== null) {
			$before = $this->rows->find(self::MILESTONES, $id);
			if ($before === null || $before['demandId'] !== $demandId) {
				throw new NotFoundException('Milestone not found');
			}
		}
		$name = trim((string)($data['name'] ?? ($before['name'] ?? '')));
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('A name is required');
		}
		$due = $this->date($data['dueDate'] ?? ($before['dueDate'] ?? null));
		if ($due === null) {
			throw new ValidationException('A due date is required');
		}
		$values = [
			'name' => $name,
			'dueDate' => $due,
			'forecastDate' => array_key_exists('forecastDate', $data) ? $this->date($data['forecastDate']) : ($before['forecastDate'] ?? null),
			'actualDate' => array_key_exists('actualDate', $data) ? $this->date($data['actualDate']) : ($before['actualDate'] ?? null),
			'external' => (bool)($data['external'] ?? ($before['external'] ?? 0)) ? 1 : 0,
			'updatedAt' => $this->time->getTime(),
		];
		if ($id === null) {
			$id = $this->rows->insert(self::MILESTONES, $values + ['demandId' => $demandId, 'createdBy' => $userId, 'createdAt' => $this->time->getTime()]);
			$this->audit->log(DemandService::ENTITY, $demandId, 'milestone_create', $userId, ['milestone' => [null, $name], 'dueDate' => [null, $due]]);
		} else {
			$this->rows->update(self::MILESTONES, $id, $values);
			$this->audit->log(DemandService::ENTITY, $demandId, 'milestone_update', $userId, [
				'milestone' => [$before['name'], $name],
				'forecastDate' => [$before['forecastDate'], $values['forecastDate']],
				'actualDate' => [$before['actualDate'], $values['actualDate']],
			]);
		}
		return $this->cockpit($userId, $demandId);
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException
	 */
	public function deleteMilestone(string $userId, int $demandId, int $id): array {
		$this->manageable($userId, $demandId);
		$row = $this->rows->find(self::MILESTONES, $id);
		if ($row === null || $row['demandId'] !== $demandId) {
			throw new NotFoundException('Milestone not found');
		}
		$this->rows->delete(self::MILESTONES, $id);
		$this->audit->log(DemandService::ENTITY, $demandId, 'milestone_delete', $userId, ['milestone' => [$row['name'], null]]);
		return $this->cockpit($userId, $demandId);
	}

	// ---- Baselines ----------------------------------------------------------------------------

	/**
	 * Freigabe (G3): Projektstammdaten anlegen, Baseline 1 und Termin des Nutzenreviews setzen.
	 * Läuft innerhalb der Transaktion des Gate-Beschlusses.
	 */
	public function onReleased(string $userId, Demand $demand, int $decisionId): void {
		$project = $this->projectRow($demand);
		$periods = array_map(static fn (ResourceNeed $n) => $n->getPeriod(), $this->validNeeds($demand));
		sort($periods);
		$values = [];
		if (($project['plannedStart'] ?? null) === null && $periods !== []) {
			$values['plannedStart'] = $periods[0] . '-01';
		}
		if (($project['plannedEnd'] ?? null) === null && $periods !== []) {
			$values['plannedEnd'] = date('Y-m-t', (int)strtotime(end($periods) . '-01'));
		}
		$end = $values['plannedEnd'] ?? $project['plannedEnd'] ?? date('Y-m-d', $this->time->getTime());
		// Nutzenreview 6–12 Monate nach Go-Live wird bei G3 terminiert (Kapitel 02 §3)
		if (($project['benefitReviewDue'] ?? null) === null) {
			$values['benefitReviewDue'] = date('Y-m-d', (int)strtotime($end . ' +9 months'));
		}
		$this->upsertProject($userId, $demand, $values);
		$this->createBaseline($userId, $demand, $decisionId, null, 'G3');
	}

	/**
	 * Unveränderliche Baseline-Version anlegen (Version 1 bei G3, weitere durch genehmigte Change Requests).
	 *
	 * @param array<string, mixed> $override geänderte Werte (Change Request)
	 */
	public function createBaseline(string $userId, Demand $demand, ?int $decisionId, ?int $changeId, string $note, array $override = []): int {
		$project = $this->projectRow($demand) ?? [];
		$previous = $this->baselines($demand)[0] ?? null;
		$byPool = [];
		$days = 0.0;
		foreach ($this->validNeeds($demand) as $need) {
			$byPool[$need->getPoolId()][$need->getPeriod()] = ($byPool[$need->getPoolId()][$need->getPeriod()] ?? 0) + $need->getPersonDays();
			$days += $need->getPersonDays();
		}
		$kpis = $this->businessCases->kpisFor($demand);
		$values = [
			'demandId' => $demand->getId(),
			'version' => ($previous['version'] ?? 0) + 1,
			'decisionId' => $decisionId,
			'changeId' => $changeId,
			'plannedStart' => $override['plannedStart'] ?? $project['plannedStart'] ?? null,
			'plannedEnd' => $override['plannedEnd'] ?? $project['plannedEnd'] ?? null,
			'budgetFrame' => $override['budgetFrame'] ?? $previous['budgetFrame'] ?? ($kpis['oneTimeCost'] ?? null),
			'personDays' => $override['personDays'] ?? $previous['personDays'] ?? round($days, 1),
			'milestones' => array_map(static fn (array $m) => ['name' => $m['name'], 'dueDate' => $m['dueDate'], 'external' => $m['external']], $this->milestones($demand)),
			'resourceDemand' => $byPool,
			'note' => $note,
			'createdBy' => $userId,
			'createdAt' => $this->time->getTime(),
		];
		$id = $this->rows->insert(self::BASELINES, $values);
		$this->audit->log(DemandService::ENTITY, $demand->getId(), 'baseline', $userId, ['baseline' => [$previous['version'] ?? null, $values['version']]], $note);
		return $id;
	}

	/** @return list<array<string, mixed>> neueste zuerst */
	public function baselines(Demand $demand): array {
		return array_reverse($this->ofDemand(self::BASELINES, $demand, 'version'));
	}

	// ---- Statusberichte -----------------------------------------------------------------------

	/**
	 * Vorlage für den nächsten Bericht: aus dem letzten Bericht vorbefüllt, mit Ist-Werten.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException
	 */
	public function reportTemplate(string $userId, int $demandId): array {
		$demand = $this->manageable($userId, $demandId);
		$last = $this->latestReport($demand);
		$finance = $this->finance($demand);
		return [
			'period' => date('Y-m', $this->time->getTime()),
			'ragScope' => $last['ragScope'] ?? 'green',
			'ragTime' => $last['ragTime'] ?? 'green',
			'ragCost' => $last['ragCost'] ?? 'green',
			'ragRisk' => $last['ragRisk'] ?? 'green',
			'ragComment' => '',
			'progressPct' => $last['progressPct'] ?? 0,
			'achievements' => '',
			'nextSteps' => $last['nextSteps'] ?? '',
			'impediments' => $last['impediments'] ?? '',
			'forecastEndDate' => $last['forecastEndDate'] ?? ($this->projectRow($demand)['plannedEnd'] ?? null),
			'forecastCost' => $last['forecastCost'] ?? $finance['frame'],
			'prefilled' => ['costActual' => $finance['actual'], 'effortActual' => $this->effort($demand)['actual']],
			'health' => $this->health($demand),
		];
	}

	/**
	 * Statusbericht abgeben. Widerspricht eine grüne Meldung dem roten Indikator derselben
	 * Dimension, ist eine Begründung Pflicht.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed> Cockpit
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function saveReport(string $userId, int $demandId, array $data): array {
		$demand = $this->manageable($userId, $demandId);
		$period = (string)($data['period'] ?? date('Y-m', $this->time->getTime()));
		if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
			throw new ValidationException('Unknown period');
		}
		$values = ['period' => $period];
		foreach (['ragScope', 'ragTime', 'ragCost', 'ragRisk'] as $field) {
			$rag = (string)($data[$field] ?? '');
			if (!in_array($rag, self::RAG, true)) {
				throw new ValidationException('Every dimension needs a traffic light');
			}
			$values[$field] = $rag;
		}
		foreach (['ragComment', 'achievements', 'nextSteps', 'impediments'] as $field) {
			$values[$field] = trim((string)($data[$field] ?? '')) ?: null;
		}
		if (in_array('red', [$values['ragScope'], $values['ragTime'], $values['ragCost'], $values['ragRisk']], true) && $values['ragComment'] === null) {
			throw new ValidationException('A red traffic light needs a comment');
		}
		$progress = $data['progressPct'] ?? null;
		$values['progressPct'] = $progress === null || $progress === '' ? null : max(0, min(100, (int)$progress));
		$values['forecastEndDate'] = $this->date($data['forecastEndDate'] ?? null);
		$values['forecastCost'] = ($data['forecastCost'] ?? '') === '' || $data['forecastCost'] === null ? null : max(0.0, (float)$data['forecastCost']);
		$health = $this->health($demand);
		$contradictions = $this->contradictions($values, $health);
		$reason = trim((string)($data['contradictionReason'] ?? ''));
		if ($contradictions !== [] && $reason === '') {
			throw new ValidationException('The report contradicts the health indicator (%1$s); please explain why', [implode(', ', $contradictions)]);
		}
		$values['contradictionReason'] = $contradictions === [] ? null : $reason;
		$values['healthSnapshot'] = $health;
		$id = $this->rows->insert(self::REPORTS, $values + ['demandId' => $demandId, 'createdBy' => $userId, 'createdAt' => $this->time->getTime()]);
		$this->audit->log(DemandService::ENTITY, $demandId, 'status_report', $userId, [
			'period' => [null, $period],
			'rag' => [null, implode('/', [$values['ragScope'], $values['ragTime'], $values['ragCost'], $values['ragRisk']])],
			'health' => [null, $health['level']],
		], $values['contradictionReason']);
		unset($id);
		return $this->cockpit($userId, $demandId);
	}

	/** @return list<array<string, mixed>> neueste zuerst */
	public function reports(Demand $demand): array {
		$rows = $this->ofDemand(self::REPORTS, $demand, 'createdAt');
		return array_reverse($rows);
	}

	/** @return array<string, mixed>|null */
	public function latestReport(Demand $demand): ?array {
		return $this->reports($demand)[0] ?? null;
	}

	/**
	 * Fälligkeit des nächsten Berichts nach Kadenz der Klasse (M/L monatlich, XS/S quartalsweise).
	 *
	 * @return array{due: string, overdue: bool, cadenceDays: int}|null
	 */
	public function reportDue(Demand $demand): ?array {
		if (!in_array($demand->getStatus(), self::ACTIVE, true) || $demand->getStatus() === 'paused') {
			return null;
		}
		$days = (int)$this->settings->get(in_array($demand->getProjectClass(), ['XS', 'S'], true) ? 'reportDaysSmall' : 'reportDaysLarge');
		$last = $this->latestReport($demand);
		$from = $last['createdAt'] ?? ($demand->getReleasedAt() ?? $demand->getUpdatedAt());
		$due = $from + $days * 86400;
		return ['due' => date('Y-m-d', $due), 'overdue' => $due < $this->time->getTime(), 'cadenceDays' => $days];
	}

	// ---- Health-Indikator ---------------------------------------------------------------------

	/**
	 * Berechneter Indikator aus objektiven Daten (Kapitel 02 §4.5). Jede Regel nennt Dimension
	 * und Datenquelle; das Gesamtergebnis ist die schlechteste Stufe.
	 *
	 * @return array{level: string, findings: list<array{rule: string, level: string, dimension: string, source: string, detail: string}>}
	 */
	public function health(Demand $demand): array {
		$findings = [];
		$today = date('Y-m-d', $this->time->getTime());
		$add = static function (string $rule, string $level, string $dimension, string $source, string $detail) use (&$findings): void {
			$findings[] = ['rule' => $rule, 'level' => $level, 'dimension' => $dimension, 'source' => $source, 'detail' => $detail];
		};
		$active = in_array($demand->getStatus(), self::ACTIVE, true);

		foreach ($this->milestones($demand) as $m) {
			if ($m['actualDate'] === null && $m['dueDate'] < $today) {
				$add('milestone_overdue', $m['external'] === 1 ? 'red' : 'yellow', 'time', 'milestones', $m['name'] . ' · ' . $m['dueDate']);
			} elseif ($m['actualDate'] === null && $m['forecastDate'] !== null && $m['forecastDate'] > $m['dueDate']) {
				$add('milestone_late', $m['external'] === 1 ? 'red' : 'yellow', 'time', 'milestones', $m['name'] . ' · ' . $m['forecastDate']);
			}
		}
		$finance = $this->finance($demand);
		if ($finance['frame'] !== null && $finance['forecast'] !== null && $finance['frame'] > 0) {
			$tolerance = $this->settings->get('facTolerancePct');
			if ($finance['forecast'] > $finance['frame'] * (1 + $tolerance / 100)) {
				$add('forecast_over_budget', 'red', 'cost', 'status_report', sprintf('%.0f / %.0f', $finance['forecast'], $finance['frame']));
			} elseif ($finance['forecast'] > $finance['frame']) {
				$add('forecast_over_budget', 'yellow', 'cost', 'status_report', sprintf('%.0f / %.0f', $finance['forecast'], $finance['frame']));
			}
		}
		if ($active) {
			$due = $this->reportDue($demand);
			if ($due !== null && $due['overdue']) {
				$late = ($this->time->getTime() - (int)strtotime($due['due'])) / 86400;
				$add('report_overdue', $late > $due['cadenceDays'] ? 'red' : 'yellow', 'scope', 'status_report', $due['due']);
			}
			$effort = $this->effort($demand);
			$tolerance = $this->settings->get('effortDeviationPct');
			if ($effort['plannedToDate'] > 0 && $effort['actual'] !== null) {
				$deviation = ($effort['actual'] - $effort['plannedToDate']) / $effort['plannedToDate'] * 100;
				if (abs($deviation) > $tolerance) {
					$add('effort_deviation', 'yellow', 'cost', 'actuals', sprintf('%+.0f %%', $deviation));
				}
			}
		}
		foreach ($this->rows->where('nx_conditions', ['demandId' => $demand->getId(), 'status' => 'open']) as $c) {
			if ($c['blocking'] === 1 && $c['dueDate'] < $today && $c['createdAt'] >= $demand->getCreatedAt()) {
				$add('blocking_condition_overdue', 'red', 'scope', 'conditions', $c['title']);
			}
		}
		$threshold = $this->settings->get('riskEscalationScore');
		$riskIds = array_column($this->rows->where('nx_risk_links', ['demandId' => $demand->getId()]), 'riskId');
		foreach ($this->rows->where('nx_risks', ['id' => array_map('intval', $riskIds)]) as $r) {
			if ($r['status'] === 'open' && $r['probability'] * $r['impact'] >= $threshold && trim((string)$r['mitigation']) === '') {
				$add('critical_risk_unmitigated', 'red', 'risk', 'risks', $r['title']);
			}
		}
		foreach ($this->rows->where('nx_issues', ['demandId' => $demand->getId(), 'status' => 'open']) as $i) {
			if ($i['createdAt'] >= $demand->getCreatedAt() && $i['severity'] === 'critical') {
				$add('critical_issue', 'red', 'risk', 'issues', $i['title']);
			}
		}
		foreach ($this->openConflicts($demand) as $c) {
			if ($c['escalationLevel'] >= 2) {
				$add('conflict_escalated', 'red', 'time', 'conflicts', $c['conflictType'] . ' · ' . ($c['period'] ?? ''));
			}
		}
		$level = 'green';
		foreach ($findings as $f) {
			if ($f['level'] === 'red') {
				$level = 'red';
			} elseif ($f['level'] === 'yellow' && $level === 'green') {
				$level = 'yellow';
			}
		}
		return ['level' => $level, 'findings' => $findings];
	}

	/**
	 * Dimensionen, in denen die Meldung grün ist, der Indikator aber rot.
	 *
	 * @param array<string, mixed> $report
	 * @param array{level: string, findings: list<array<string, string>>} $health
	 * @return list<string>
	 */
	public function contradictions(array $report, array $health): array {
		$red = [];
		foreach ($health['findings'] as $f) {
			if ($f['level'] === 'red') {
				$red[$f['dimension']] = true;
			}
		}
		$field = ['scope' => 'ragScope', 'time' => 'ragTime', 'cost' => 'ragCost', 'risk' => 'ragRisk'];
		$result = [];
		foreach ($red as $dimension => $_) {
			if (($report[$field[$dimension]] ?? null) === 'green') {
				$result[] = $dimension;
			}
		}
		return $result;
	}

	// ---- Finanzen und Aufwand -----------------------------------------------------------------

	/**
	 * Rahmen (Baseline bzw. Business Case), freigegeben (Tranchen), gebunden, Ist (Kosten + intern
	 * geleistete Personentage × Verrechnungssatz), Forecast at Completion (jüngster Bericht), Abweichung.
	 *
	 * @return array{frame: float|null, approved: float, committed: float|null, actual: float, actualExternal: float, actualInternal: float, forecast: float|null, deviation: float|null, deviationPct: float|null}
	 */
	public function finance(Demand $demand): array {
		$baseline = $this->baselines($demand)[0] ?? null;
		$frame = $baseline['budgetFrame'] ?? ($this->businessCases->kpisFor($demand)['oneTimeCost'] ?? null);
		$frame = $frame === null ? null : (float)$frame;
		$approved = 0.0;
		foreach ($this->rows->where('nx_tranches', ['demandId' => $demand->getId()]) as $t) {
			if ($t['createdAt'] >= $demand->getCreatedAt()) {
				$approved += (float)$t['amount'];
			}
		}
		$rates = [];
		foreach ($this->pools->findAll() as $pool) {
			$rates[$pool->getId()] = $pool->getPdRate();
		}
		$external = 0.0;
		$internal = 0.0;
		foreach ($this->actuals($demand) as $a) {
			if ($a['kind'] === 'cost') {
				$external += (float)$a['amount'];
			} elseif ($a['kind'] === 'effort' && $a['poolId'] !== null) {
				$internal += (float)$a['amount'] * (float)($rates[$a['poolId']] ?? 0);
			}
		}
		$report = $this->latestReport($demand);
		$forecast = $report['forecastCost'] ?? null;
		$deviation = $frame !== null && $forecast !== null ? (float)$forecast - $frame : null;
		return [
			'frame' => $frame,
			'approved' => $approved,
			'committed' => ($this->projectRow($demand)['committed'] ?? null),
			'actual' => round($external + $internal, 2),
			'actualExternal' => round($external, 2),
			'actualInternal' => round($internal, 2),
			'forecast' => $forecast === null ? null : (float)$forecast,
			'deviation' => $deviation,
			'deviationPct' => $deviation !== null && $frame > 0 ? round($deviation / $frame * 100, 1) : null,
		];
	}

	/**
	 * Aufwand: geplant (Bedarf), geplant bis heute, Ist (erfasste Personentage).
	 *
	 * @return array{planned: float, plannedToDate: float, actual: float|null}
	 */
	public function effort(Demand $demand): array {
		$month = date('Y-m', $this->time->getTime());
		$planned = 0.0;
		$toDate = 0.0;
		foreach ($this->validNeeds($demand) as $need) {
			$planned += $need->getPersonDays();
			if ($need->getPeriod() <= $month) {
				$toDate += $need->getPersonDays();
			}
		}
		$actual = null;
		foreach ($this->actuals($demand) as $a) {
			if ($a['kind'] === 'effort') {
				$actual = ($actual ?? 0.0) + (float)$a['amount'];
			}
		}
		return ['planned' => round($planned, 1), 'plannedToDate' => round($toDate, 1), 'actual' => $actual === null ? null : round($actual, 1)];
	}

	/** @return list<array<string, mixed>> */
	public function actuals(Demand $demand): array {
		return $this->ofDemand(self::ACTUALS, $demand, 'period');
	}

	/**
	 * Ist-Wert von Hand erfassen (Projektleitung/PMO).
	 *
	 * @param array<string, mixed> $data period, kind (cost|effort), poolId, amount
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function addActual(string $userId, int $demandId, array $data): array {
		$demand = $this->manageable($userId, $demandId);
		$this->insertActual($userId, $demand, $data, 'manual');
		return $this->cockpit($userId, $demandId);
	}

	/**
	 * CSV-Import der Ist-Werte (PMO): Zeilen „Projekt;Monat;Art;Pool;Wert“, Projekt als PRJ- oder
	 * DEM-Schlüssel, Art cost oder effort, Pool-Kürzel nur bei effort. Trennzeichen ; oder ,.
	 *
	 * @return array{imported: int, errors: list<string>}
	 * @throws ForbiddenException
	 */
	public function importActuals(string $userId, string $csv): array {
		if (!$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may import actuals');
		}
		$byKey = [];
		foreach ($this->demandMapper->findAll() as $d) {
			$byKey[$d->getDemandKey()] = $d;
			if ($d->getProjectKey() !== null) {
				$byKey[$d->getProjectKey()] = $d;
			}
		}
		$pools = [];
		foreach ($this->pools->findAll() as $p) {
			$pools[strtoupper($p->getCode())] = $p->getId();
		}
		$imported = 0;
		$errors = [];
		foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $n => $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}
			$cells = array_map('trim', str_getcsv($line, str_contains($line, ';') ? ';' : ',', '"', '\\'));
			if ($n === 0 && !preg_match('/^(PRJ|DEM)-/i', $cells[0])) {
				continue; // Kopfzeile
			}
			[$key, $period, $kind, $pool, $amount] = array_pad($cells, 5, '');
			$demand = $byKey[strtoupper((string)$key)] ?? null;
			try {
				if ($demand === null) {
					throw new ValidationException('Unknown project');
				}
				$this->insertActual($userId, $demand, [
					'period' => $period, 'kind' => strtolower((string)$kind),
					'poolId' => $pool === '' ? null : ($pools[strtoupper((string)$pool)] ?? -1),
					'amount' => str_replace(',', '.', (string)$amount),
				], 'csv');
				$imported++;
			} catch (ValidationException $e) {
				$errors[] = sprintf('%d: %s', $n + 1, $e->getMessage());
			}
		}
		return ['imported' => $imported, 'errors' => $errors];
	}

	// ---- intern -------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $data
	 * @throws ValidationException
	 */
	private function insertActual(string $userId, Demand $demand, array $data, string $source): void {
		$period = (string)($data['period'] ?? '');
		$kind = (string)($data['kind'] ?? '');
		if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
			throw new ValidationException('Unknown period');
		}
		if (!in_array($kind, self::ACTUAL_KINDS, true)) {
			throw new ValidationException('Unknown kind of actual value');
		}
		$poolId = $data['poolId'] ?? null;
		$poolId = $poolId === null || $poolId === '' ? null : (int)$poolId;
		if ($kind === 'effort') {
			$known = array_map(static fn ($p) => $p->getId(), $this->pools->findAll());
			if ($poolId === null || !in_array($poolId, $known, true)) {
				throw new ValidationException('Effort needs a pool');
			}
		} else {
			$poolId = null;
		}
		if (!is_numeric($data['amount'] ?? null) || (float)$data['amount'] < 0) {
			throw new ValidationException('The amount must be a positive number');
		}
		$amount = (float)$data['amount'];
		$this->rows->insert(self::ACTUALS, [
			'demandId' => $demand->getId(), 'period' => $period, 'kind' => $kind, 'poolId' => $poolId,
			'amount' => $amount, 'source' => $source, 'createdBy' => $userId, 'createdAt' => $this->time->getTime(),
		]);
		$this->audit->log(DemandService::ENTITY, $demand->getId(), 'actual', $userId, ['actual' => [null, sprintf('%s %s %s', $period, $kind, $amount)]], $source);
	}

	/** Höchste Eskalationsstufe offener Konflikte und Issues (Kennzeichen, kein Status). */
	public function escalationLevel(Demand $demand): int {
		$level = 0;
		foreach ($this->openConflicts($demand) as $c) {
			$level = max($level, (int)$c['escalationLevel']);
		}
		foreach ($this->rows->where('nx_issues', ['demandId' => $demand->getId(), 'status' => 'open']) as $i) {
			if ($i['createdAt'] >= $demand->getCreatedAt()) {
				$level = max($level, (int)$i['escalationLevel']);
			}
		}
		return $level;
	}

	/** @return list<array<string, mixed>> */
	private function openConflicts(Demand $demand): array {
		return array_values(array_filter(
			$this->rows->where('nx_conflicts', ['status' => 'open']),
			static fn (array $c) => in_array($demand->getId(), $c['demandIds'] ?? [], true) && $c['createdAt'] >= $demand->getCreatedAt(),
		));
	}

	/** @return array<string, mixed>|null */
	public function projectRow(Demand $demand): ?array {
		foreach (array_reverse($this->rows->where(self::PROJECTS, ['demandId' => $demand->getId()])) as $row) {
			if ($row['createdAt'] >= $demand->getCreatedAt()) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Stammdaten aus einem Beschluss übernehmen (z. B. neues Planende nach Change Request).
	 *
	 * @param array<string, mixed> $values
	 */
	public function saveProjectSystem(Demand $demand, array $values): void {
		$this->upsertProject('system', $demand, $values);
	}

	/** @param array<string, mixed> $values */
	private function upsertProject(string $userId, Demand $demand, array $values): void {
		$now = $this->time->getTime();
		$row = $this->projectRow($demand);
		if ($row === null) {
			$this->rows->insert(self::PROJECTS, $values + ['demandId' => $demand->getId(), 'createdBy' => $userId, 'createdAt' => $now, 'updatedAt' => $now]);
		} elseif ($values !== []) {
			$this->rows->update(self::PROJECTS, (int)$row['id'], $values + ['updatedAt' => $now]);
		}
		$this->permissions->reset();
	}

	/** @return list<array<string, mixed>> */
	public function milestones(Demand $demand): array {
		return $this->ofDemand(self::MILESTONES, $demand, 'dueDate');
	}

	/** @return list<array<string, mixed>> Zeilen ab dem Anlegen des Antrags (SQLite vergibt IDs neu) */
	private function ofDemand(string $table, Demand $demand, string $order): array {
		return array_values(array_filter(
			$this->rows->where($table, ['demandId' => $demand->getId()], $order),
			static fn (array $r) => $r['createdAt'] >= $demand->getCreatedAt(),
		));
	}

	/** @return list<ResourceNeed> */
	private function validNeeds(Demand $demand): array {
		return array_values(array_filter(
			$this->needs->findForDemand($demand->getId()),
			static fn (ResourceNeed $n) => $n->getUpdatedAt() >= $demand->getCreatedAt(),
		));
	}

	/**
	 * @param array<string, mixed> $report
	 * @return array{scope: string, time: string, cost: string, risk: string}
	 */
	private function rag(array $report): array {
		return ['scope' => (string)$report['ragScope'], 'time' => (string)$report['ragTime'], 'cost' => (string)$report['ragCost'], 'risk' => (string)$report['ragRisk']];
	}

	/** @throws ValidationException */
	private function date(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			throw new ValidationException('Unknown date');
		}
		return $value;
	}
}
