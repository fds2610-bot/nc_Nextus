<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Db\Comment;
use OCA\Nexus\Db\CommentMapper;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCA\Nexus\Service\CommentService;
use OCA\Nexus\Service\PermissionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CommentServiceTest extends TestCase {
	private CommentMapper&MockObject $comments;
	private CommentService $service;

	protected function setUp(): void {
		$demand = new Demand();
		$demand->setId(7);
		$demand->setRequestorId('alice');
		$demand->setCreatedAt(1794200000);
		$demands = $this->createMock(DemandMapper::class);
		$demands->method('find')->willReturn($demand);
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canReadDemand')->willReturnCallback(fn (string $uid) => in_array($uid, ['alice', 'pmo'], true));
		$this->comments = $this->createMock(CommentMapper::class);
		$this->comments->method('insert')->willReturnArgument(0);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794268800);
		$this->service = new CommentService($this->comments, $demands, $permissions, $time);
	}

	public function testReaderCanComment(): void {
		$comment = $this->service->add('pmo', 7, '  Bitte Kosten schätzen ');
		$this->assertSame('Bitte Kosten schätzen', $comment->getMessage());
		$this->assertSame('comment', $comment->getKind());
		$this->assertSame('pmo', $comment->getUserId());
	}

	public function testOutsiderSeesNothing(): void {
		$this->expectException(NotFoundException::class);
		$this->service->list('mallory', 7);
	}

	public function testEmptyCommentIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->service->add('alice', 7, "  \n ");
	}

	public function testListStartsAtDemandCreation(): void {
		$this->comments->expects($this->once())->method('findForDemand')->with(7, 1794200000)->willReturn([new Comment()]);
		$this->assertCount(1, $this->service->list('alice', 7));
	}
}
