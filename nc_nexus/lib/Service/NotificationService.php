<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\RoleAssignmentMapper;
use OCA\Nexus\Db\Rows;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Benachrichtigungen (Kapitel 06 §4.4) über die Nextcloud-Benachrichtigungen. Jeder Anlass wird
 * nur einmal gemeldet (Fingerabdruck in nx_notified); der tägliche Job erinnert an Fristen und
 * schickt dem PMO einen Tagesüberblick.
 */
class NotificationService {
	public function __construct(
		private IManager $manager,
		private Rows $rows,
		private DemandMapper $demands,
		private PoolMapper $pools,
		private RoleAssignmentMapper $roles,
		private IGroupManager $groups,
		private SettingsService $settings,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $params
	 */
	public function notify(string $user, string $subject, array $params, string $objectType, string $objectId, ?string $once = null): void {
		if ($user === '' || $user === 'system') {
			return;
		}
		if ($once !== null && !$this->firstTime($once . ':' . $user)) {
			return;
		}
		try {
			$n = $this->manager->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($user)
				->setDateTime(new \DateTime('@' . $this->time->getTime()))
				->setObject($objectType, $objectId)
				->setSubject($subject, $params);
			$this->manager->notify($n);
		} catch (\Throwable $e) {
			$this->logger->warning('Nexus: notification failed', ['exception' => $e]);
		}
	}

	/** Beschluss gefasst → Antragsteller und Projektleitung */
	public function decided(Demand $demand, string $step, string $outcome, string $decidedBy): void {
		$project = $this->projectRow($demand);
		foreach (array_unique(array_filter([$demand->getRequestorId(), $project['managerId'] ?? null, $project['sponsorId'] ?? null])) as $user) {
			if ($user !== $decidedBy) {
				$this->notify((string)$user, 'gate_decided', ['title' => $demand->getTitle(), 'gate' => strtoupper($step), 'outcome' => $outcome], 'demand', (string)$demand->getId());
			}
		}
	}

	/** Change Request eingereicht → PMO */
	public function changeSubmitted(Demand $demand, string $title): void {
		foreach ($this->pmo() as $user) {
			$this->notify($user, 'change_submitted', ['project' => $demand->getTitle(), 'title' => $title], 'demand', (string)$demand->getId());
		}
	}

	/**
	 * Neue und eskalierte Konflikte → Pool-Management und PMO.
	 *
	 * @param list<array<string, mixed>> $created
	 * @param list<array<string, mixed>> $escalated
	 */
	public function conflicts(array $created, array $escalated): void {
		$pools = [];
		foreach ($this->pools->findAll() as $p) {
			$pools[$p->getId()] = $p;
		}
		foreach ([['conflict_detected', $created], ['conflict_escalated', $escalated]] as [$subject, $list]) {
			foreach ($list as $c) {
				$pool = $pools[(int)($c['poolId'] ?? 0)] ?? null;
				$users = $this->pmo();
				if ($pool?->getManagerUserId() !== null) {
					$users[] = (string)$pool->getManagerUserId();
				}
				foreach (array_unique($users) as $user) {
					$this->notify($user, $subject, ['pool' => $pool?->getName() ?? (string)$c['conflictType'], 'period' => (string)($c['period'] ?? ''), 'level' => (string)$c['escalationLevel']],
						'conflict', (string)($c['id'] ?? $c['fingerprint']), $subject . ':' . ($c['fingerprint'] ?? '') . ':' . $c['escalationLevel']);
				}
			}
		}
	}

	/**
	 * Täglich: Auflagen fällig in n Tagen bzw. überfällig, überfällige Statusberichte,
	 * Tagesüberblick fürs PMO.
	 *
	 * @param list<array{demand: Demand, due: string}> $overdueReports
	 */
	public function daily(array $overdueReports, int $toTriage, int $openConflicts): void {
		$today = date('Y-m-d', $this->time->getTime());
		$soon = date('Y-m-d', $this->time->getTime() + (int)$this->settings->get('conditionReminderDays') * 86400);
		$overdueConditions = 0;
		foreach ($this->rows->where(ConditionService::TABLE, ['status' => 'open']) as $c) {
			if ($c['dueDate'] < $today) {
				$overdueConditions++;
				$this->notify((string)$c['ownerId'], 'condition_overdue', ['title' => $c['title'], 'due' => $c['dueDate']], 'condition', (string)$c['id'], 'condition_overdue:' . $c['id']);
			} elseif ($c['dueDate'] <= $soon) {
				$this->notify((string)$c['ownerId'], 'condition_due', ['title' => $c['title'], 'due' => $c['dueDate']], 'condition', (string)$c['id'], 'condition_due:' . $c['id']);
			}
		}
		foreach ($overdueReports as $r) {
			$project = $this->projectRow($r['demand']);
			$user = $project['managerId'] ?? $r['demand']->getRequestorId();
			$this->notify((string)$user, 'report_overdue', ['title' => $r['demand']->getTitle()], 'demand', (string)$r['demand']->getId(), 'report_overdue:' . $r['demand']->getId() . ':' . $r['due']);
		}
		foreach ($this->pmo() as $user) {
			$this->notify($user, 'digest', ['triage' => $toTriage, 'conditions' => $overdueConditions, 'conflicts' => $openConflicts, 'reports' => count($overdueReports)],
				'digest', $today, 'digest:' . $today);
		}
	}

	/** @return list<string> Konten mit globaler PMO-Rolle (Gruppen aufgelöst), sonst die Administration */
	public function pmo(): array {
		$users = [];
		foreach ($this->roles->findAll() as $a) {
			if ($a->getRole() !== 'portfolio_manager' || $a->getScopeType() !== 'global') {
				continue;
			}
			if ($a->getPrincipalType() === 'user') {
				$users[] = $a->getPrincipalId();
			} else {
				foreach ($this->groups->get($a->getPrincipalId())?->getUsers() ?? [] as $u) {
					$users[] = $u->getUID();
				}
			}
		}
		if ($users === []) {
			foreach ($this->groups->get('admin')?->getUsers() ?? [] as $u) {
				$users[] = $u->getUID();
			}
		}
		return array_values(array_unique($users));
	}

	private function firstTime(string $fingerprint): bool {
		$fingerprint = mb_substr($fingerprint, 0, 191);
		if ($this->rows->where('nx_notified', ['fingerprint' => $fingerprint]) !== []) {
			return false;
		}
		try {
			$this->rows->insert('nx_notified', ['fingerprint' => $fingerprint, 'createdAt' => $this->time->getTime()]);
		} catch (\Throwable) {
			return false;
		}
		return true;
	}

	/** @return array<string, mixed>|null */
	private function projectRow(Demand $demand): ?array {
		foreach (array_reverse($this->rows->where('nx_projects', ['demandId' => $demand->getId()])) as $row) {
			if ($row['createdAt'] >= $demand->getCreatedAt()) {
				return $row;
			}
		}
		return null;
	}

	/** @return list<Demand> */
	public function allDemands(): array {
		return $this->demands->findAll();
	}
}
