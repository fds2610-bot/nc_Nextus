<?php

declare(strict_types=1);

namespace OCA\Nexus\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Beschluss im Entscheidungsregister (DECISION, Kapitel 05) – nach Anlage unveränderlich.
 *
 * @method string getDecisionKey()
 * @method void setDecisionKey(string $decisionKey)
 * @method string getSubjectType()
 * @method void setSubjectType(string $subjectType)
 * @method int getSubjectId()
 * @method void setSubjectId(int $subjectId)
 * @method string getDecisionType()
 * @method void setDecisionType(string $decisionType)
 * @method string getOutcome()
 * @method void setOutcome(string $outcome)
 * @method string|null getRationale()
 * @method void setRationale(?string $rationale)
 * @method string|null getDetails()
 * @method void setDetails(?string $details)
 * @method string getMandateRole()
 * @method void setMandateRole(string $mandateRole)
 * @method string getMandateScopeType()
 * @method void setMandateScopeType(string $mandateScopeType)
 * @method int|null getMandateScopeId()
 * @method void setMandateScopeId(?int $mandateScopeId)
 * @method int getOutOfSequence()
 * @method void setOutOfSequence(int $outOfSequence)
 * @method int|null getSupersedesId()
 * @method void setSupersedesId(?int $supersedesId)
 * @method string getDecidedBy()
 * @method void setDecidedBy(string $decidedBy)
 * @method int getDecidedAt()
 * @method void setDecidedAt(int $decidedAt)
 */
class Decision extends Entity implements JsonSerializable {
	// Startwerte bewusst neutral: Entity markiert nur geänderte Werte als zu speichern
	protected string $decisionKey = '';
	protected string $subjectType = '';
	protected int $subjectId = 0;
	protected string $decisionType = '';
	protected string $outcome = '';
	protected ?string $rationale = null;
	protected ?string $details = null;
	protected string $mandateRole = '';
	protected string $mandateScopeType = '';
	protected ?int $mandateScopeId = null;
	protected int $outOfSequence = -1;
	protected ?int $supersedesId = null;
	protected string $decidedBy = '';
	protected int $decidedAt = 0;

	public function __construct() {
		$this->addType('subjectId', 'integer');
		$this->addType('mandateScopeId', 'integer');
		$this->addType('outOfSequence', 'integer');
		$this->addType('supersedesId', 'integer');
		$this->addType('decidedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'key' => $this->decisionKey,
			'subjectType' => $this->subjectType,
			'subjectId' => $this->subjectId,
			'decisionType' => $this->decisionType,
			'outcome' => $this->outcome,
			'rationale' => $this->rationale,
			'details' => $this->details === null ? null : json_decode($this->details, true),
			'mandate' => ['role' => $this->mandateRole, 'scopeType' => $this->mandateScopeType, 'scopeId' => $this->mandateScopeId],
			'outOfSequence' => $this->outOfSequence === 1,
			'supersedesId' => $this->supersedesId,
			'decidedBy' => $this->decidedBy,
			'decidedAt' => $this->decidedAt,
		];
	}
}
