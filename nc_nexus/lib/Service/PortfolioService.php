<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\OrgUnitMapper;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Portfolioübersicht (POR-001): Vorhaben über Organisation und Zeit, verdichtet zu
 * Portfoliophasen (Kapitel 02 §4.2). Gezählt werden nur Anträge, die das Konto lesen darf.
 */
class PortfolioService {
	/** Portfoliophasen in Anzeige-Reihenfolge; „planned“, „active“, „review“ entstehen mit Projekten (Stufe 2) */
	public const PHASES = ['demand', 'planned', 'active', 'on_hold', 'closed', 'review'];

	/** Status → Portfoliophase (Entwürfe sind privat und zählen nicht) */
	public const PHASE_OF = [
		'submitted' => 'demand',
		'query' => 'demand',
		'further_analysis' => 'demand',
		'in_qualification' => 'demand',
		'deferred' => 'on_hold',
		'rejected' => 'closed',
		'duplicate' => 'closed',
	];

	private const QUARTERS = 4;

	public function __construct(
		private DemandService $demands,
		private OrgUnitMapper $orgUnits,
		private ResourceNeedMapper $needs,
		private CapacityService $capacity,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array<string, mixed>
	 * @throws ForbiddenException
	 */
	public function overview(string $userId): array {
		if (!$this->capacity->canSee($userId)) {
			throw new ForbiddenException('Only governance roles may see the portfolio');
		}
		$demands = array_filter(
			$this->demands->findAll($userId),
			static fn (Demand $d) => isset(self::PHASE_OF[$d->getStatus()]),
		);
		$byId = [];
		foreach ($demands as $d) {
			$byId[$d->getId()] = $d;
		}

		// Personentage je Antrag gesamt und je Quartal (nur offene Phasen belasten künftige Quartale)
		$quarters = $this->quarters();
		$daysTotal = [];
		$daysQuarter = [];
		foreach ($this->needs->findRange('0000-00', '9999-12') as $need) {
			$d = $byId[$need->getDemandId()] ?? null;
			if ($d === null || $need->getUpdatedAt() < $d->getCreatedAt()) {
				continue;
			}
			$daysTotal[$d->getId()] = ($daysTotal[$d->getId()] ?? 0) + $need->getPersonDays();
			$q = self::quarterOf($need->getPeriod());
			if (in_array($q, $quarters, true) && self::PHASE_OF[$d->getStatus()] === 'demand') {
				$daysQuarter[$d->getId()][$q] = ($daysQuarter[$d->getId()][$q] ?? 0) + $need->getPersonDays();
			}
		}

		// Org-Baum: jede Einheit zählt ihre eigenen Anträge und die aller Untereinheiten
		$parents = [];
		foreach ($this->orgUnits->findAll() as $unit) {
			$parents[$unit->getId()] = $unit->getParentId();
		}
		$rows = [];
		$empty = fn () => [
			'phases' => array_fill_keys(self::PHASES, ['count' => 0, 'days' => 0.0]),
			'quarters' => array_fill_keys($quarters, 0.0),
			'total' => 0,
		];
		foreach (array_keys($parents) as $id) {
			$rows[$id] = $empty();
		}
		$rows['none'] = $empty();
		$totals = $empty();
		foreach ($demands as $d) {
			$phase = self::PHASE_OF[$d->getStatus()];
			$targets = [];
			$unit = $d->getOrgUnitId();
			// array_key_exists statt isset: Einheiten der obersten Ebene haben den Elternwert null
			if ($unit === null || !array_key_exists($unit, $parents)) {
				$targets[] = 'none';
			} else {
				for ($u = $unit, $seen = []; $u !== null && !isset($seen[$u]); $u = $parents[$u] ?? null) {
					$seen[$u] = true;
					$targets[] = $u;
				}
			}
			$days = $daysTotal[$d->getId()] ?? 0.0;
			$perQuarter = $daysQuarter[$d->getId()] ?? [];
			foreach ($targets as $key) {
				$rows[$key] = $this->add($rows[$key], $phase, $days, $perQuarter);
			}
			$totals = $this->add($totals, $phase, $days, $perQuarter);
		}
		$out = [];
		foreach ($rows as $key => $row) {
			$out[] = ['orgUnitId' => $key === 'none' ? null : $key] + $this->rounded($row);
		}
		return [
			'phases' => self::PHASES,
			'quarters' => $quarters,
			'rows' => $out,
			'totals' => $this->rounded($totals),
		];
	}

	/** @return list<string> die nächsten Quartale ab dem aktuellen, z. B. 2026-Q4 */
	public function quarters(): array {
		$year = (int)date('Y', $this->time->getTime());
		$q = intdiv((int)date('n', $this->time->getTime()) - 1, 3) + 1;
		$result = [];
		for ($i = 0; $i < self::QUARTERS; $i++) {
			$result[] = $year . '-Q' . $q;
			if (++$q > 4) {
				$q = 1;
				$year++;
			}
		}
		return $result;
	}

	public static function quarterOf(string $period): string {
		return substr($period, 0, 4) . '-Q' . (intdiv((int)substr($period, 5, 2) - 1, 3) + 1);
	}

	/**
	 * Einen Antrag in eine Zeile einrechnen.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, float> $perQuarter
	 * @return array<string, mixed>
	 */
	private function add(array $row, string $phase, float $days, array $perQuarter): array {
		$row['phases'][$phase]['count']++;
		$row['phases'][$phase]['days'] += $days;
		$row['total']++;
		foreach ($perQuarter as $q => $value) {
			$row['quarters'][$q] += $value;
		}
		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function rounded(array $row): array {
		foreach ($row['phases'] as $p => $v) {
			$row['phases'][$p]['days'] = round($v['days'], 1);
		}
		foreach ($row['quarters'] as $q => $v) {
			$row['quarters'][$q] = round($v, 1);
		}
		return $row;
	}
}
