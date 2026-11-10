<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Kontextgebundene Rollenzuweisung (ROLE_ASSIGNMENT, Kapitel 05) an ein Konto oder eine Gruppe.
 *
 * @method string getRole()
 * @method void setRole(string $role)
 * @method string getScopeType()
 * @method void setScopeType(string $scopeType)
 * @method int|null getScopeId()
 * @method void setScopeId(?int $scopeId)
 * @method string getPrincipalType()
 * @method void setPrincipalType(string $principalType)
 * @method string getPrincipalId()
 * @method void setPrincipalId(string $principalId)
 * @method string|null getValidFrom()
 * @method void setValidFrom(?string $validFrom)
 * @method string|null getValidTo()
 * @method void setValidTo(?string $validTo)
 * @method string|null getDeputyFor()
 * @method void setDeputyFor(?string $deputyFor)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class RoleAssignment extends Entity implements JsonSerializable {
	protected string $role = '';
	// Startwerte bewusst leer: Entity markiert nur geänderte Werte als zu speichern
	protected string $scopeType = '';
	protected ?int $scopeId = null;
	protected string $principalType = '';
	protected string $principalId = '';
	protected ?string $validFrom = null;
	protected ?string $validTo = null;
	protected ?string $deputyFor = null;
	protected string $createdBy = '';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('scopeId', 'integer');
		$this->addType('createdAt', 'integer');
	}

	/** Gilt die Zuweisung am Stichtag (JJJJ-MM-TT)? */
	public function isValidOn(string $day): bool {
		return ($this->validFrom === null || $this->validFrom <= $day)
			&& ($this->validTo === null || $this->validTo >= $day);
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'role' => $this->role,
			'scopeType' => $this->scopeType,
			'scopeId' => $this->scopeId,
			'principalType' => $this->principalType,
			'principalId' => $this->principalId,
			'validFrom' => $this->validFrom,
			'validTo' => $this->validTo,
			'deputyFor' => $this->deputyFor,
		];
	}
}
