<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Ressourcenpool (RESOURCE_POOL, Kapitel 05); Quoten in Prozent (Kapitel 04 §2).
 *
 * @method string getCode()
 * @method void setCode(string $code)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getPoolType()
 * @method void setPoolType(string $poolType)
 * @method ?int getOrgUnitId()
 * @method void setOrgUnitId(?int $orgUnitId)
 * @method ?string getManagerUserId()
 * @method void setManagerUserId(?string $managerUserId)
 * @method int getIsBottleneck()
 * @method void setIsBottleneck(int $isBottleneck)
 * @method ?int getKeyPersons()
 * @method void setKeyPersons(?int $keyPersons)
 * @method ?int getWipLimit()
 * @method void setWipLimit(?int $wipLimit)
 * @method ?string getSkills()
 * @method void setSkills(?string $skills)
 * @method float getAbsencePct()
 * @method void setAbsencePct(float $absencePct)
 * @method float getLinePct()
 * @method void setLinePct(float $linePct)
 * @method float getOverheadPct()
 * @method void setOverheadPct(float $overheadPct)
 * @method float getReservePct()
 * @method void setReservePct(float $reservePct)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Pool extends Entity implements JsonSerializable {
	// Startwerte bewusst neutral: Entity markiert nur geänderte Werte als zu speichern
	protected string $code = '';
	protected string $name = '';
	protected string $poolType = '';
	protected ?int $orgUnitId = null;
	protected ?string $managerUserId = null;
	protected int $isBottleneck = -1;
	protected ?int $keyPersons = null;
	protected ?int $wipLimit = null;
	protected ?string $skills = null;
	protected float $absencePct = -1.0;
	protected float $linePct = -1.0;
	protected float $overheadPct = -1.0;
	protected float $reservePct = -1.0;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('orgUnitId', 'integer');
		$this->addType('isBottleneck', 'integer');
		$this->addType('keyPersons', 'integer');
		$this->addType('wipLimit', 'integer');
		$this->addType('absencePct', 'float');
		$this->addType('linePct', 'float');
		$this->addType('overheadPct', 'float');
		$this->addType('reservePct', 'float');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'code' => $this->code,
			'name' => $this->name,
			'poolType' => $this->poolType,
			'orgUnitId' => $this->orgUnitId,
			'managerUserId' => $this->managerUserId,
			'isBottleneck' => $this->isBottleneck,
			'keyPersons' => $this->keyPersons,
			'wipLimit' => $this->wipLimit,
			'skills' => $this->skills,
			'absencePct' => $this->absencePct,
			'linePct' => $this->linePct,
			'overheadPct' => $this->overheadPct,
			'reservePct' => $this->reservePct,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
