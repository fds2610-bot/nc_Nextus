<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Service\AuditService;
use OCA\Nexus\Service\GovernanceService;
use OCA\Nexus\Service\PermissionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class GovernanceServiceTest extends TestCase {
	/** @var array<string, list<array<string, mixed>>> */
	private array $tables = [];

	private function service(): GovernanceService {
		$rows = $this->createMock(Rows::class);
		$rows->method('where')->willReturnCallback(fn (string $table) => $this->tables[$table] ?? []);
		return new GovernanceService(
			$rows,
			$this->createMock(PermissionService::class),
			$this->createMock(AuditService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
		);
	}

	private function demand(?string $class, string $type = 'efficiency'): Demand {
		$d = new Demand();
		$d->setProjectClass($class);
		$d->setDemandType($type);
		return $d;
	}

	/** @param list<string>|null $classes */
	private function right(int $id, string $gate, ?array $classes, ?int $bodyId, ?int $quorum = null, ?int $validTo = null): array {
		return ['id' => $id, 'gateCode' => $gate, 'projectClasses' => $classes, 'bodyId' => $bodyId, 'quorumMin' => $quorum, 'validTo' => $validTo];
	}

	private function bodies(): void {
		$this->tables['nx_bodies'] = [
			['id' => 1, 'code' => 'PB', 'name' => 'Portfolio-Board', 'quorumMin' => 3, 'chairId' => null, 'requiredSeats' => []],
			['id' => 2, 'code' => 'RB', 'name' => 'Ressourcen-Board', 'quorumMin' => 2, 'chairId' => null, 'requiredSeats' => []],
		];
	}

	public function testWithoutRightThePortfolioOfficeDecidesDirectly(): void {
		$this->bodies();
		$decider = $this->service()->deciderFor('g2', $this->demand('M'));
		$this->assertSame('pmo', $decider['kind']);
		$this->assertNull($decider['body']);
	}

	public function testRightForTheClassBeatsRightForAllClasses(): void {
		$this->bodies();
		$this->tables['nx_decision_rights'] = [
			$this->right(1, 'g2', null, 2),
			$this->right(2, 'g2', ['L'], 1, 4),
		];
		$service = $this->service();
		$forL = $service->deciderFor('g2', $this->demand('L'));
		$this->assertSame('body', $forL['kind']);
		$this->assertSame('PB', $forL['body']['code']);
		$this->assertSame(4, $forL['quorumMin'], 'Quorum des Rechts geht dem Standard des Gremiums vor');
		$forM = $service->deciderFor('g2', $this->demand('M'));
		$this->assertSame('RB', $forM['body']['code']);
		$this->assertSame(2, $forM['quorumMin'], 'ohne eigenes Quorum gilt das des Gremiums');
	}

	public function testEndedRightsNoLongerApplyAndRightWithoutBodyMeansDirect(): void {
		$this->bodies();
		$this->tables['nx_decision_rights'] = [
			$this->right(1, 'g3', ['S'], 1, null, 1700000000),
			$this->right(2, 'g3', ['S'], null),
		];
		$this->assertSame('pmo', $this->service()->deciderFor('g3', $this->demand('S'))['kind']);
	}

	public function testGateSetFollowsTheProjectClass(): void {
		$this->tables['nx_gate_sets'] = [
			['id' => 1, 'name' => 'Standard', 'projectClasses' => [], 'demandTypes' => [], 'status' => 'active', 'version' => 1],
			['id' => 2, 'name' => 'Verkürzt', 'projectClasses' => ['XS', 'S'], 'demandTypes' => [], 'status' => 'active', 'version' => 1],
			['id' => 3, 'name' => 'Regulatorisch', 'projectClasses' => ['M', 'L'], 'demandTypes' => ['mandatory'], 'status' => 'active', 'version' => 1],
			['id' => 4, 'name' => 'Alt', 'projectClasses' => ['S'], 'demandTypes' => [], 'status' => 'retired', 'version' => 1],
		];
		$this->tables['nx_gate_defs'] = [];
		$service = $this->service();
		$this->assertSame('Verkürzt', $service->gateSetFor($this->demand('S'))['name']);
		$this->assertSame('Standard', $service->gateSetFor($this->demand('M'))['name']);
		$this->assertSame('Regulatorisch', $service->gateSetFor($this->demand('L', 'mandatory'))['name']);
		$this->assertSame('Standard', $service->gateSetFor($this->demand(null))['name'], 'ohne Klasse gilt das Set ohne Einschränkung');
	}
}
