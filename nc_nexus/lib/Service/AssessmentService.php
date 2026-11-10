<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Db\ScoringMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Bewertung nach Kriterienkatalog (POR-002) und Katalogpflege (GOV-003), Kapitel 03.
 *
 *   Nutzenwert   = Σ (Kriterienwert × Gewicht)                       0 … 8
 *   Dringlichkeit = max(Z…)   – eine Frist wird nicht durch stabilen Nutzen entschärft
 *   Risikowert   = Mittelwert(R…)
 *   Wertdichte   = (Nutzen × (1 + Dringlichkeit/8)) / (Aufwand × (1 + Risiko/16))
 *
 * Bewertungen sind unveränderlich; eine neue Einschätzung ist eine neue Bewertung. Die
 * Fremdbewertung des PMO geht in die Rangliste ein, die Selbstbewertung bleibt daneben sichtbar.
 */
class AssessmentService {
	public const SCALE = [0, 1, 3, 5, 8];
	public const EFFORTS = [1, 3, 5, 8];
	public const QUALITIES = ['rough', 'plausible', 'planned'];
	public const DIMENSIONS = ['benefit', 'urgency', 'risk'];
	/** Status, in denen bewertet wird (nach G0 bis zur Priorisierung) */
	public const ASSESSABLE = ['in_qualification', 'candidate'];
	/** Antragstyp → vorgeschlagener Bucket */
	public const BUCKET_OF_TYPE = ['mandatory' => 'A', 'lifecycle' => 'B', 'strategic' => 'C', 'efficiency' => 'C', 'innovation' => 'D'];

	public function __construct(
		private ScoringMapper $scoring,
		private DemandService $demands,
		private DemandMapper $demandMapper,
		private ResourceNeedMapper $needs,
		private PermissionService $permissions,
		private AuditService $audit,
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	// ---- Katalog ------------------------------------------------------------------------------

	/**
	 * Aktiver Kriterienkatalog mit Kriterien, dazu Buckets und Versionsliste.
	 *
	 * @return array<string, mixed>
	 */
	public function model(string $userId): array {
		$sets = $this->scoring->sets();
		$active = $this->activeSet();
		return [
			'canManage' => $this->permissions->canManageOrg($userId),
			'set' => $active + ['criteria' => $this->scoring->criteria($active['id'])],
			'sets' => $sets,
			'buckets' => $this->scoring->buckets(),
			'scale' => self::SCALE,
			'efforts' => self::EFFORTS,
			'qualities' => self::QUALITIES,
		];
	}

	/**
	 * Katalog ändern = neue Version; bestehende Bewertungen bleiben an ihrer Version.
	 *
	 * @param list<array<string, mixed>> $criteria
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException
	 */
	public function newVersion(string $userId, array $criteria, ?string $note): array {
		if (!$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may change the criteria catalog');
		}
		$clean = [];
		$weights = 0.0;
		$codes = [];
		foreach ($criteria as $c) {
			$code = strtoupper(trim((string)($c['code'] ?? '')));
			$name = trim((string)($c['name'] ?? ''));
			$dimension = (string)($c['dimension'] ?? '');
			if (!preg_match('/^[A-Z][A-Z0-9]{0,7}$/', $code) || isset($codes[$code])) {
				throw new ValidationException('Criterion codes must be unique (letter followed by letters or digits)');
			}
			if ($name === '' || !in_array($dimension, self::DIMENSIONS, true)) {
				throw new ValidationException('Every criterion needs a name and a dimension');
			}
			$anchors = [];
			foreach (self::SCALE as $step) {
				$anchors[(string)$step] = trim((string)($c['anchors'][(string)$step] ?? $c['anchors'][$step] ?? ''));
			}
			$weight = $dimension === 'benefit' ? (float)($c['weight'] ?? 0) : 0.0;
			if ($weight < 0) {
				throw new ValidationException('Weights must not be negative');
			}
			$weights += $weight;
			$codes[$code] = true;
			$clean[] = ['code' => $code, 'name' => $name, 'dimension' => $dimension, 'weight' => $weight, 'anchors' => $anchors];
		}
		foreach (self::DIMENSIONS as $dimension) {
			if (!in_array($dimension, array_column($clean, 'dimension'), true)) {
				throw new ValidationException('Every dimension needs at least one criterion');
			}
		}
		if (abs($weights - 100) > 0.01) {
			throw new ValidationException('The weights of the benefit criteria must add up to 100');
		}
		$active = $this->activeSet();
		$note = trim((string)$note);
		$this->db->beginTransaction();
		try {
			$id = $this->scoring->insertSet($active['version'] + 1, $active['name'], $note === '' ? null : $note, $userId, $this->time->getTime(), $clean);
			$this->audit->log('criterion_set', $id, 'create', $userId, ['version' => [$active['version'], $active['version'] + 1]], $note === '' ? null : $note);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->model($userId);
	}

	/**
	 * Zielquote und Budgetrahmen eines Buckets.
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException
	 */
	public function updateBucket(string $userId, string $code, float $share, ?float $budget): array {
		if (!$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may change the buckets');
		}
		$buckets = array_column($this->scoring->buckets(), null, 'code');
		if (!isset($buckets[$code])) {
			throw new ValidationException('Unknown bucket');
		}
		if ($share < 0 || $share > 100 || ($budget !== null && $budget < 0)) {
			throw new ValidationException('Invalid share or budget');
		}
		$this->scoring->updateBucket($code, $share, $budget);
		$this->audit->log('bucket', $buckets[$code]['id'], 'update', $userId, [
			'targetSharePct' => [$buckets[$code]['targetSharePct'], $share],
			'targetBudget' => [$buckets[$code]['targetBudget'], $budget],
		]);
		return $this->model($userId);
	}

	// ---- Bewertung ----------------------------------------------------------------------------

	/**
	 * Bewertungen eines Antrags (Verlauf) mit der geltenden Bewertung und einem Aufwandsvorschlag.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function ofDemand(string $userId, int $demandId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$list = $this->valid($demand, $this->scoring->assessments($demandId));
		return [
			'assessments' => $list,
			'current' => self::current($list),
			'suggestedEffort' => $this->suggestedEffort($demand),
			'bucket' => $demand->getBucket() ?? self::BUCKET_OF_TYPE[$demand->getDemandType()] ?? 'C',
			'can' => [
				'self' => $this->canAssess($userId, $demand, 'self'),
				'review' => $this->canAssess($userId, $demand, 'review'),
			],
		];
	}

	/**
	 * Neue Bewertung anlegen (unveränderlich).
	 *
	 * @param array<string, mixed> $data kind, scores, effort, estimateQuality, comment, bucket (nur review)
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function assess(string $userId, int $demandId, array $data): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$kind = (string)($data['kind'] ?? '');
		if (!in_array($kind, ['self', 'review'], true)) {
			throw new ValidationException('Unknown assessment type');
		}
		if (!$this->canAssess($userId, $demand, $kind)) {
			throw new ForbiddenException($kind === 'self' ? 'Only the requestor may assess their own demand' : 'Only the portfolio office of the area may review the assessment');
		}
		$buckets = array_column($this->scoring->buckets(), null, 'code');
		$bucket = $demand->getBucket() ?? self::BUCKET_OF_TYPE[$demand->getDemandType()] ?? 'C';
		if ($kind === 'review' && isset($data['bucket']) && $data['bucket'] !== '') {
			$bucket = (string)$data['bucket'];
			if (!isset($buckets[$bucket])) {
				throw new ValidationException('Unknown bucket');
			}
		}
		$scored = $buckets[$bucket]['scored'] ?? true;
		$set = $this->activeSet();
		$criteria = $this->scoring->criteria($set['id']);
		$input = is_array($data['scores'] ?? null) ? $data['scores'] : [];
		$scores = [];
		foreach ($criteria as $c) {
			$value = $input[$c['code']] ?? null;
			// Pflicht-Bucket: kein Nutzen-Score nötig (Kapitel 03 §1)
			if ($value === null || $value === '') {
				if ($c['dimension'] === 'benefit' && !$scored) {
					continue;
				}
				throw new ValidationException('Please score every criterion');
			}
			if (!in_array((int)$value, self::SCALE, true)) {
				throw new ValidationException('Scores must be 0, 1, 3, 5 or 8');
			}
			$scores[$c['code']] = (int)$value;
		}
		$effort = (int)($data['effort'] ?? 0);
		if (!in_array($effort, self::EFFORTS, true)) {
			throw new ValidationException('Effort must be 1, 3, 5 or 8');
		}
		$quality = (string)($data['estimateQuality'] ?? 'rough');
		if (!in_array($quality, self::QUALITIES, true)) {
			throw new ValidationException('Unknown estimate quality');
		}
		$comment = trim((string)($data['comment'] ?? ''));
		$values = self::compute($criteria, $scores, $effort);
		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			$id = $this->scoring->insertAssessment([
				'demandId' => $demandId, 'setId' => $set['id'], 'kind' => $kind, 'scores' => $scores, 'effort' => $effort,
				'estimateQuality' => $quality, 'comment' => $comment === '' ? null : $comment,
				'createdBy' => $userId, 'createdAt' => $now,
			] + $values);
			$changes = ['assessment' => [null, $kind . ' #' . $id], 'density' => [null, $values['density']]];
			if ($kind === 'review' && $demand->getBucket() !== $bucket) {
				$changes['bucket'] = [$demand->getBucket(), $bucket];
				$demand->setBucket($bucket);
				$demand->setUpdatedAt($now);
				$this->demandMapper->update($demand);
			}
			$this->audit->log(DemandService::ENTITY, $demandId, 'assessment', $userId, $changes);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->ofDemand($userId, $demandId);
	}

	public function canAssess(string $userId, Demand $demand, string $kind): bool {
		if (!in_array($demand->getStatus(), self::ASSESSABLE, true)) {
			return false;
		}
		return $kind === 'self' ? $demand->getRequestorId() === $userId : $this->permissions->canTriage($userId, $demand);
	}

	/**
	 * Kennwerte einer Bewertung berechnen.
	 *
	 * @param list<array<string, mixed>> $criteria
	 * @param array<string, int> $scores
	 * @return array{benefit: float, urgency: float, risk: float, density: float}
	 */
	public static function compute(array $criteria, array $scores, int $effort): array {
		$benefit = 0.0;
		$urgency = 0.0;
		$risks = [];
		foreach ($criteria as $c) {
			if (!isset($scores[$c['code']])) {
				continue;
			}
			$value = $scores[$c['code']];
			match ($c['dimension']) {
				'benefit' => $benefit += $value * $c['weight'] / 100,
				'urgency' => $urgency = max($urgency, (float)$value),
				default => $risks[] = $value,
			};
		}
		$risk = $risks === [] ? 0.0 : array_sum($risks) / count($risks);
		$density = ($benefit * (1 + $urgency / 8)) / (max(1, $effort) * (1 + $risk / 16));
		return ['benefit' => round($benefit, 2), 'urgency' => $urgency, 'risk' => round($risk, 2), 'density' => round($density, 2)];
	}

	/**
	 * Geltende Bewertung: die letzte Fremdbewertung, sonst die letzte Selbstbewertung.
	 *
	 * @param list<array<string, mixed>> $assessments älteste zuerst
	 * @return array<string, mixed>|null
	 */
	public static function current(array $assessments): ?array {
		$self = null;
		$review = null;
		foreach ($assessments as $a) {
			if ($a['kind'] === 'review') {
				$review = $a;
			} else {
				$self = $a;
			}
		}
		$current = $review ?? $self;
		if ($current === null) {
			return null;
		}
		// Abweichung zwischen Selbst- und Fremdbewertung bleibt sichtbar (Kapitel 03 §5)
		return $current + ['selfDensity' => $self['density'] ?? null, 'reviewDensity' => $review['density'] ?? null];
	}

	/**
	 * Aufwandsklasse aus dem erfassten Ressourcenbedarf (Schwellen wie die Vorhabensklassen).
	 */
	public function suggestedEffort(Demand $demand): int {
		$days = 0.0;
		foreach ($this->needs->findForDemand($demand->getId()) as $need) {
			if ($need->getUpdatedAt() >= $demand->getCreatedAt()) {
				$days += $need->getPersonDays();
			}
		}
		return self::effortForDays($days);
	}

	public static function effortForDays(float $days): int {
		return match (true) {
			$days < 5 => 1,
			$days < 30 => 3,
			$days < 200 => 5,
			default => 8,
		};
	}

	/**
	 * Bewertungen ab dem Anlegen des Antrags (keine Übernahme bei neu vergebener ID).
	 *
	 * @param list<array<string, mixed>> $assessments
	 * @return list<array<string, mixed>>
	 */
	public function valid(Demand $demand, array $assessments): array {
		return array_values(array_filter($assessments, static fn (array $a) => $a['demandId'] === $demand->getId() && $a['createdAt'] >= $demand->getCreatedAt()));
	}

	/** @return array<string, mixed> */
	private function activeSet(): array {
		foreach ($this->scoring->sets() as $set) {
			if ($set['status'] === 'active') {
				return $set;
			}
		}
		throw new \RuntimeException('No active criterion set');
	}
}
