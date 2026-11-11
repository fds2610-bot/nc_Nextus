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
 * @method string|null getProjectClass()
 * @method void setProjectClass(?string $projectClass)
 * @method string|null getTriageCode()
 * @method void setTriageCode(?string $triageCode)
 * @method string|null getTriageReason()
 * @method void setTriageReason(?string $triageReason)
 * @method string|null getResubmissionDate()
 * @method void setResubmissionDate(?string $resubmissionDate)
 * @method int|null getDuplicateOfId()
 * @method void setDuplicateOfId(?int $duplicateOfId)
 * @method string|null getAnalysisOwner()
 * @method void setAnalysisOwner(?string $analysisOwner)
 * @method string|null getAnalysisDue()
 * @method void setAnalysisDue(?string $analysisDue)
 * @method string|null getQueryText()
 * @method void setQueryText(?string $queryText)
 * @method string|null getQueryDue()
 * @method void setQueryDue(?string $queryDue)
 * @method string|null getTriagedBy()
 * @method void setTriagedBy(?string $triagedBy)
 * @method int|null getTriagedAt()
 * @method void setTriagedAt(?int $triagedAt)
 * @method int|null getAnalysisDoneAt()
 * @method void setAnalysisDoneAt(?int $analysisDoneAt)
 * @method string|null getBucket()
 * @method void setBucket(?string $bucket)
 * @method string|null getDeadline()
 * @method void setDeadline(?string $deadline)
 * @method int|null getRankOverride()
 * @method void setRankOverride(?int $rankOverride)
 * @method string|null getOverrideReason()
 * @method void setOverrideReason(?string $overrideReason)
 * @method string|null getProjectKey()
 * @method void setProjectKey(?string $projectKey)
 * @method int|null getReleasedAt()
 * @method void setReleasedAt(?int $releasedAt)
 * @method int|null getOrgUnitId()
 * @method void setOrgUnitId(?int $orgUnitId)
 * @method int|null getSubmittedAt()
 * @method void setSubmittedAt(?int $submittedAt)
 */
class Demand extends Entity implements JsonSerializable {
	/** Felder, die der Antragsteller im Entwurf bearbeiten darf (Audit-Vergleich). */
	public const EDITABLE = ['title', 'description', 'problemStatement', 'expectedBenefit', 'demandType', 'orgUnitId', 'deadline'];

	protected string $demandKey = '';
	protected string $title = '';
	protected ?string $description = null;
	protected ?string $problemStatement = null;
	protected ?string $expectedBenefit = null;
	protected string $demandType = '';
	// Startwerte bewusst leer: Entity markiert nur geänderte Werte als zu speichern
	protected string $status = '';
	protected string $intakeChannel = '';
	protected string $requestorId = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $submittedAt = null;
	protected ?int $orgUnitId = null;
	protected ?string $projectKey = null;
	protected ?int $releasedAt = null;
	protected ?string $bucket = null;
	protected ?string $deadline = null;
	protected ?int $rankOverride = null;
	protected ?string $overrideReason = null;
	protected ?int $analysisDoneAt = null;
	protected ?string $projectClass = null;
	protected ?string $triageCode = null;
	protected ?string $triageReason = null;
	protected ?string $resubmissionDate = null;
	protected ?int $duplicateOfId = null;
	protected ?string $analysisOwner = null;
	protected ?string $analysisDue = null;
	protected ?string $queryText = null;
	protected ?string $queryDue = null;
	protected ?string $triagedBy = null;
	protected ?int $triagedAt = null;

	public function __construct() {
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('submittedAt', 'integer');
		$this->addType('orgUnitId', 'integer');
		$this->addType('releasedAt', 'integer');
		$this->addType('rankOverride', 'integer');
		$this->addType('duplicateOfId', 'integer');
		$this->addType('triagedAt', 'integer');
		$this->addType('analysisDoneAt', 'integer');
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
			'orgUnitId' => $this->orgUnitId,
			'projectKey' => $this->projectKey,
			'releasedAt' => $this->releasedAt,
			'bucket' => $this->bucket,
			'deadline' => $this->deadline,
			'rankOverride' => $this->rankOverride,
			'overrideReason' => $this->overrideReason,
			'analysisDoneAt' => $this->analysisDoneAt,
			'projectClass' => $this->projectClass,
			'triageCode' => $this->triageCode,
			'triageReason' => $this->triageReason,
			'resubmissionDate' => $this->resubmissionDate,
			'duplicateOfId' => $this->duplicateOfId,
			'analysisOwner' => $this->analysisOwner,
			'analysisDue' => $this->analysisDue,
			'queryText' => $this->queryText,
			'queryDue' => $this->queryDue,
			'triagedBy' => $this->triagedBy,
			'triagedAt' => $this->triagedAt,
			'status' => $this->status,
			'intakeChannel' => $this->intakeChannel,
			'requestorId' => $this->requestorId,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'submittedAt' => $this->submittedAt,
		];
	}
}
