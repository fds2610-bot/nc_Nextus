<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\OrgUnit;
use OCA\Nexus\Db\OrgUnitMapper;
use OCA\Nexus\Db\RoleAssignment;
use OCA\Nexus\Db\RoleAssignmentMapper;
use OCA\Nexus\Service\PermissionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PermissionServiceTest extends TestCase {
	private RoleAssignmentMapper&MockObject $roles;
	private IGroupManager&MockObject $groups;
	private PermissionService $service;
	private bool $admin = false;

	protected function setUp(): void {
		$this->roles = $this->createMock(RoleAssignmentMapper::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$this->groups->method('getUserGroupIds')->willReturn(['pmo']);
		$this->groups->method('isAdmin')->willReturnCallback(fn () => $this->admin);

		// IT (1) › Infrastruktur (2); Vertrieb (3)
		$units = [];
		foreach ([[1, null], [2, 1], [3, null]] as [$id, $parent]) {
			$unit = new OrgUnit();
			$unit->setId($id);
			$unit->setParentId($parent);
			$units[] = $unit;
		}
		$orgUnits = $this->createMock(OrgUnitMapper::class);
		$orgUnits->method('findAll')->willReturn($units);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794268800); // 10.11.2026
		$this->service = new PermissionService($this->roles, $orgUnits, $this->groups, $users, $time);
	}

	private function role(string $role, string $scopeType, ?int $scopeId, ?string $from = null, ?string $to = null): RoleAssignment {
		$a = new RoleAssignment();
		$a->setRole($role);
		$a->setScopeType($scopeType);
		$a->setScopeId($scopeId);
		$a->setValidFrom($from);
		$a->setValidTo($to);
		return $a;
	}

	private function demand(string $requestor, ?int $orgUnitId): Demand {
		$d = new Demand();
		$d->setStatus('submitted');
		$d->setRequestorId($requestor);
		$d->setOrgUnitId($orgUnitId);
		return $d;
	}

	public function testRequestorAlwaysReadsOwnDemand(): void {
		$this->roles->method('findForPrincipal')->willReturn([]);
		$this->assertTrue($this->service->canReadDemand('alice', $this->demand('alice', 3)));
		$this->assertFalse($this->service->canReadDemand('alice', $this->demand('bob', 3)));
	}

	public function testRoleOnParentUnitCoversSubunits(): void {
		$this->roles->method('findForPrincipal')->willReturn([$this->role('board_member', 'org_unit', 1)]);
		$this->assertTrue($this->service->canReadDemand('alice', $this->demand('bob', 2)));
		$this->assertFalse($this->service->canReadDemand('alice', $this->demand('bob', 3)));
		$this->assertFalse($this->service->canReadDemand('alice', $this->demand('bob', null)));
	}

	public function testGlobalRoleCoversDemandsWithoutUnit(): void {
		$this->roles->method('findForPrincipal')->willReturn([$this->role('portfolio_manager', 'global', null)]);
		$this->assertTrue($this->service->canReadDemand('alice', $this->demand('bob', null)));
		$this->assertTrue($this->service->canManageOrg('alice'));
	}

	public function testUnitScopedPmoDoesNotManageOrganization(): void {
		$this->roles->method('findForPrincipal')->willReturn([$this->role('portfolio_manager', 'org_unit', 1)]);
		$this->assertFalse($this->service->canManageOrg('alice'));
	}

	public function testExpiredDeputyRoleIsIgnored(): void {
		$this->roles->method('findForPrincipal')->willReturn([$this->role('board_member', 'global', null, '2026-10-01', '2026-10-31')]);
		$this->assertFalse($this->service->canReadDemand('alice', $this->demand('bob', 1)));
	}

	public function testDraftsArePrivateEvenForGlobalPmoAndAdmin(): void {
		$this->roles->method('findForPrincipal')->willReturn([$this->role('portfolio_manager', 'global', null)]);
		$this->admin = true;
		$draft = $this->demand('bob', 1);
		$draft->setStatus('draft');
		$this->assertFalse($this->service->canReadDemand('alice', $draft));
		$this->assertTrue($this->service->canReadDemand('bob', $draft));
	}

	public function testViewerDoesNotReadDemands(): void {
		$this->roles->method('findForPrincipal')->willReturn([$this->role('viewer', 'global', null)]);
		$this->assertFalse($this->service->canReadDemand('alice', $this->demand('bob', 1)));
	}

	public function testNextcloudAdminActsAsGlobalPmo(): void {
		$this->roles->method('findForPrincipal')->willReturn([]);
		$this->admin = true;
		$this->assertTrue($this->service->canManageOrg('root'));
		$this->assertTrue($this->service->canReadDemand('root', $this->demand('bob', 3)));
	}
}
