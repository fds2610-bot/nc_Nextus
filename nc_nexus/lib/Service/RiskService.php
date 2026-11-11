<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Risiken, Issues und Maßnahmen (RSK-001, PRJ-003, Kapitel 02 §4.6): einheitliche Methodik
 * (Wahrscheinlichkeit × Auswirkung 1–5, Restrisiko), ein Risiko kann mehrere Vorhaben betreffen,
 * Issues mit Schweregrad und Eskalationsstufe, Maßnahmen landen in der gemeinsamen Nachverfolgung.
 */
class RiskService {
	public const CATEGORIES = ['technical', 'schedule', 'resources', 'supplier', 'financial', 'compliance', 'security', 'organizational'];
	public const RISK_STATUSES = ['open', 'mitigated', 'occurred', 'closed'];
	public const SEVERITIES = ['low', 'medium', 'high', 'critical'];
	private const RISKS = 'nx_risks';
	private const LINKS = 'nx_risk_links';
	private const ISSUES = 'nx_issues';

	public function __construct(
		private Rows $rows,
		private DemandService $demands,
		private DemandMapper $demandMapper,
		private ProjectService $projects,
		private ConditionService $conditions,
		private SettingsService $settings,
		private AuditService $audit,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	// ---- Risiken ------------------------------------------------------------------------------

	/**
	 * Risiken eines Vorhabens bzw. (ohne Vorhaben) alle Risiken lesbarer Vorhaben.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function risks(string $userId, ?int $demandId = null): array {
		$readable = [];
		foreach ($this->demands->findAll($userId) as $d) {
			$readable[$d->getId()] = $d;
		}
		$links = [];
		foreach ($this->rows->where(self::LINKS) as $l) {
			$links[$l['riskId']][] = $l;
		}
		$threshold = $this->settings->get('riskEscalationScore');
		$result = [];
		foreach ($this->rows->where(self::RISKS) as $risk) {
			$projects = [];
			foreach ($links[$risk['id']] ?? [] as $l) {
				$d = $readable[$l['demandId']] ?? null;
				if ($d !== null && $risk['createdAt'] >= $d->getCreatedAt()) {
					$projects[] = ['id' => $d->getId(), 'key' => $d->getProjectKey() ?? $d->getDemandKey(), 'title' => $d->getTitle(), 'primary' => $l['isPrimary'] === 1];
				}
			}
			if ($projects === [] || ($demandId !== null && !in_array($demandId, array_column($projects, 'id'), true))) {
				continue;
			}
			$score = $risk['probability'] * $risk['impact'];
			$residual = $risk['residualProbability'] !== null && $risk['residualImpact'] !== null ? $risk['residualProbability'] * $risk['residualImpact'] : null;
			$result[] = $risk + [
				'score' => $score,
				'residualScore' => $residual,
				'projects' => $projects,
				'crossProject' => count($projects) > 1,
				// Schwelle erreicht: Eskalationshinweis an Lenkungsausschuss bzw. PMO
				'escalationHint' => $risk['status'] === 'open' && ($score >= $threshold || $risk['impact'] === 5) && $risk['escalated'] !== 1,
				'actions' => $this->actionsOf('risk', $risk['id']),
				'can' => ['edit' => $this->canEditRisk($userId, $projects)],
			];
		}
		usort($result, static fn (array $a, array $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
		return $result;
	}

	/**
	 * Portfolio-Risikosicht (v_portfolio_risks): eskalierte und projektübergreifende Risiken mit
	 * Score und Restrisiko, dazu die Matrix Wahrscheinlichkeit × Auswirkung aller offenen Risiken.
	 *
	 * @return array<string, mixed>
	 */
	public function portfolio(string $userId): array {
		$all = array_values(array_filter($this->risks($userId), static fn (array $r) => in_array($r['status'], ['open', 'occurred'], true)));
		$matrix = [];
		foreach ($all as $r) {
			$matrix[$r['probability']][$r['impact']] = ($matrix[$r['probability']][$r['impact']] ?? 0) + 1;
		}
		return [
			'risks' => array_values(array_filter($all, static fn (array $r) => $r['escalated'] === 1 || $r['crossProject'] || $r['escalationHint'])),
			'matrix' => $matrix,
			'open' => count($all),
			'threshold' => $this->settings->get('riskEscalationScore'),
		];
	}

	/**
	 * Risiko anlegen oder ändern; Vorhaben über demandIds (das erste ist das primäre).
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function saveRisk(string $userId, ?int $id, array $data): array {
		$before = null;
		if ($id !== null) {
			$before = $this->rows->find(self::RISKS, $id) ?? throw new NotFoundException('Risk not found');
		}
		// Neue Verknüpfungen nur mit Leserecht; bestehende bleiben beim Bearbeiten unangetastet
		$relink = is_array($data['demandIds'] ?? null);
		$demandIds = array_values(array_unique(array_map('intval', $relink ? $data['demandIds'] : array_column($this->rows->where(self::LINKS, ['riskId' => (int)$id]), 'demandId'))));
		if ($demandIds === []) {
			throw new ValidationException('A risk belongs to at least one project');
		}
		$demands = [];
		foreach ($demandIds as $did) {
			$demands[] = $relink ? $this->demands->findReadable($userId, $did) : $this->demandMapper->find($did);
		}
		if (!$this->canEditRisk($userId, array_map(static fn (Demand $d) => ['id' => $d->getId()], $demands))) {
			throw new ForbiddenException('Only the project manager or the portfolio office may change the project');
		}
		$title = trim((string)($data['title'] ?? ($before['title'] ?? '')));
		if ($title === '' || mb_strlen($title) > 255) {
			throw new ValidationException('A title is required');
		}
		$category = (string)($data['category'] ?? ($before['category'] ?? 'technical'));
		$status = (string)($data['status'] ?? ($before['status'] ?? 'open'));
		if (!in_array($category, self::CATEGORIES, true) || !in_array($status, self::RISK_STATUSES, true)) {
			throw new ValidationException('Unknown category or status');
		}
		$scale = static function (mixed $v, bool $optional = false): ?int {
			if ($optional && ($v === null || $v === '')) {
				return null;
			}
			$n = (int)$v;
			if ($n < 1 || $n > 5) {
				throw new ValidationException('Probability and impact range from 1 to 5');
			}
			return $n;
		};
		$owner = trim((string)($data['ownerId'] ?? ($before['ownerId'] ?? $userId)));
		if (!$this->userManager->userExists($owner)) {
			throw new ValidationException('Unknown account or group');
		}
		$due = $data['dueDate'] ?? ($before['dueDate'] ?? null);
		if ($due !== null && $due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$due)) {
			throw new ValidationException('Unknown date');
		}
		$values = [
			'title' => $title,
			'description' => trim((string)($data['description'] ?? ($before['description'] ?? ''))) ?: null,
			'category' => $category,
			'probability' => $scale($data['probability'] ?? ($before['probability'] ?? 0)),
			'impact' => $scale($data['impact'] ?? ($before['impact'] ?? 0)),
			'mitigation' => trim((string)($data['mitigation'] ?? ($before['mitigation'] ?? ''))) ?: null,
			'residualProbability' => $scale($data['residualProbability'] ?? ($before['residualProbability'] ?? null), true),
			'residualImpact' => $scale($data['residualImpact'] ?? ($before['residualImpact'] ?? null), true),
			'ownerId' => $owner,
			'status' => $status,
			'dueDate' => $due === '' ? null : $due,
			'escalated' => (bool)($data['escalated'] ?? ($before['escalated'] ?? 0)) ? 1 : 0,
			'updatedAt' => $this->time->getTime(),
		];
		if ($id === null) {
			$id = $this->rows->insert(self::RISKS, $values + ['createdBy' => $userId, 'createdAt' => $this->time->getTime()]);
		} else {
			$this->rows->update(self::RISKS, $id, $values);
		}
		if ($relink) {
			$this->rows->deleteWhere(self::LINKS, ['riskId' => $id]);
			foreach ($demandIds as $i => $did) {
				$this->rows->insert(self::LINKS, ['riskId' => $id, 'demandId' => $did, 'isPrimary' => $i === 0 ? 1 : 0]);
			}
		}
		foreach ($demands as $d) {
			$this->audit->log(DemandService::ENTITY, $d->getId(), $before === null ? 'risk_create' : 'risk_update', $userId, [
				'risk' => [$before['title'] ?? null, $title],
				'score' => [$before === null ? null : $before['probability'] * $before['impact'], $values['probability'] * $values['impact']],
				'status' => [$before['status'] ?? null, $status],
			]);
		}
		foreach ($this->risks($userId) as $r) {
			if ($r['id'] === $id) {
				return $r;
			}
		}
		throw new NotFoundException('Risk not found');
	}

	// ---- Issues -------------------------------------------------------------------------------

	/** @return list<array<string, mixed>> */
	public function issues(string $userId, int $demandId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$canEdit = $this->projects->canManage($userId, $demand);
		$result = [];
		foreach (array_reverse($this->rows->where(self::ISSUES, ['demandId' => $demandId])) as $i) {
			if ($i['createdAt'] >= $demand->getCreatedAt()) {
				$result[] = $i + ['actions' => $this->actionsOf('issue', $i['id']), 'can' => ['edit' => $canEdit]];
			}
		}
		return $result;
	}

	/**
	 * @param array<string, mixed> $data title, description, severity, ownerId, status, dueDate, escalationLevel
	 * @return list<array<string, mixed>> Issues des Vorhabens
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function saveIssue(string $userId, int $demandId, ?int $id, array $data): array {
		$demand = $this->projects->manageable($userId, $demandId);
		$before = null;
		if ($id !== null) {
			$before = $this->rows->find(self::ISSUES, $id);
			if ($before === null || $before['demandId'] !== $demandId) {
				throw new NotFoundException('Issue not found');
			}
		}
		$title = trim((string)($data['title'] ?? ($before['title'] ?? '')));
		if ($title === '' || mb_strlen($title) > 255) {
			throw new ValidationException('A title is required');
		}
		$severity = (string)($data['severity'] ?? ($before['severity'] ?? 'medium'));
		$status = (string)($data['status'] ?? ($before['status'] ?? 'open'));
		$level = (int)($data['escalationLevel'] ?? ($before['escalationLevel'] ?? 0));
		if (!in_array($severity, self::SEVERITIES, true) || !in_array($status, ['open', 'resolved'], true) || $level < 0 || $level > 3) {
			throw new ValidationException('Unknown category or status');
		}
		$owner = trim((string)($data['ownerId'] ?? ($before['ownerId'] ?? $userId)));
		if (!$this->userManager->userExists($owner)) {
			throw new ValidationException('Unknown account or group');
		}
		$due = $data['dueDate'] ?? ($before['dueDate'] ?? null);
		if ($due !== null && $due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$due)) {
			throw new ValidationException('Unknown date');
		}
		$values = [
			'title' => $title,
			'description' => trim((string)($data['description'] ?? ($before['description'] ?? ''))) ?: null,
			'severity' => $severity, 'ownerId' => $owner, 'status' => $status,
			'dueDate' => $due === '' ? null : $due, 'escalationLevel' => $level,
			'updatedAt' => $this->time->getTime(),
		];
		if ($id === null) {
			$this->rows->insert(self::ISSUES, $values + ['demandId' => $demandId, 'createdBy' => $userId, 'createdAt' => $this->time->getTime()]);
		} else {
			$this->rows->update(self::ISSUES, $id, $values);
		}
		$this->audit->log(DemandService::ENTITY, $demand->getId(), $before === null ? 'issue_create' : 'issue_update', $userId, [
			'issue' => [$before['title'] ?? null, $title],
			'severity' => [$before['severity'] ?? null, $severity],
			'status' => [$before['status'] ?? null, $status],
			'escalationLevel' => [$before['escalationLevel'] ?? null, $level],
		]);
		return $this->issues($userId, $demandId);
	}

	// ---- Maßnahmen ----------------------------------------------------------------------------

	/**
	 * Maßnahme aus einem Risiko, Issue oder Statusbericht – landet in der Beschlussverfolgung.
	 *
	 * @param array<string, mixed> $data title, ownerId, dueDate
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function addAction(string $userId, int $demandId, string $originType, ?int $originId, array $data): void {
		if (!in_array($originType, ['risk', 'issue', 'status_report'], true)) {
			throw new ValidationException('Unknown kind');
		}
		$demand = $this->projects->manageable($userId, $demandId);
		$items = $this->conditions->validate([$data]);
		if ($items === []) {
			throw new ValidationException('Every condition needs a title');
		}
		$this->conditions->createFor($userId, $demand, null, null, 'action', $items, $originType, $originId);
	}

	/** @return list<array<string, mixed>> */
	private function actionsOf(string $originType, int $originId): array {
		return array_map(
			static fn (array $c) => array_intersect_key($c, array_flip(['id', 'title', 'ownerId', 'dueDate', 'status'])),
			$this->rows->where(ConditionService::TABLE, ['originType' => $originType, 'originId' => $originId], 'dueDate'),
		);
	}

	/** @param list<array<string, mixed>> $projects */
	private function canEditRisk(string $userId, array $projects): bool {
		foreach ($projects as $p) {
			try {
				$demand = $this->demandMapper->find((int)$p['id']);
			} catch (\Throwable) {
				continue;
			}
			if ($this->projects->canManage($userId, $demand)) {
				return true;
			}
		}
		return false;
	}
}
