<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Ressourcenbedarf eines Antrags je Pool und Monat (RESOURCE_DEMAND, Kapitel 05).
 *
 * @method int getDemandId()
 * @method void setDemandId(int $demandId)
 * @method int getPoolId()
 * @method void setPoolId(int $poolId)
 * @method string getPeriod()
 * @method void setPeriod(string $period)
 * @method float getPersonDays()
 * @method void setPersonDays(float $personDays)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getUpdatedBy()
 * @method void setUpdatedBy(string $updatedBy)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class ResourceNeed extends Entity implements JsonSerializable {
	// Startwerte bewusst neutral: Entity markiert nur geänderte Werte als zu speichern
	protected int $demandId = 0;
	protected int $poolId = 0;
	protected string $period = '';
	protected float $personDays = -1.0;
	protected string $source = '';
	protected string $updatedBy = '';
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('demandId', 'integer');
		$this->addType('poolId', 'integer');
		$this->addType('personDays', 'float');
		$this->addType('updatedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'demandId' => $this->demandId,
			'poolId' => $this->poolId,
			'period' => $this->period,
			'personDays' => $this->personDays,
			'source' => $this->source,
			'updatedBy' => $this->updatedBy,
			'updatedAt' => $this->updatedAt,
		];
	}
}
