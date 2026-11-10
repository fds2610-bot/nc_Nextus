<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Org-Einheit (ORG_UNIT, Kapitel 05), hierarchisch über parentId.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method int|null getParentId()
 * @method void setParentId(?int $parentId)
 * @method string|null getCostCenter()
 * @method void setCostCenter(?string $costCenter)
 * @method string|null getHeadUserId()
 * @method void setHeadUserId(?string $headUserId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class OrgUnit extends Entity implements JsonSerializable {
	public const EDITABLE = ['name', 'parentId', 'costCenter', 'headUserId'];

	protected string $name = '';
	protected ?int $parentId = null;
	protected ?string $costCenter = null;
	protected ?string $headUserId = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('parentId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->name,
			'parentId' => $this->parentId,
			'costCenter' => $this->costCenter,
			'headUserId' => $this->headUserId,
		];
	}
}
