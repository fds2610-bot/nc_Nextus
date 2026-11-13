<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\ObjectiveMapper;
use OCA\Nexus\Db\PlanningLinkMapper;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Db\ScoringMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Planning\PlanningProviderRegistry;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Datenqualitäts-Cockpit (DAT-001, Kapitel 05 §10, 06 §2.6): welche Pflichtangaben fehlen wo,
 * welche Fristen sind überschritten – je Regel mit Daten-Owner und betroffenen Objekten.
 * „Keine Entscheidung ohne Daten“ lässt sich nur durchhalten, wenn Lücken sichtbar sind.
 */
class QualityService {
	/** Status, in denen ein Vorhaben aktiv bearbeitet wird */
	private const OPEN = ['submitted', 'query', 'further_analysis', 'in_qualification', 'candidate', 'prioritized', 'released', 'in_execution', 'paused'];
	private const QUALIFIED = ['in_qualification', 'candidate', 'prioritized', 'released', 'in_execution', 'paused', 'completed'];
	private const PORTFOLIO = ['candidate', 'prioritized', 'released', 'in_execution'];
	private const PROJECTS = ['prioritized', 'released', 'in_execution', 'paused'];
	private const STALE_DAYS = 90;

	public function __construct(
		private DemandService $demands,
		private AssessmentService $assessments,
		private ScoringMapper $scoring,
		private BusinessCaseService $businessCases,
		private ResourceNeedMapper $needs,
		private ObjectiveMapper $objectives,
		private PlanningLinkMapper $planningLinks,
		private PlanningProviderRegistry $planningProviders,
		private PlanningService $planning,
		private CapacityService $capacity,
		private ConditionService $conditions,
		private PermissionService $permissions,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array<string, mixed> Regeln mit Befunden und Kennzahl
	 * @throws ForbiddenException
	 */
	public function cockpit(string $userId): array {
		if (!$this->permissions->isTriager($userId) && !$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may open the data quality cockpit');
		}
		$now = $this->time->getTime();
		$today = date('Y-m-d', $now);
		$demands = array_values(array_filter($this->demands->findAll($userId), static fn (Demand $d) => $d->getStatus() !== 'draft'));
		$needDemands = [];
		foreach ($this->needs->findRange('0000-00', '9999-12') as $need) {
			$needDemands[$need->getDemandId()][] = $need->getUpdatedAt();
		}
		$linked = [];
		foreach ($this->objectives->links() as $l) {
			$linked[$l['demandId']][] = $l['createdAt'];
		}
		$allAssessments = $this->scoring->assessments();

		$rules = [];
		$add = static function (string $key, string $owner, array $items) use (&$rules): void {
			$rules[] = ['key' => $key, 'owner' => $owner, 'count' => count($items), 'items' => $items];
		};
		$item = static fn (Demand $d, string $detail = '') => [
			'type' => 'demand', 'id' => $d->getId(), 'key' => $d->getProjectKey() ?? $d->getDemandKey(),
			'title' => $d->getTitle(), 'status' => $d->getStatus(), 'detail' => $detail,
		];
		$where = static function (array $statuses, callable $test) use ($demands, $item): array {
			$out = [];
			foreach ($demands as $d) {
				if (in_array($d->getStatus(), $statuses, true)) {
					$detail = $test($d);
					if ($detail !== false) {
						$out[] = $item($d, is_string($detail) ? $detail : '');
					}
				}
			}
			return $out;
		};
		$after = static fn (Demand $d, array $times) => array_filter($times, static fn (int $t) => $t >= $d->getCreatedAt()) !== [];

		$add('missing_org_unit', 'requestor', $where(self::OPEN, static fn (Demand $d) => $d->getOrgUnitId() === null));
		$add('missing_class', 'portfolio_office', $where(self::QUALIFIED, static fn (Demand $d) => $d->getProjectClass() === null));
		$add('missing_review', 'portfolio_office', $where(['candidate', 'prioritized'], function (Demand $d) use ($allAssessments) {
			$current = AssessmentService::current($this->assessments->valid($d, $allAssessments));
			return $current === null || $current['kind'] !== 'review';
		}));
		$add('business_case_incomplete', 'sponsor', $where(self::PORTFOLIO, function (Demand $d) {
			$missing = $this->businessCases->missingFor($d);
			return $missing === [] ? false : implode(', ', $missing);
		}));
		$add('missing_needs', 'requestor', $where(['in_qualification', 'candidate', 'prioritized'], static fn (Demand $d) => !$after($d, $needDemands[$d->getId()] ?? [])));
		$add('missing_objectives', 'portfolio_office', $where(self::PROJECTS, static fn (Demand $d) => !$after($d, $linked[$d->getId()] ?? [])));
		$add('resubmission_overdue', 'portfolio_office', $where(['deferred', 'paused'], static fn (Demand $d) => $d->getResubmissionDate() !== null && $d->getResubmissionDate() < $today ? (string)$d->getResubmissionDate() : false));
		$add('analysis_overdue', 'analysis_owner', $where(['further_analysis'], static fn (Demand $d) => $d->getAnalysisDue() !== null && $d->getAnalysisDue() < $today ? $d->getAnalysisOwner() . ' · ' . $d->getAnalysisDue() : false));
		$add('query_overdue', 'requestor', $where(['query'], static fn (Demand $d) => $d->getQueryDue() !== null && $d->getQueryDue() < $today ? (string)$d->getQueryDue() : false));
		$add('stale', 'requestor', $where(self::OPEN, static fn (Demand $d) => $d->getUpdatedAt() < $now - self::STALE_DAYS * 86400 ? date('Y-m-d', $d->getUpdatedAt()) : false));
		if ($this->planningProviders->hasLiveProvider($userId)) {
			$add('no_planning_link', 'project_manager', $where(['released', 'in_execution'], fn (Demand $d) => $this->planningLinks->findByDemand($d->getId()) === null));
		}
		// Abgleichsfehler (Kapitel 07 §4.2) – auch ohne live verfügbares Tool, gerade dann
		$syncErrors = $this->planning->syncErrors();
		$add('planning_sync_failed', 'project_manager', $where(PlanningService::LINKABLE, static fn (Demand $d) => $syncErrors[$d->getId()] ?? false));

		$overdue = [];
		foreach ($this->conditions->list($userId) as $c) {
			if ($c['overdue']) {
				$overdue[] = ['type' => 'condition', 'id' => $c['demandId'], 'key' => $c['demandKey'], 'title' => $c['title'], 'status' => $c['kind'], 'detail' => $c['ownerId'] . ' · ' . $c['dueDate']];
			}
		}
		$add('conditions_overdue', 'condition_owner', $overdue);

		$pools = [];
		$from = date('Y-m', $now);
		foreach ($this->capacity->pools($userId) as $pool) {
			$rows = $this->capacity->capacityRows($pool, $from, 3);
			$empty = array_filter($rows, static fn (array $r) => (float)$r['project'] <= 0);
			if ($empty !== []) {
				$pools[] = ['type' => 'pool', 'id' => $pool->getId(), 'key' => $pool->getCode(), 'title' => $pool->getName(), 'status' => '', 'detail' => implode(', ', array_column($empty, 'period'))];
			}
		}
		$add('pool_capacity_missing', 'pool_manager', $pools);

		$covered = [];
		foreach ($demands as $d) {
			foreach ($this->objectives->links() as $l) {
				if ($l['demandId'] === $d->getId() && $l['createdAt'] >= $d->getCreatedAt() && in_array($d->getStatus(), array_merge(self::OPEN, ['completed']), true)) {
					$covered[$l['objectiveId']] = true;
				}
			}
		}
		$uncovered = [];
		$all = $this->objectives->findAll();
		$parents = array_flip(array_filter(array_map(static fn ($o) => $o->getParentId(), $all)));
		// Blattziele ohne laufendes Vorhaben (übergeordnete Ziele gelten über ihre Unterziele)
		foreach ($all as $o) {
			if (!isset($parents[$o->getId()]) && !isset($covered[$o->getId()]) && ($o->getValidTo() === null || $o->getValidTo() >= $today)) {
				$uncovered[] = ['type' => 'objective', 'id' => $o->getId(), 'key' => $o->getCode(), 'title' => $o->getTitle(), 'status' => '', 'detail' => ''];
			}
		}
		$add('objectives_uncovered', 'executive', $uncovered);

		// Kennzahl: Anteil offener Vorhaben ohne Befund
		$open = array_filter($demands, static fn (Demand $d) => in_array($d->getStatus(), self::OPEN, true));
		$flagged = [];
		foreach ($rules as $rule) {
			foreach ($rule['items'] as $i) {
				if ($i['type'] === 'demand') {
					$flagged[$i['id']] = true;
				}
			}
		}
		$clean = count(array_filter($open, static fn (Demand $d) => !isset($flagged[$d->getId()])));
		return [
			'rules' => $rules,
			'openDemands' => count($open),
			'cleanDemands' => $clean,
			'score' => $open === [] ? 100 : (int)round($clean / count($open) * 100),
			'findings' => array_sum(array_column($rules, 'count')),
		];
	}
}
