<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Comment;
use OCA\Nexus\Db\CommentMapper;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Kommentarfaden am Antrag (DEM-003). Lesen und schreiben darf, wer den Antrag lesen darf;
 * Kommentare sind unveränderlich (Nachvollziehbarkeit von Rückfragen und Antworten).
 */
class CommentService {
	public const KINDS = ['comment', 'query', 'answer', 'analysis'];
	private const MAX_LENGTH = 10000;

	public function __construct(
		private CommentMapper $comments,
		private DemandMapper $demands,
		private PermissionService $permissions,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return list<Comment>
	 * @throws NotFoundException
	 */
	public function list(string $userId, int $demandId): array {
		$demand = $this->readable($userId, $demandId);
		return $this->comments->findForDemand($demandId, $demand->getCreatedAt());
	}

	/**
	 * @throws NotFoundException|ValidationException
	 */
	public function add(string $userId, int $demandId, string $message, string $kind = 'comment'): Comment {
		$this->readable($userId, $demandId);
		$message = trim($message);
		if ($message === '') {
			throw new ValidationException('A comment must not be empty');
		}
		if (mb_strlen($message) > self::MAX_LENGTH) {
			throw new ValidationException('The comment is too long');
		}
		if (!in_array($kind, self::KINDS, true)) {
			throw new ValidationException('Unknown comment type');
		}
		$comment = new Comment();
		$comment->setDemandId($demandId);
		$comment->setUserId($userId);
		$comment->setKind($kind);
		$comment->setMessage($message);
		$comment->setCreatedAt($this->time->getTime());
		return $this->comments->insert($comment);
	}

	/** @throws NotFoundException */
	private function readable(string $userId, int $demandId): Demand {
		try {
			$demand = $this->demands->find($demandId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Demand not found');
		}
		if (!$this->permissions->canReadDemand($userId, $demand)) {
			throw new NotFoundException('Demand not found');
		}
		return $demand;
	}
}
