<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\AuditEntry;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCA\Nexus\Service\AuditService;
use OCA\Nexus\Service\CommentService;
use OCA\Nexus\Service\DemandService;
use OCA\Nexus\Service\PermissionService;
use OCA\Nexus\Service\TriageService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TriageServiceTest extends TestCase {
	private DemandService&MockObject $demands;
	private PermissionService&MockObject $permissions;
	private AuditService&MockObject $audit;
	private TriageService $service;
	/** @var array<int, Demand> */
	private array $store = [];

	protected function setUp(): void {
		$this->demands = $this->createMock(DemandService::class);
		$this->demands->method('findReadable')->willReturnCallback(fn (string $uid, int $id) => $this->store[$id] ?? throw new NotFoundException('x'));
		$mapper = $this->createMock(DemandMapper::class);
		$mapper->method('update')->willReturnArgument(0);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->permissions->method('canTriage')->willReturnCallback(fn (string $uid, Demand $d) => $d->getRequestorId() !== $uid);
		$this->audit = $this->createMock(AuditService::class);
		$this->audit->method('log')->willReturn(new AuditEntry());
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(fn (string $uid) => $uid === 'analyst');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794268800); // 10.11.2026
		$this->service = new TriageService($this->demands, $mapper, $this->permissions, $this->audit, $this->createMock(IDBConnection::class), $users, $time, $this->createMock(CommentService::class));

		$this->store[7] = $this->demand(7, 'submitted');
		$this->store[8] = $this->demand(8, 'in_qualification');
	}

	private function demand(int $id, string $status): Demand {
		$d = new Demand();
		$d->setId($id);
		$d->setRequestorId('alice');
		$d->setStatus($status);
		return $d;
	}

	/** @return array<string, array{0: array<string, mixed>, 1: string}> */
	public static function validDecisions(): array {
		return [
			'angenommen mit Klasse' => [['code' => 'accepted', 'projectClass' => 'M'], 'in_qualification'],
			'weitere Analyse' => [['code' => 'further_analysis', 'analysisOwner' => 'analyst', 'analysisDue' => '2026-11-20'], 'further_analysis'],
			'zurückgestellt' => [['code' => 'deferred', 'resubmissionDate' => '2027-01-15'], 'deferred'],
			'abgelehnt' => [['code' => 'rejected', 'reason' => 'kein Bedarf'], 'rejected'],
			'Dublette' => [['code' => 'duplicate', 'duplicateOfId' => 8], 'duplicate'],
		];
	}

	/** @param array<string, mixed> $data */
	#[DataProvider('validDecisions')]
	public function testDecisionSetsFollowUpStatus(array $data, string $status): void {
		$demand = $this->service->decide('pmo', 7, $data);
		$this->assertSame($status, $demand->getStatus());
		$this->assertSame($data['code'], $demand->getTriageCode());
		$this->assertSame('pmo', $demand->getTriagedBy());
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function incompleteDecisions(): array {
		return [
			'Ablehnung ohne Begründung' => [['code' => 'rejected', 'reason' => '  ']],
			'Zurückstellen ohne Datum' => [['code' => 'deferred']],
			'Wiedervorlage in der Vergangenheit' => [['code' => 'deferred', 'resubmissionDate' => '2026-11-09']],
			'Analyse ohne Verantwortlichen' => [['code' => 'further_analysis', 'analysisDue' => '2026-11-20']],
			'Analyse mit unbekanntem Konto' => [['code' => 'further_analysis', 'analysisOwner' => 'ghost', 'analysisDue' => '2026-11-20']],
			'Dublette von sich selbst' => [['code' => 'duplicate', 'duplicateOfId' => 7]],
			'Dublette ohne führenden Antrag' => [['code' => 'duplicate', 'duplicateOfId' => 99]],
			'unbekannte Klasse' => [['code' => 'accepted', 'projectClass' => 'XL']],
			'unbekannter Code' => [['code' => 'maybe']],
		];
	}

	/** @param array<string, mixed> $data */
	#[DataProvider('incompleteDecisions')]
	public function testIncompleteDecisionIsRejected(array $data): void {
		$this->expectException(ValidationException::class);
		$this->service->decide('pmo', 7, $data);
	}

	public function testRequestorCannotTriageOwnDemand(): void {
		$this->expectException(ForbiddenException::class);
		$this->service->decide('alice', 7, ['code' => 'accepted']);
	}

	public function testDecidedDemandIsNotTriagedAgain(): void {
		$this->expectException(ForbiddenException::class);
		$this->service->decide('pmo', 8, ['code' => 'rejected', 'reason' => 'x']);
	}

	public function testFurtherAnalysisIsNotTriagedBeforeTheResultIsDelivered(): void {
		$demand = $this->service->decide('pmo', 7, ['code' => 'further_analysis', 'analysisOwner' => 'analyst', 'analysisDue' => '2026-11-20']);
		$this->assertFalse($this->service->isDue($demand));
		try {
			$this->service->decide('pmo', 7, ['code' => 'accepted']);
			$this->fail('Triage vor gelieferter Analyse');
		} catch (ForbiddenException) {
		}
		$this->service->completeAnalysis('analyst', 7, 'Aufwand ca. 40 PT');
		$this->assertTrue($this->service->isDue($this->store[7]));
		$this->assertSame('in_qualification', $this->service->decide('pmo', 7, ['code' => 'accepted'])->getStatus());
	}

	public function testOnlyTheAssignedAccountCompletesTheAnalysis(): void {
		$this->service->decide('pmo', 7, ['code' => 'further_analysis', 'analysisOwner' => 'analyst', 'analysisDue' => '2026-11-20']);
		foreach (['alice', 'pmo'] as $uid) {
			try {
				$this->service->completeAnalysis($uid, 7, 'selbst erledigt');
				$this->fail($uid . ' darf die Analyse nicht abschließen');
			} catch (ForbiddenException) {
			}
		}
		$this->assertNull($this->store[7]->getAnalysisDoneAt());
	}

	public function testAnalysisNeedsAResult(): void {
		$this->service->decide('pmo', 7, ['code' => 'further_analysis', 'analysisOwner' => 'analyst', 'analysisDue' => '2026-11-20']);
		$this->expectException(ValidationException::class);
		$this->service->completeAnalysis('analyst', 7, ' ');
	}

	public function testDeferredDemandIsDueOnTheResubmissionDate(): void {
		$demand = $this->service->decide('pmo', 7, ['code' => 'deferred', 'resubmissionDate' => '2026-11-11']);
		$this->assertFalse($this->service->isDue($demand));
		$demand->setResubmissionDate('2026-11-10');
		$this->assertTrue($this->service->isDue($demand));
	}

	public function testQueryReopensDemandForRequestor(): void {
		$demand = $this->service->query('pmo', 7, 'Welche Systeme?', '2026-11-30');
		$this->assertSame('query', $demand->getStatus());
		$this->assertContains($demand->getStatus(), DemandService::EDITABLE_STATUSES);
		$this->assertSame('Welche Systeme?', $demand->getQueryText());
	}

	public function testQueryNeedsQuestion(): void {
		$this->expectException(ValidationException::class);
		$this->service->query('pmo', 7, ' ', '2026-11-30');
	}
}
