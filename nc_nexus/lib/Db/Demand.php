<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Antrag (DEMAND, Kapitel 05).
 *
 * @method string getDemandKey()
 * @method void setDemandKey(string $demandKey)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getProblemStatement()
 * @method void setProblemStatement(?string $problemStatement)
 * @method string|null getExpectedBenefit()
 * @method void setExpectedBenefit(?string $expectedBenefit)
 * @method string getDemandType()
 * @method void setDemandType(string $demandType)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getIntakeChannel()
 * @method void setIntakeChannel(string $intakeChannel)
 * @method string getRequestorId()
 * @method void setRequestorId(string $requestorId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getSubmittedAt()
 * @method void setSubmittedAt(?int $submittedAt)
 */
class Demand extends Entity implements JsonSerializable {
	/** Felder, die der Antragsteller im Entwurf bearbeiten darf (Audit-Vergleich). */
	public const EDITABLE = ['title', 'description', 'problemStatement', 'expectedBenefit', 'demandType'];

	protected string $demandKey = '';
	protected string $title = '';
	protected ?string $description = null;
	protected ?string $problemStatement = null;
	protected ?string $expectedBenefit = null;
	protected string $demandType = '';
	protected string $status = 'draft';
	protected string $intakeChannel = 'form';
	protected string $requestorId = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $submittedAt = null;

	public function __construct() {
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('submittedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'key' => $this->demandKey,
			'title' => $this->title,
			'description' => $this->description,
			'problemStatement' => $this->problemStatement,
			'expectedBenefit' => $this->expectedBenefit,
			'demandType' => $this->demandType,
			'status' => $this->status,
			'intakeChannel' => $this->intakeChannel,
			'requestorId' => $this->requestorId,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'submittedAt' => $this->submittedAt,
		];
	}
}
