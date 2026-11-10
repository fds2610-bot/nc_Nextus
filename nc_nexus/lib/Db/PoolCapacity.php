<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Kapazität eines Pools in einem Monat (POOL_CAPACITY, Kapitel 05).
 *
 * @method int getPoolId()
 * @method void setPoolId(int $poolId)
 * @method string getPeriod()
 * @method void setPeriod(string $period)
 * @method float getFte()
 * @method void setFte(float $fte)
 * @method int getWorkingDays()
 * @method void setWorkingDays(int $workingDays)
 * @method ?float getOverrideDays()
 * @method void setOverrideDays(?float $overrideDays)
 * @method ?string getOverrideReason()
 * @method void setOverrideReason(?string $overrideReason)
 * @method string getUpdatedBy()
 * @method void setUpdatedBy(string $updatedBy)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class PoolCapacity extends Entity implements JsonSerializable {
	// Startwerte bewusst neutral: Entity markiert nur geänderte Werte als zu speichern
	protected int $poolId = 0;
	protected string $period = '';
	protected float $fte = -1.0;
	protected int $workingDays = -1;
	protected ?float $overrideDays = null;
	protected ?string $overrideReason = null;
	protected string $updatedBy = '';
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('poolId', 'integer');
		$this->addType('fte', 'float');
		$this->addType('workingDays', 'integer');
		$this->addType('overrideDays', 'float');
		$this->addType('updatedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'poolId' => $this->poolId,
			'period' => $this->period,
			'fte' => $this->fte,
			'workingDays' => $this->workingDays,
			'overrideDays' => $this->overrideDays,
			'overrideReason' => $this->overrideReason,
			'updatedBy' => $this->updatedBy,
			'updatedAt' => $this->updatedAt,
		];
	}
}
