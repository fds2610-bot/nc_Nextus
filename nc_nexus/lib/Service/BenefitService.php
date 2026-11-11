<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Nutzensteuerung (BEN-002, Kapitel 03 §4.1): Prognose und Realisierungsstatus je Nutzenposition,
 * Messzeitpunkte nach Go-Live, Nutzenreviews mit Ist-Wert und Abweichungsanalyse, Soll/Ist-
 * Nutzenbilanz und Korrekturfaktoren je Nutzenart als Rückkopplung in künftige Business Cases.
 *
 * Der Benefit Owner bleibt nach Projektabschluss verantwortlich: Er sieht und pflegt seine
 * Positionen auch ohne Leserecht auf den übrigen Antrag.
 */
class BenefitService {
	public const TRACKING = 'nx_benefit_tracking';
	public const REVIEWS = 'nx_benefit_reviews';
	public const STATUSES = ['not_started', 'on_track', 'at_risk', 'realized', 'missed'];
	public const ASSESSMENTS = ['achieved', 'partial', 'missed'];
	/** Ab der Freigabe ist der Nutzen zugesagt und wird verfolgt */
	public const TRACKED = ['released', 'in_execution', 'paused', 'completed', 'closed'];
	/** Nutzenarten mit Geldwert */
	public const MONETARY = ['cost_saving', 'capacity', 'revenue', 'avoided_cost'];

	public function __construct(
		private Rows $rows,
		private DemandMapper $demandMapper,
		private BusinessCaseService $cases,
		private PermissionService $permissions,
		private ConditionService $conditions,
		private AuditService $audit,
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Nutzenregister mit Prognose, Status, Messzeitpunkten und Reviews; dazu Nutzenbilanz und
	 * Korrekturfaktoren. Sichtbar sind lesbare Vorhaben und die eigenen Positionen.
	 *
	 * @return array{rows: list<array<string, mixed>>, balance: array<string, mixed>, factors: array<string, mixed>}
	 */
	public function register(string $userId): array {
		$rows = [];
		foreach ($this->positions() as $p) {
			$demand = $p['demand'];
			$readable = $this->permissions->canReadDemand($userId, $demand);
			if (!$readable && $p['benefit']['ownerUserId'] !== $userId) {
				continue;
			}
			$rows[] = $this->row($userId, $p, $readable);
		}
		return ['rows' => $rows, 'balance' => self::balance($rows), 'factors' => $this->factors()];
	}

	/**
	 * Prognose, Realisierungsstatus und Messzeitpunkte einer Position pflegen.
	 *
	 * @param array<string, mixed> $data forecast, forecastAmount, realizationStatus, reviewDates
	 * @return array<string, mixed> Registerzeile
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function track(string $userId, int $demandId, string $key, array $data): array {
		$p = $this->position($userId, $demandId, $key);
		$this->requireTrack($userId, $p);
		$status = (string)($data['realizationStatus'] ?? 'not_started');
		if (!in_array($status, self::STATUSES, true)) {
			throw new ValidationException('Unknown realization status');
		}
		$dates = [];
		foreach (is_array($data['reviewDates'] ?? null) ? $data['reviewDates'] : [] as $d) {
			$d = (string)$d;
			if ($d === '') {
				continue;
			}
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
				throw new ValidationException('Invalid review date');
			}
			$dates[$d] = $d;
		}
		sort($dates);
		if ($dates === []) {
			throw new ValidationException('A benefit item needs at least one review date');
		}
		$forecast = trim((string)($data['forecast'] ?? ''));
		$amount = ($data['forecastAmount'] ?? null) === null || $data['forecastAmount'] === ''
			? null : round(max(0.0, (float)$data['forecastAmount']), 2);
		if (!in_array($p['benefit']['type'], self::MONETARY, true)) {
			$amount = null;
		}
		$values = [
			'forecast' => $forecast === '' ? null : mb_substr($forecast, 0, 255),
			'forecastAmount' => $amount, 'realizationStatus' => $status, 'reviewDates' => $dates,
		];
		$now = $this->time->getTime();
		$before = $p['tracking'];
		if ($before === null) {
			$this->rows->insert(self::TRACKING, $values + [
				'demandId' => $demandId, 'benefitKey' => $key, 'updatedBy' => $userId, 'createdBy' => $userId, 'createdAt' => $now, 'updatedAt' => $now,
			]);
		} else {
			$this->rows->update(self::TRACKING, $before['id'], $values + ['updatedBy' => $userId, 'updatedAt' => $now]);
		}
		$changes = [];
		$old = $this->effectiveTracking($p);
		foreach (['forecast', 'forecastAmount', 'realizationStatus', 'reviewDates'] as $field) {
			if ($old[$field] !== $values[$field]) {
				$changes[$field] = [$old[$field], $values[$field]];
			}
		}
		if ($changes !== []) {
			$this->audit->log(DemandService::ENTITY, $demandId, 'benefit_tracking', $userId, $changes, (string)$p['benefit']['title']);
		}
		return $this->row($userId, $this->position($userId, $demandId, $key), true);
	}

	/**
	 * Nutzenreview erfassen (unveränderlich): Ist-Wert, Zielerreichung, Abweichungsanalyse,
	 * Lessons Learned und – bei verfehltem Nutzen – mindestens eine Maßnahme.
	 *
	 * @param array<string, mixed> $data reviewDate, actualValue, actualAmount, assessment, explanation, lessonsLearned, actions
	 * @return array<string, mixed> Registerzeile
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function review(string $userId, int $demandId, string $key, array $data): array {
		$p = $this->position($userId, $demandId, $key);
		$this->requireTrack($userId, $p);
		$date = (string)($data['reviewDate'] ?? '');
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			throw new ValidationException('A review needs a date');
		}
		$actual = trim((string)($data['actualValue'] ?? ''));
		if ($actual === '') {
			throw new ValidationException('A review needs the measured actual value');
		}
		$monetary = in_array($p['benefit']['type'], self::MONETARY, true);
		$planned = $monetary ? (float)($p['benefit']['amount'] ?? 0) : null;
		$actualAmount = null;
		if ($monetary) {
			if (($data['actualAmount'] ?? null) === null || $data['actualAmount'] === '') {
				throw new ValidationException('A review of a monetary benefit needs the realized amount per year');
			}
			$actualAmount = round(max(0.0, (float)$data['actualAmount']), 2);
		}
		$pct = null;
		if ($planned !== null && $planned > 0) {
			$pct = (int)round($actualAmount / $planned * 100);
		} elseif (($data['achievementPct'] ?? null) !== null && $data['achievementPct'] !== '') {
			$pct = max(0, min(1000, (int)$data['achievementPct']));
		}
		$assessment = (string)($data['assessment'] ?? ($pct === null ? '' : self::assess($pct)));
		if (!in_array($assessment, self::ASSESSMENTS, true)) {
			throw new ValidationException('Assess the review: achieved, partial or missed');
		}
		$explanation = trim((string)($data['explanation'] ?? ''));
		if ($assessment !== 'achieved' && $explanation === '') {
			throw new ValidationException('A deviation needs an explanation');
		}
		$demand = $p['demand'];
		$actions = $this->conditions->validate($data['actions'] ?? []);
		if ($assessment === 'missed' && $actions === []) {
			throw new ValidationException('A missed benefit needs at least one action');
		}
		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			$id = $this->rows->insert(self::REVIEWS, [
				'demandId' => $demandId, 'benefitKey' => $key, 'reviewDate' => $date, 'actualValue' => mb_substr($actual, 0, 255),
				'actualAmount' => $actualAmount, 'plannedAmount' => $planned, 'achievementPct' => $pct, 'assessment' => $assessment,
				'explanation' => $explanation === '' ? null : $explanation,
				'lessonsLearned' => trim((string)($data['lessonsLearned'] ?? '')) ?: null,
				'createdBy' => $userId, 'createdAt' => $now,
			]);
			$this->conditions->createFor($userId, $demand, null, null, 'action', $actions, 'benefit_review', $id);
			// Realisierungsstatus folgt dem Review; weitere Messzeitpunkte bleiben bestehen
			$tracking = $this->effectiveTracking($p);
			$status = ['achieved' => 'realized', 'partial' => 'at_risk', 'missed' => 'missed'][$assessment];
			$values = ['realizationStatus' => $status, 'updatedBy' => $userId, 'updatedAt' => $now];
			if ($p['tracking'] === null) {
				$this->rows->insert(self::TRACKING, $values + [
					'demandId' => $demandId, 'benefitKey' => $key, 'forecast' => null, 'forecastAmount' => null,
					'reviewDates' => $tracking['reviewDates'], 'createdBy' => $userId, 'createdAt' => $now,
				]);
			} else {
				$this->rows->update(self::TRACKING, $p['tracking']['id'], $values);
			}
			$this->audit->log(DemandService::ENTITY, $demandId, 'benefit_review', $userId, [
				'assessment' => [null, $assessment], 'actualValue' => [null, $actual], 'achievementPct' => [null, $pct],
				'realizationStatus' => [$tracking['realizationStatus'], $status],
			], (string)$p['benefit']['title']);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->row($userId, $this->position($userId, $demandId, $key), true);
	}

	/**
	 * Korrekturfaktoren je Nutzenart (Kapitel 03 §4.1 Rückkopplung): realisierter ÷ zugesagter Nutzen
	 * über den jeweils letzten Review jeder Position mit Geldwert.
	 *
	 * @return array{byType: array<string, array{planned: float, actual: float, count: int, factor: float|null}>, overall: array{planned: float, actual: float, count: int, factor: float|null}}
	 */
	public function factors(): array {
		$latest = [];
		$demands = [];
		foreach ($this->demandMapper->findAll() as $d) {
			$demands[$d->getId()] = $d;
		}
		foreach ($this->rows->where(self::REVIEWS, [], 'createdAt') as $r) {
			$d = $demands[$r['demandId']] ?? null;
			if ($d !== null && $r['createdAt'] >= $d->getCreatedAt() && $r['plannedAmount'] !== null && $r['plannedAmount'] > 0) {
				$latest[$r['demandId'] . ':' . $r['benefitKey']] = $r;
			}
		}
		$types = [];
		foreach ($this->positions() as $p) {
			$types[$p['demand']->getId() . ':' . $p['benefit']['key']] = (string)$p['benefit']['type'];
		}
		$byType = [];
		$overall = ['planned' => 0.0, 'actual' => 0.0, 'count' => 0, 'factor' => null];
		foreach ($latest as $id => $r) {
			$type = $types[$id] ?? null;
			if ($type === null) {
				continue;
			}
			$byType[$type] ??= ['planned' => 0.0, 'actual' => 0.0, 'count' => 0, 'factor' => null];
			$add = ['planned' => (float)$r['plannedAmount'], 'actual' => (float)$r['actualAmount'], 'count' => 1];
			self::add($byType[$type], $add);
			self::add($overall, $add);
		}
		foreach (array_keys($byType) as $type) {
			$byType[$type]['factor'] = round($byType[$type]['actual'] / $byType[$type]['planned'], 2);
		}
		if ($overall['planned'] > 0) {
			$overall['factor'] = round($overall['actual'] / $overall['planned'], 2);
		}
		return ['byType' => $byType, 'overall' => $overall];
	}

	/**
	 * Pflichtprüfung für G6: Jede Nutzenposition hat mindestens ein Review.
	 *
	 * @return list<string> Titel der Positionen ohne Review
	 */
	public function unreviewed(Demand $demand): array {
		$missing = [];
		foreach ($this->positions([$demand]) as $p) {
			if ($p['reviews'] === []) {
				$missing[] = (string)$p['benefit']['title'];
			}
		}
		return $missing;
	}

	/**
	 * Fällige Nutzenreviews (nächster Messzeitpunkt erreicht) für die Erinnerung im Tagesjob.
	 *
	 * @return list<array{demand: Demand, benefit: array<string, mixed>, due: string, sponsorId: string|null}>
	 */
	public function due(): array {
		$today = date('Y-m-d', $this->time->getTime());
		$due = [];
		foreach ($this->positions() as $p) {
			if (!in_array($p['demand']->getStatus(), self::TRACKED, true)) {
				continue;
			}
			$next = self::nextReview($this->effectiveTracking($p)['reviewDates'], count($p['reviews']));
			if ($next !== null && $next <= $today) {
				$due[] = ['demand' => $p['demand'], 'benefit' => $p['benefit'], 'due' => $next, 'sponsorId' => $p['project']['sponsorId'] ?? null];
			}
		}
		return $due;
	}

	/** Bewertung aus der Zielerreichung: ab 90 % erreicht, ab 50 % teilweise, sonst verfehlt. */
	public static function assess(int $pct): string {
		return $pct >= 90 ? 'achieved' : ($pct >= 50 ? 'partial' : 'missed');
	}

	/**
	 * Nächster offener Messzeitpunkt: Jedes Review verbraucht einen Termin.
	 *
	 * @param list<string> $dates
	 */
	public static function nextReview(array $dates, int $reviews): ?string {
		return $dates[$reviews] ?? null;
	}

	/**
	 * Soll/Ist-Nutzenbilanz (zugesagt vs. prognostiziert vs. realisiert) je Nutzenart.
	 *
	 * @param list<array<string, mixed>> $rows Registerzeilen
	 * @return array<string, mixed>
	 */
	public static function balance(array $rows): array {
		$byType = [];
		$total = ['committed' => 0.0, 'forecast' => 0.0, 'realized' => 0.0, 'reviewedCommitted' => 0.0];
		$statuses = array_fill_keys(self::STATUSES, 0);
		foreach ($rows as $r) {
			if (!$r['committed']) {
				continue;
			}
			$statuses[$r['realizationStatus']]++;
			if ($r['amount'] === null) {
				continue;
			}
			$type = (string)$r['type'];
			$byType[$type] ??= ['committed' => 0.0, 'forecast' => 0.0, 'realized' => 0.0, 'reviewedCommitted' => 0.0];
			$latest = $r['reviews'] === [] ? null : $r['reviews'][count($r['reviews']) - 1];
			$reviewed = $latest !== null && $latest['actualAmount'] !== null;
			$add = [
				'committed' => (float)$r['amount'],
				'forecast' => (float)($r['forecastAmount'] ?? $r['amount']),
				'realized' => $reviewed ? (float)$latest['actualAmount'] : 0.0,
				'reviewedCommitted' => $reviewed ? (float)$r['amount'] : 0.0,
			];
			self::add($byType[$type], $add);
			self::add($total, $add);
		}
		return ['byType' => $byType, 'total' => $total, 'statuses' => $statuses];
	}

	// ---- intern -------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $sum
	 * @param array<string, float|int> $add
	 */
	private static function add(array &$sum, array $add): void {
		foreach ($add as $k => $v) {
			$sum[$k] += $v;
		}
	}

	/**
	 * Alle Nutzenpositionen (ohne Entwürfe und abgebrochene Vorhaben vor der Freigabe) mit Verfolgung und Reviews.
	 *
	 * @param list<Demand>|null $only
	 * @return list<array{demand: Demand, benefit: array<string, mixed>, version: int, tracking: array<string, mixed>|null, reviews: list<array<string, mixed>>, project: array<string, mixed>|null}>
	 */
	private function positions(?array $only = null): array {
		$demands = array_values(array_filter(
			$only ?? $this->demandMapper->findAll(),
			static fn (Demand $d) => $d->getStatus() !== DemandService::STATUS_DRAFT,
		));
		$byId = [];
		foreach ($demands as $d) {
			$byId[$d->getId()] = $d;
		}
		$where = $only === null ? [] : ['demandId' => array_keys($byId)];
		$tracking = [];
		foreach ($this->rows->where(self::TRACKING, $where) as $t) {
			$d = $byId[$t['demandId']] ?? null;
			if ($d !== null && $t['createdAt'] >= $d->getCreatedAt()) {
				$tracking[$t['demandId'] . ':' . $t['benefitKey']] = $t;
			}
		}
		$reviews = [];
		foreach ($this->rows->where(self::REVIEWS, $where, 'reviewDate') as $r) {
			$d = $byId[$r['demandId']] ?? null;
			if ($d !== null && $r['createdAt'] >= $d->getCreatedAt()) {
				$reviews[$r['demandId'] . ':' . $r['benefitKey']][] = $r;
			}
		}
		$projects = [];
		foreach ($this->rows->where('nx_projects', $where) as $row) {
			$d = $byId[$row['demandId']] ?? null;
			if ($d !== null && $row['createdAt'] >= $d->getCreatedAt()) {
				$projects[$row['demandId']] = $row;
			}
		}
		$result = [];
		foreach ($this->cases->benefitsOf($demands) as $demandId => $case) {
			foreach ($case['benefits'] as $b) {
				$id = $demandId . ':' . $b['key'];
				$result[] = [
					'demand' => $byId[$demandId], 'benefit' => $b, 'version' => $case['version'],
					'tracking' => $tracking[$id] ?? null, 'reviews' => $reviews[$id] ?? [], 'project' => $projects[$demandId] ?? null,
				];
			}
		}
		return $result;
	}

	/**
	 * @return array{demand: Demand, benefit: array<string, mixed>, version: int, tracking: array<string, mixed>|null, reviews: list<array<string, mixed>>, project: array<string, mixed>|null}
	 * @throws NotFoundException
	 */
	private function position(string $userId, int $demandId, string $key): array {
		try {
			$demand = $this->demandMapper->find($demandId);
		} catch (\Throwable) {
			throw new NotFoundException('Demand not found');
		}
		foreach ($this->positions([$demand]) as $p) {
			if ($p['benefit']['key'] === $key
				&& ($p['benefit']['ownerUserId'] === $userId || $this->permissions->canReadDemand($userId, $demand))) {
				return $p;
			}
		}
		throw new NotFoundException('Benefit item not found');
	}

	/**
	 * Verfolgung mit Startwerten: ohne eigene Messzeitpunkte gilt der bei G3 gesetzte Reviewtermin.
	 *
	 * @param array<string, mixed> $p
	 * @return array{forecast: string|null, forecastAmount: float|null, realizationStatus: string, reviewDates: list<string>}
	 */
	private function effectiveTracking(array $p): array {
		$t = $p['tracking'];
		$dates = $t['reviewDates'] ?? null;
		if (!is_array($dates) || $dates === []) {
			$due = $p['project']['benefitReviewDue'] ?? null;
			$dates = $due === null ? [] : [(string)$due];
		}
		return [
			'forecast' => $t['forecast'] ?? null,
			'forecastAmount' => $t['forecastAmount'] ?? null,
			'realizationStatus' => (string)($t['realizationStatus'] ?? 'not_started'),
			'reviewDates' => array_values($dates),
		];
	}

	/** @param array<string, mixed> $p */
	private function canTrack(string $userId, array $p): bool {
		if (!in_array($p['demand']->getStatus(), self::TRACKED, true)) {
			return false;
		}
		return $p['benefit']['ownerUserId'] === $userId
			|| ($p['project']['sponsorId'] ?? null) === $userId
			|| $this->permissions->canTriage($userId, $p['demand'])
			|| $this->permissions->isAdmin($userId);
	}

	/**
	 * @param array<string, mixed> $p
	 * @throws ForbiddenException
	 */
	private function requireTrack(string $userId, array $p): void {
		if (!in_array($p['demand']->getStatus(), self::TRACKED, true)) {
			throw new ForbiddenException('Benefits are tracked from the release on');
		}
		if (!$this->canTrack($userId, $p)) {
			throw new ForbiddenException('Only the benefit owner, the sponsor or the portfolio office may track this benefit');
		}
	}

	/**
	 * @param array<string, mixed> $p
	 * @return array<string, mixed>
	 */
	private function row(string $userId, array $p, bool $readable): array {
		$demand = $p['demand'];
		$tracking = $this->effectiveTracking($p);
		$next = self::nextReview($tracking['reviewDates'], count($p['reviews']));
		$today = date('Y-m-d', $this->time->getTime());
		return $p['benefit'] + $tracking + [
			'demandId' => $demand->getId(),
			'demandKey' => $demand->getDemandKey(),
			'demandTitle' => $demand->getTitle(),
			'demandStatus' => $demand->getStatus(),
			'version' => $p['version'],
			'committed' => in_array($demand->getStatus(), self::TRACKED, true),
			'sponsorId' => $p['project']['sponsorId'] ?? null,
			'nextReview' => $next,
			'reviewDue' => $next !== null && $next <= $today && in_array($demand->getStatus(), self::TRACKED, true),
			'reviews' => $p['reviews'],
			'readable' => $readable,
			'canTrack' => $this->canTrack($userId, $p),
		];
	}
}
