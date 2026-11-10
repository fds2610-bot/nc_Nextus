<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Eintrag im fachlichen Audit-Log (AUDIT_LOG, Kapitel 05); nur anfügbar.
 *
 * @method string getEntityType()
 * @method void setEntityType(string $entityType)
 * @method int getEntityId()
 * @method void setEntityId(int $entityId)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string|null getFieldChanges()
 * @method void setFieldChanges(?string $fieldChanges)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 */
class AuditEntry extends Entity implements JsonSerializable {
	protected string $entityType = '';
	protected int $entityId = 0;
	protected string $action = '';
	protected ?string $fieldChanges = null;
	protected string $userId = '';
	protected int $createdAt = 0;
	protected ?string $comment = null;

	public function __construct() {
		$this->addType('entityId', 'integer');
		$this->addType('createdAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'entityType' => $this->entityType,
			'entityId' => $this->entityId,
			'action' => $this->action,
			'fieldChanges' => $this->fieldChanges !== null ? json_decode($this->fieldChanges, true) : null,
			'userId' => $this->userId,
			'createdAt' => $this->createdAt,
			'comment' => $this->comment,
		];
	}
}
