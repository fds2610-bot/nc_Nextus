<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Triage eingereichter Anträge (DEM-002) mit verbindlichen Entscheidungscodes (Kapitel 02, §2)
 * und Rückfrage an den Antragsteller.
 */
class TriageService {
	/** Entscheidungscode => Folgestatus (Statusautomat Kapitel 02, §4.1) */
	public const CODES = [
		'accepted' => 'in_qualification',
		'further_analysis' => 'further_analysis',
		'deferred' => 'deferred',
		'rejected' => 'rejected',
		'duplicate' => 'duplicate',
	];

	/** Vorhabensklassen (Kapitel 01, §6.1) */
	public const CLASSES = ['XS', 'S', 'M', 'L'];

	/**
	 * Wartet der Antrag gerade auf eine Triage? Eingereicht immer; weitere Analyse erst, wenn das
	 * Ergebnis geliefert ist; zurückgestellt erst ab dem Wiedervorlagedatum (Kapitel 02 §4.1).
	 */
	public function isDue(Demand $demand): bool {
		return match ($demand->getStatus()) {
			'submitted' => true,
			'further_analysis' => $demand->getAnalysisDoneAt() !== null,
			'deferred' => $demand->getResubmissionDate() !== null && $demand->getResubmissionDate() <= date('Y-m-d', $this->time->getTime()),
			default => false,
		};
	}

	/**
	 * Ergebnis der weiteren Analyse liefern – nur das beauftragte Konto (klare Zuständigkeit). Das Ergebnis
	 * steht im Kommentarfaden; danach ist der Antrag wieder triagierbar.
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function completeAnalysis(string $userId, int $id, ?string $result): Demand {
		$demand = $this->demands->findReadable($userId, $id);
		if ($demand->getStatus() !== 'further_analysis' || $demand->getAnalysisDoneAt() !== null) {
			throw new ForbiddenException('No analysis is pending for this demand');
		}
		if ($demand->getAnalysisOwner() !== $userId) {
			throw new ForbiddenException('Only the assigned account may complete the analysis');
		}
		$result = $this->text($result);
		if ($result === null) {
			throw new ValidationException('Please describe the result of the analysis');
		}
		$now = $this->time->getTime();
		$demand->setAnalysisDoneAt($now);
		$demand->setUpdatedAt($now);
		$this->db->beginTransaction();
		try {
			$demand = $this->mapper->update($demand);
			$this->audit->log(DemandService::ENTITY, $id, 'analysis', $userId, ['analysisDoneAt' => [null, $now]], $result);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->comments->add($userId, $id, $result, 'analysis');
		return $demand;
	}

	public function __construct(
		private DemandService $demands,
		private DemandMapper $mapper,
		private PermissionService $permissions,
		private AuditService $audit,
		private IDBConnection $db,
		private IUserManager $userManager,
		private ITimeFactory $time,
		private CommentService $comments,
		private DecisionService $decisions,
	) {
	}

	/**
	 * Entscheidungscode setzen.
	 *
	 * @param array<string, mixed> $data code, reason, resubmissionDate, analysisOwner, analysisDue, duplicateOfId, projectClass
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function decide(string $userId, int $id, array $data): Demand {
		$demand = $this->triageable($userId, $id);
		$code = (string)($data['code'] ?? '');
		if (!isset(self::CODES[$code])) {
			throw new ValidationException('Unknown decision code');
		}
		$reason = $this->text($data['reason'] ?? null);
		// Außerhalb des regulären Schritts (vorgezogen, Analyse nicht abgewartet, Entscheidung
		// geändert) nur mit Begründung – das PMO darf alles, aber nachvollziehbar
		$outOfSequence = !$this->isDue($demand);
		if ($outOfSequence && $reason === null) {
			throw new ValidationException('A reason is required when deciding outside the regular step');
		}
		$resubmission = null;
		$analysisOwner = null;
		$analysisDue = null;
		$duplicateOf = null;
		$class = null;

		switch ($code) {
			case 'accepted':
				$class = $data['projectClass'] ?? null;
				$class = $class === null || $class === '' ? null : (string)$class;
				if ($class !== null && !in_array($class, self::CLASSES, true)) {
					throw new ValidationException('Unknown project class');
				}
				break;
			case 'further_analysis':
				$analysisOwner = trim((string)($data['analysisOwner'] ?? ''));
				if ($analysisOwner === '' || !$this->userManager->userExists($analysisOwner)) {
					throw new ValidationException('Further analysis needs a responsible account');
				}
				$analysisDue = $this->futureDate($data['analysisDue'] ?? null, 'Further analysis needs a due date');
				break;
			case 'deferred':
				$resubmission = $this->futureDate($data['resubmissionDate'] ?? null, 'Deferring needs a resubmission date');
				break;
			case 'rejected':
				if ($reason === null) {
					throw new ValidationException('Rejecting needs a reason');
				}
				break;
			case 'duplicate':
				$duplicateOf = (int)($data['duplicateOfId'] ?? 0);
				if ($duplicateOf === $id) {
					throw new ValidationException('A demand cannot duplicate itself');
				}
				try {
					$leading = $this->demands->findReadable($userId, $duplicateOf);
				} catch (NotFoundException) {
					throw new ValidationException('Leading demand not found');
				}
				if ($leading->getStatus() === 'duplicate' || $leading->getStatus() === 'draft') {
					throw new ValidationException('The leading demand must not be a draft or itself a duplicate');
				}
				break;
		}

		$before = $this->snapshot($demand);
		$demand->setTriageCode($code);
		$demand->setStatus(self::CODES[$code]);
		$demand->setTriageReason($reason);
		$demand->setResubmissionDate($resubmission);
		$demand->setAnalysisOwner($analysisOwner);
		$demand->setAnalysisDue($analysisDue);
		$demand->setDuplicateOfId($duplicateOf);
		if ($code === 'accepted') {
			$demand->setProjectClass($class);
		}
		$demand->setQueryText(null);
		$demand->setQueryDue(null);
		// eine neue Analyse beginnt ohne geliefertes Ergebnis
		$demand->setAnalysisDoneAt(null);
		$details = [
			'projectClass' => $class,
			'resubmissionDate' => $resubmission,
			'analysisOwner' => $analysisOwner,
			'analysisDue' => $analysisDue,
			'duplicateOfId' => $duplicateOf,
		];
		return $this->store($userId, $demand, 'decision', $before, $reason,
			fn (Demand $d) => $this->decisions->recordTriage($userId, $d, $code, $reason, $details, $outOfSequence));
	}

	/**
	 * Rückfrage an den Antragsteller: Status „Rückfrage“, der Antrag ist wieder bearbeitbar.
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function query(string $userId, int $id, ?string $question, ?string $due): Demand {
		$demand = $this->triageable($userId, $id);
		if ($demand->getStatus() === DemandService::STATUS_QUERY) {
			throw new ForbiddenException('A query is already open');
		}
		$question = $this->text($question);
		if ($question === null) {
			throw new ValidationException('A query needs a question');
		}
		$before = $this->snapshot($demand);
		$demand->setStatus(DemandService::STATUS_QUERY);
		$demand->setQueryText($question);
		$demand->setQueryDue($this->futureDate($due, 'A query needs a due date'));
		$demand = $this->store($userId, $demand, 'status_change', $before, $question);
		// Rückfrage auch im Kommentarfaden, damit Frage und Antwort zusammen stehen (DEM-003)
		$this->comments->add($userId, $id, $question, 'query');
		return $demand;
	}

	/** @throws NotFoundException|ForbiddenException */
	private function triageable(string $userId, int $id): Demand {
		$demand = $this->demands->findReadable($userId, $id);
		if (!$this->permissions->canTriage($userId, $demand)) {
			throw new ForbiddenException($demand->getRequestorId() === $userId
				? 'You cannot triage your own demand'
				: 'Only the portfolio office of the area may triage');
		}
		// Entwürfe sind privat; sonst darf das PMO jederzeit entscheiden (außerplanmäßig mit Begründung)
		if ($demand->getStatus() === DemandService::STATUS_DRAFT) {
			throw new ForbiddenException('The demand is not waiting for triage');
		}
		return $demand;
	}

	/**
	 * @param array<string, mixed> $before
	 */
	private function store(string $userId, Demand $demand, string $action, array $before, ?string $comment, ?\Closure $within = null): Demand {
		$now = $this->time->getTime();
		$demand->setTriagedBy($userId);
		$demand->setTriagedAt($now);
		$demand->setUpdatedAt($now);
		$changes = [];
		foreach ($this->snapshot($demand) as $field => $value) {
			if ($before[$field] !== $value) {
				$changes[$field] = [$before[$field], $value];
			}
		}
		$this->db->beginTransaction();
		try {
			$demand = $this->mapper->update($demand);
			$this->audit->log(DemandService::ENTITY, $demand->getId(), $action, $userId, $changes, $comment);
			if ($within !== null) {
				$within($demand);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $demand;
	}

	/** @return array<string, mixed> */
	private function snapshot(Demand $demand): array {
		return [
			'status' => $demand->getStatus(),
			'triageCode' => $demand->getTriageCode(),
			'triageReason' => $demand->getTriageReason(),
			'resubmissionDate' => $demand->getResubmissionDate(),
			'analysisOwner' => $demand->getAnalysisOwner(),
			'analysisDue' => $demand->getAnalysisDue(),
			'duplicateOfId' => $demand->getDuplicateOfId(),
			'projectClass' => $demand->getProjectClass(),
			'queryText' => $demand->getQueryText(),
			'queryDue' => $demand->getQueryDue(),
		];
	}

	private function text(mixed $value): ?string {
		$value = trim((string)$value);
		return $value === '' ? null : $value;
	}

	/** @throws ValidationException */
	private function futureDate(mixed $value, string $missing): string {
		$value = trim((string)$value);
		if ($value === '') {
			throw new ValidationException($missing);
		}
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
			throw new ValidationException('Invalid date');
		}
		if ($value < date('Y-m-d', $this->time->getTime())) {
			throw new ValidationException('The date must not be in the past');
		}
		return $value;
	}
}
