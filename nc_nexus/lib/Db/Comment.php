<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Kommentar am Antrag (DEM-003); kind = comment, query (Rückfrage des PMO) oder answer.
 *
 * @method int getDemandId()
 * @method void setDemandId(int $demandId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getMessage()
 * @method void setMessage(string $message)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Comment extends Entity implements JsonSerializable {
	// Startwerte bewusst leer: Entity markiert nur geänderte Werte als zu speichern
	protected int $demandId = 0;
	protected string $userId = '';
	protected string $kind = '';
	protected string $message = '';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('demandId', 'integer');
		$this->addType('createdAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'demandId' => $this->demandId,
			'userId' => $this->userId,
			'kind' => $this->kind,
			'message' => $this->message,
			'createdAt' => $this->createdAt,
		];
	}
}
