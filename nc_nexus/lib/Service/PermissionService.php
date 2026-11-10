<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\OrgUnitMapper;
use OCA\Nexus\Db\RoleAssignment;
use OCA\Nexus\Db\RoleAssignmentMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Rechte nach der Berechtigungsmatrix (Kapitel 06, §3) auf Basis kontextgebundener Rollen.
 *
 * Eine Rolle gilt global oder für eine Org-Einheit samt allen Untereinheiten. Nextcloud-Admins
 * gelten als globales PMO (Einstieg, solange noch keine Rollen vergeben sind).
 */
class PermissionService {
	/** Rollen aus ROLE_ASSIGNMENT (Kapitel 05). */
	public const ROLES = [
		'requestor', 'sponsor', 'project_manager', 'pool_manager', 'portfolio_manager', 'board_member',
		'steering_member', 'benefit_owner', 'reviewer', 'controller', 'auditor', 'data_owner', 'viewer', 'admin',
	];

	/** Lesen fremder Anträge im Geltungsbereich (Matrix: Demand fremd = L/S). */
	private const READ_DEMANDS = ['portfolio_manager', 'board_member', 'pool_manager', 'auditor', 'admin', 'sponsor', 'project_manager'];
	/** Organisation und Rollen pflegen. */
	private const MANAGE_ORG = ['portfolio_manager', 'admin'];

	/** @var array<string, list<RoleAssignment>> */
	private array $assignments = [];
	/** @var array<int, int|null>|null Org-Einheit => Eltern */
	private ?array $parents = null;

	public function __construct(
		private RoleAssignmentMapper $roles,
		private OrgUnitMapper $orgUnits,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	public function isAdmin(string $userId): bool {
		return $this->groupManager->isAdmin($userId);
	}

	public function canManageOrg(string $userId): bool {
		return $this->isAdmin($userId) || $this->hasRole($userId, self::MANAGE_ORG, null);
	}

	/**
	 * Triage (G0/G1) entscheidet das PMO des Bereichs (RACI 01 §4.1). Den eigenen Antrag triagiert
	 * niemand selbst (Funktionstrennung, 01 §8) – auch kein Admin.
	 */
	public function canTriage(string $userId, Demand $demand): bool {
		return $demand->getRequestorId() !== $userId
			&& ($this->isAdmin($userId) || $this->hasRole($userId, self::MANAGE_ORG, $demand->getOrgUnitId()));
	}

	/** Hat das Konto irgendwo eine PMO-Rolle (Arbeitsvorrat in der Navigation)? */
	public function isTriager(string $userId): bool {
		if ($this->isAdmin($userId)) {
			return true;
		}
		foreach ($this->assignmentsOf($userId) as $assignment) {
			if (in_array($assignment->getRole(), self::MANAGE_ORG, true)) {
				return true;
			}
		}
		return false;
	}

	public function canReadDemand(string $userId, Demand $demand): bool {
		return $demand->getRequestorId() === $userId
			|| ($demand->getStatus() === 'further_analysis' && $demand->getAnalysisOwner() === $userId)
			|| $this->isAdmin($userId)
			|| $this->hasRole($userId, self::READ_DEMANDS, $demand->getOrgUnitId());
	}

	/**
	 * Hat das Konto eine der Rollen global oder für die Org-Einheit bzw. eine übergeordnete Einheit?
	 * Ohne Org-Einheit zählen nur globale Rollen.
	 *
	 * @param list<string> $roles
	 */
	public function hasRole(string $userId, array $roles, ?int $orgUnitId): bool {
		$scope = $orgUnitId === null ? [] : $this->ancestorsInclusive($orgUnitId);
		foreach ($this->assignmentsOf($userId) as $assignment) {
			if (!in_array($assignment->getRole(), $roles, true)) {
				continue;
			}
			if ($assignment->getScopeType() === 'global'
				|| ($assignment->getScopeType() === 'org_unit' && in_array($assignment->getScopeId(), $scope, true))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Heute gültige Rollen des Kontos (für die Oberfläche).
	 *
	 * @return list<RoleAssignment>
	 */
	public function assignmentsOf(string $userId): array {
		if (!isset($this->assignments[$userId])) {
			$user = $this->userManager->get($userId);
			$groups = $user === null ? [] : $this->groupManager->getUserGroupIds($user);
			$today = date('Y-m-d', $this->time->getTime());
			$this->assignments[$userId] = array_values(array_filter(
				$this->roles->findForPrincipal($userId, $groups),
				static fn (RoleAssignment $a) => $a->isValidOn($today),
			));
		}
		return $this->assignments[$userId];
	}

	/** Zwischenspeicher leeren, nachdem Rollen oder Org-Einheiten geändert wurden. */
	public function reset(): void {
		$this->assignments = [];
		$this->parents = null;
	}

	/** @return list<int> die Einheit und alle übergeordneten */
	public function ancestorsInclusive(int $orgUnitId): array {
		if ($this->parents === null) {
			$this->parents = [];
			foreach ($this->orgUnits->findAll() as $unit) {
				$this->parents[$unit->getId()] = $unit->getParentId();
			}
		}
		$chain = [];
		for ($id = $orgUnitId; $id !== null && !in_array($id, $chain, true); $id = $this->parents[$id] ?? null) {
			$chain[] = $id;
		}
		return $chain;
	}
}
