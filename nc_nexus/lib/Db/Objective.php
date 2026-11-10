<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Strategisches Ziel (STRATEGIC_OBJECTIVE, Kapitel 05), hierarchisch über parentId.
 *
 * @method string getCode()
 * @method void setCode(string $code)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int|null getParentId()
 * @method void setParentId(?int $parentId)
 * @method string|null getOwnerUserId()
 * @method void setOwnerUserId(?string $ownerUserId)
 * @method string|null getTargetMetric()
 * @method void setTargetMetric(?string $targetMetric)
 * @method string|null getValidFrom()
 * @method void setValidFrom(?string $validFrom)
 * @method string|null getValidTo()
 * @method void setValidTo(?string $validTo)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Objective extends Entity implements JsonSerializable {
	// Startwerte bewusst neutral: Entity markiert nur geänderte Werte als zu speichern
	protected string $code = '';
	protected string $title = '';
	protected ?string $description = null;
	protected ?int $parentId = null;
	protected ?string $ownerUserId = null;
	protected ?string $targetMetric = null;
	protected ?string $validFrom = null;
	protected ?string $validTo = null;
	protected int $version = 0;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('parentId', 'integer');
		$this->addType('version', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'code' => $this->code,
			'title' => $this->title,
			'description' => $this->description,
			'parentId' => $this->parentId,
			'ownerUserId' => $this->ownerUserId,
			'targetMetric' => $this->targetMetric,
			'validFrom' => $this->validFrom,
			'validTo' => $this->validTo,
			'version' => $this->version,
		];
	}
}
