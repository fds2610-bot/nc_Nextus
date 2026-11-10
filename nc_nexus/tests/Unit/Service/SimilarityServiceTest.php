<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Service\PermissionService;
use OCA\Nexus\Service\SimilarityService;
use PHPUnit\Framework\TestCase;

class SimilarityServiceTest extends TestCase {
	private SimilarityService $service;

	protected function setUp(): void {
		$demands = [];
		foreach ([
			[1, 'Ticketsystem ablösen', 'Das alte Ticketsystem wird nicht mehr gewartet', 'submitted', 'alice'],
			[2, 'Neues Ticket-System für den Service Desk', null, 'deferred', 'bob'],
			[3, 'Ticketsystem modernisieren', null, 'rejected', 'alice'],
			[4, 'Ticketsystem ablösen', null, 'draft', 'alice'],
			[5, 'Kantine renovieren', null, 'submitted', 'alice'],
		] as [$id, $title, $problem, $status, $requestor]) {
			$d = new Demand();
			$d->setId($id);
			$d->setTitle($title);
			$d->setProblemStatement($problem);
			$d->setStatus($status);
			$d->setRequestorId($requestor);
			$demands[] = $d;
		}
		$mapper = $this->createMock(DemandMapper::class);
		$mapper->method('findAll')->willReturn($demands);
		$permissions = $this->createMock(PermissionService::class);
		// alice sieht nur ihre eigenen Anträge
		$permissions->method('canReadDemand')->willReturnCallback(fn (string $uid, Demand $d) => $d->getRequestorId() === $uid);
		$this->service = new SimilarityService($mapper, $permissions);
	}

	public function testStemsNormalizeUmlautsAndDropStopwords(): void {
		$this->assertSame(['ticket' => 1, 'abloes' => 1], $this->service->stems('Das Ticketsystem ablösen'));
	}

	public function testFindsOpenSimilarDemandsOnly(): void {
		$result = $this->service->similar('alice', 'Ticketsystem ablösen');
		$ids = array_map(static fn ($m) => $m['demand']->getId(), $result);
		// 1 passt, 3 ist abgelehnt, 4 ist ein Entwurf, 5 passt nicht
		$this->assertSame([1], $ids);
	}

	public function testUnreadableMatchesDoNotAppear(): void {
		// Antrag 2 (bob) passt am besten, ist für alice aber nicht lesbar
		$result = $this->service->similar('alice', 'Ticketsystem für den Service Desk');
		$this->assertNotEmpty($result);
		foreach ($result as $match) {
			$this->assertSame('alice', $match['demand']->getRequestorId());
		}
	}

	public function testExcludesTheDemandItself(): void {
		$result = $this->service->similar('alice', 'Ticketsystem ablösen', '', 1);
		$this->assertSame([], $result);
	}

	public function testShortOrEmptyInputFindsNothing(): void {
		$this->assertSame([], $this->service->similar('alice', 'IT'));
	}
}
