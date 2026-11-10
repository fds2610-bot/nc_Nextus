<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\AuditEntry;
use OCA\Nexus\Db\AuditMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Fachlicher Audit-Trail (AUD-001): nur anfügen, nie ändern oder löschen.
 */
class AuditService {
	public function __construct(
		private AuditMapper $mapper,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param array<string, array{0: mixed, 1: mixed}>|null $changes Feld => [alt, neu]
	 */
	public function log(string $entityType, int $entityId, string $action, string $userId, ?array $changes = null, ?string $comment = null): AuditEntry {
		$entry = new AuditEntry();
		$entry->setEntityType($entityType);
		$entry->setEntityId($entityId);
		$entry->setAction($action);
		$entry->setUserId($userId);
		$entry->setCreatedAt($this->time->getTime());
		$entry->setFieldChanges($changes === null ? null : json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$entry->setComment($comment);
		return $this->mapper->insert($entry);
	}

	/**
	 * @return list<AuditEntry>
	 */
	public function history(string $entityType, int $entityId): array {
		return $this->mapper->findForEntity($entityType, $entityId);
	}
}
