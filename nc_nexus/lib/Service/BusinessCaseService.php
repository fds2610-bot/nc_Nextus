<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\BusinessCaseMapper;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Business Case (FIN-001) und Nutzenregister (BEN-001), Kapitel 03 §4.
 *
 * Der Business Case ist ein strukturiertes Objekt aus Kosten, Nutzenpositionen und Annahmen. Jede
 * Nutzenposition braucht einen benannten Verantwortlichen und eine Messgröße mit Ausgangswert –
 * ohne Baseline ist ein späteres Nutzenreview unmöglich. An Gates wird der Stand als Version
 * eingefroren; bearbeitet wird immer der Entwurf.
 */
class BusinessCaseService {
	public const COST_KINDS = ['capex', 'opex_once', 'opex_annual'];
	public const BENEFIT_TYPES = ['cost_saving', 'capacity', 'revenue', 'avoided_cost', 'qualitative'];
	/** Nutzenarten mit Geldwert (Nutzen p. a.) */
	private const MONETARY = ['cost_saving', 'capacity', 'revenue', 'avoided_cost'];
	/** Status, in denen der Business Case bearbeitet wird (nach G0 bis zur Freigabe) */
	public const EDITABLE = ['in_qualification', 'candidate', 'prioritized'];
	private const YEARS = 5;

	public function __construct(
		private BusinessCaseMapper $cases,
		private DemandService $demands,
		private PermissionService $permissions,
		private AuditService $audit,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Entwurf (oder letzte eingefrorene Version), Kennzahlen und Versionsliste.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function get(string $userId, int $demandId): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		$versions = $this->valid($demand, $this->cases->versions($demandId));
		$current = $this->current($versions);
		$content = $current['content'] ?? self::empty();
		return [
			'content' => $content,
			'kpis' => self::kpis($content),
			'complete' => self::missing($content) === [],
			'missing' => self::missing($content),
			'versions' => array_map(static fn (array $v) => array_diff_key($v, ['content' => 0]), $versions),
			'canEdit' => $this->canEdit($userId, $demand),
		];
	}

	/**
	 * Entwurf speichern.
	 *
	 * @param array<string, mixed> $content
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function save(string $userId, int $demandId, array $content): array {
		$demand = $this->demands->findReadable($userId, $demandId);
		if (!$this->canEdit($userId, $demand)) {
			throw new ForbiddenException('The business case can only be edited after acceptance and until release');
		}
		$clean = $this->clean($content);
		$versions = $this->valid($demand, $this->cases->versions($demandId));
		$draft = null;
		foreach ($versions as $v) {
			if ($v['status'] === 'draft') {
				$draft = $v;
			}
		}
		$now = $this->time->getTime();
		if ($draft === null) {
			$this->cases->insert($demandId, count($versions) + 1, 'draft', null, $clean, $userId, $now);
		} elseif ($draft['content'] !== $clean) {
			$this->cases->updateDraft($draft['id'], $clean, $userId, $now);
		}
		$before = $draft === null ? null : self::kpis($draft['content']);
		$after = self::kpis($clean);
		if ($before !== $after) {
			$this->audit->log(DemandService::ENTITY, $demandId, 'business_case', $userId, [
				'benefitPerYear' => [$before['benefitPerYear'] ?? null, $after['benefitPerYear']],
				'oneTimeCost' => [$before['oneTimeCost'] ?? null, $after['oneTimeCost']],
			]);
		}
		return $this->get($userId, $demandId);
	}

	/**
	 * Aktuellen Stand als Version einfrieren (an Gates); der Entwurf bleibt zur Weiterarbeit.
	 *
	 * @throws ValidationException
	 */
	public function freeze(string $userId, Demand $demand, string $reason): int {
		$versions = $this->valid($demand, $this->cases->versions($demand->getId()));
		$current = $this->current($versions);
		if ($current === null) {
			throw new ValidationException('The demand has no business case yet');
		}
		$version = count($versions) + 1;
		$this->cases->insert($demand->getId(), $version, 'frozen', $reason, $current['content'], $userId, $this->time->getTime());
		return $version;
	}

	/**
	 * Pflichtangaben, die für ein Gate fehlen (leer = vollständig).
	 *
	 * @param array<string, mixed> $content
	 * @return list<string>
	 */
	public static function missing(array $content): array {
		$missing = [];
		if (($content['benefits'] ?? []) === []) {
			$missing[] = 'benefit';
		}
		if (($content['costs'] ?? []) === []) {
			$missing[] = 'cost';
		}
		return $missing;
	}

	/**
	 * Inhalt für ein Gate prüfen.
	 *
	 * @return list<string>
	 * @throws NotFoundException
	 */
	public function missingFor(Demand $demand): array {
		$current = $this->current($this->valid($demand, $this->cases->versions($demand->getId())));
		return $current === null ? ['business_case'] : self::missing($current['content']);
	}

	/**
	 * Kennzahlen (Kapitel 03 §4): einmalige und laufende Kosten, Nutzen p. a., TCO über 5 Jahre,
	 * Amortisationsdauer, Kapitalwert bei Kalkulationszins.
	 *
	 * @param array<string, mixed> $content
	 * @return array<string, float|null>
	 */
	public static function kpis(array $content): array {
		$oneTime = 0.0;
		$annualCost = 0.0;
		foreach ($content['costs'] ?? [] as $c) {
			if ($c['kind'] === 'opex_annual') {
				$annualCost += (float)$c['amount'];
			} else {
				$oneTime += (float)$c['amount'];
			}
		}
		$benefit = 0.0;
		foreach ($content['benefits'] ?? [] as $b) {
			if (in_array($b['type'], self::MONETARY, true)) {
				$benefit += (float)($b['amount'] ?? 0);
			}
		}
		$net = $benefit - $annualCost;
		$rate = (float)($content['discountRate'] ?? 0) / 100;
		$npv = -$oneTime;
		for ($y = 1; $y <= self::YEARS; $y++) {
			$npv += $net / ((1 + $rate) ** $y);
		}
		return [
			'oneTimeCost' => round($oneTime, 2),
			'annualCost' => round($annualCost, 2),
			'benefitPerYear' => round($benefit, 2),
			'tco' => round($oneTime + self::YEARS * $annualCost, 2),
			'paybackYears' => $net > 0 ? round($oneTime / $net, 1) : null,
			'npv' => round($npv, 2),
		];
	}

	/**
	 * Nutzenregister: alle Nutzenpositionen der Vorhaben, die das Konto lesen darf.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function register(string $userId): array {
		$demands = [];
		foreach ($this->demands->findAll($userId) as $d) {
			if ($d->getStatus() !== DemandService::STATUS_DRAFT) {
				$demands[$d->getId()] = $d;
			}
		}
		$byDemand = [];
		foreach ($this->cases->versions() as $v) {
			if (isset($demands[$v['demandId']])) {
				$byDemand[$v['demandId']][] = $v;
			}
		}
		$rows = [];
		foreach ($byDemand as $demandId => $versions) {
			$demand = $demands[$demandId];
			$current = $this->current($this->valid($demand, $versions));
			foreach ($current['content']['benefits'] ?? [] as $i => $b) {
				$rows[] = $b + [
					'demandId' => $demandId,
					'demandKey' => $demand->getDemandKey(),
					'demandTitle' => $demand->getTitle(),
					'demandStatus' => $demand->getStatus(),
					'position' => $i + 1,
					'version' => $current['version'],
					'versionStatus' => $current['status'],
				];
			}
		}
		return $rows;
	}

	public function canEdit(string $userId, Demand $demand): bool {
		if (!in_array($demand->getStatus(), self::EDITABLE, true)) {
			return false;
		}
		return $demand->getRequestorId() === $userId || $this->permissions->canTriage($userId, $demand);
	}

	/** @return array<string, mixed> */
	public static function empty(): array {
		return ['costs' => [], 'benefits' => [], 'assumptions' => [], 'discountRate' => 5.0];
	}

	/**
	 * Eingaben prüfen und normalisieren.
	 *
	 * @param array<string, mixed> $content
	 * @return array<string, mixed>
	 * @throws ValidationException
	 */
	private function clean(array $content): array {
		$costs = [];
		foreach (is_array($content['costs'] ?? null) ? $content['costs'] : [] as $c) {
			$label = trim((string)($c['label'] ?? ''));
			$kind = (string)($c['kind'] ?? '');
			$amount = (float)($c['amount'] ?? 0);
			if ($label === '' || !in_array($kind, self::COST_KINDS, true) || $amount < 0) {
				throw new ValidationException('Every cost item needs a label, a kind and a non-negative amount');
			}
			$costs[] = ['label' => $label, 'kind' => $kind, 'amount' => round($amount, 2)];
		}
		$benefits = [];
		foreach (is_array($content['benefits'] ?? null) ? $content['benefits'] : [] as $b) {
			$type = (string)($b['type'] ?? '');
			$title = trim((string)($b['title'] ?? ''));
			$metric = trim((string)($b['metric'] ?? ''));
			$baseline = trim((string)($b['baseline'] ?? ''));
			$owner = trim((string)($b['ownerUserId'] ?? ''));
			if (!in_array($type, self::BENEFIT_TYPES, true) || $title === '') {
				throw new ValidationException('Every benefit item needs a type and a title');
			}
			// Pflichtregel Kapitel 03 §4: benannter Verantwortlicher und Messgröße mit Ausgangswert
			if ($owner === '' || !$this->userManager->userExists($owner)) {
				throw new ValidationException('Every benefit item needs a named owner (existing account)');
			}
			if ($metric === '' || $baseline === '') {
				throw new ValidationException('Every benefit item needs a metric with a baseline');
			}
			$amount = in_array($type, self::MONETARY, true) ? round(max(0, (float)($b['amount'] ?? 0)), 2) : null;
			$benefits[] = [
				'type' => $type,
				'title' => $title,
				'amount' => $amount,
				'metric' => $metric,
				'baseline' => $baseline,
				'target' => trim((string)($b['target'] ?? '')),
				'ownerUserId' => $owner,
				'method' => trim((string)($b['method'] ?? '')),
				'from' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($b['from'] ?? '')) ? (string)$b['from'] : null,
			];
		}
		$assumptions = [];
		foreach (is_array($content['assumptions'] ?? null) ? $content['assumptions'] : [] as $a) {
			$text = trim((string)($a['text'] ?? ''));
			if ($text === '') {
				continue;
			}
			$check = (string)($a['checkDate'] ?? '');
			$assumptions[] = [
				'text' => $text,
				'rationale' => trim((string)($a['rationale'] ?? '')),
				'ownerUserId' => trim((string)($a['ownerUserId'] ?? '')),
				'checkDate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $check) ? $check : null,
			];
		}
		$rate = (float)($content['discountRate'] ?? 5);
		if ($rate < 0 || $rate > 50) {
			throw new ValidationException('Invalid discount rate');
		}
		return ['costs' => $costs, 'benefits' => $benefits, 'assumptions' => $assumptions, 'discountRate' => $rate];
	}

	/**
	 * Entwurf, sonst die letzte eingefrorene Version.
	 *
	 * @param list<array<string, mixed>> $versions
	 * @return array<string, mixed>|null
	 */
	private function current(array $versions): ?array {
		$current = null;
		foreach ($versions as $v) {
			if ($v['status'] === 'draft') {
				return $v;
			}
			$current = $v;
		}
		return $current;
	}

	/**
	 * @param list<array<string, mixed>> $versions
	 * @return list<array<string, mixed>>
	 */
	private function valid(Demand $demand, array $versions): array {
		return array_values(array_filter($versions, static fn (array $v) => $v['createdAt'] >= $demand->getCreatedAt()));
	}
}
