<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\Pool;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Db\ScoringMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Konflikterkennung und Fristeskalation (RES-001, Kapitel 04 §4, 01 §7): Überbuchung, ungedeckter
 * Bedarf, Engpassspitze, WIP-Überschreitung, Zombie-Projekt, Prioritätsinversion. Ein Konflikt
 * beginnt auf Stufe 1 (bilateral) und steigt nach Fristablauf automatisch auf Stufe 2
 * (Ressourcen-Board) und 3 (Portfolio-Board) – er lässt sich nicht aussitzen.
 */
class ConflictService {
	public const TYPES = ['overbooking', 'uncovered_demand', 'bottleneck_peak', 'wip_exceeded', 'zombie_project', 'priority_inversion'];
	/** Lösungsoptionen in fester Prüfreihenfolge (Kapitel 01 §7) */
	public const OPTIONS = ['sequenced', 'scope_cut', 'substituted', 'external', 'paused', 'deprioritised'];
	private const TABLE = 'nx_conflicts';
	private const HORIZON = 12;

	public function __construct(
		private Rows $rows,
		private DemandMapper $demandMapper,
		private PoolMapper $pools,
		private ResourceNeedMapper $needs,
		private CapacityService $capacity,
		private AssessmentService $assessments,
		private ScoringMapper $scoring,
		private PermissionService $permissions,
		private SettingsService $settings,
		private AuditService $audit,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Alle Konflikte (offene zuerst), die Governance-Rollen sehen.
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException
	 */
	public function list(string $userId): array {
		if (!$this->capacity->canSee($userId)) {
			throw new ForbiddenException('Only governance roles may see the ranking');
		}
		$demands = [];
		foreach ($this->demandMapper->findAll() as $d) {
			$demands[$d->getId()] = $d;
		}
		$pools = [];
		foreach ($this->pools->findAll() as $p) {
			$pools[$p->getId()] = $p;
		}
		$canResolve = $this->canResolve($userId);
		$items = [];
		foreach ($this->rows->where(self::TABLE) as $c) {
			$items[] = $c + [
				'poolName' => isset($pools[$c['poolId'] ?? 0]) ? $pools[(int)$c['poolId']]->getName() : null,
				'projects' => array_values(array_filter(array_map(static function ($id) use ($demands) {
					$d = $demands[(int)$id] ?? null;
					return $d === null ? null : ['id' => $d->getId(), 'key' => $d->getProjectKey() ?? $d->getDemandKey(), 'title' => $d->getTitle()];
				}, $c['demandIds'] ?? []))),
				'can' => ['resolve' => $canResolve && $c['status'] === 'open'],
			];
		}
		usort($items, static fn (array $a, array $b) => [$a['status'] !== 'open', -$a['escalationLevel'], $a['id']] <=> [$b['status'] !== 'open', -$b['escalationLevel'], $b['id']]);
		return ['conflicts' => $items, 'options' => self::OPTIONS, 'canDetect' => $canResolve];
	}

	/**
	 * Erkennung ausführen: neue Konflikte auf Stufe 1 anlegen, nicht mehr bestehende schließen.
	 *
	 * @return array{created: list<array<string, mixed>>, resolved: int}
	 */
	public function detect(string $userId = 'system'): array {
		$found = $this->findConflicts();
		$now = $this->time->getTime();
		$open = [];
		foreach ($this->rows->where(self::TABLE, ['status' => 'open']) as $c) {
			$open[$c['fingerprint']] = $c;
		}
		$created = [];
		foreach ($found as $fp => $c) {
			if (isset($open[$fp])) {
				// Beteiligte und Details aktualisieren, Stufe und Frist bleiben
				$this->rows->update(self::TABLE, (int)$open[$fp]['id'], ['demandIds' => $c['demandIds'], 'detail' => $c['detail'], 'updatedAt' => $now]);
				unset($open[$fp]);
				continue;
			}
			$row = $c + [
				'fingerprint' => $fp, 'escalationLevel' => 1, 'status' => 'open',
				'escalateAt' => $now + (int)$this->settings->get('conflictLevel1Days') * 86400,
				'createdBy' => $userId, 'createdAt' => $now, 'updatedAt' => $now,
			];
			$row['id'] = $this->rows->insert(self::TABLE, $row);
			$this->audit->log('conflict', $row['id'], 'create', $userId, ['type' => [null, $c['conflictType']], 'escalationLevel' => [null, 1]]);
			$created[] = $row;
		}
		// nicht mehr erkannte Konflikte gelten als gelöst
		foreach ($open as $c) {
			$this->rows->update(self::TABLE, (int)$c['id'], ['status' => 'resolved', 'resolutionNote' => 'no longer detected', 'resolvedBy' => 'system', 'resolvedAt' => $now, 'updatedAt' => $now]);
			$this->audit->log('conflict', (int)$c['id'], 'status_change', 'system', ['status' => ['open', 'resolved']], 'no longer detected');
		}
		return ['created' => $created, 'resolved' => count($open)];
	}

	/**
	 * Fristeskalation: Stufe 1 → 2 (Ressourcen-Board) → 3 (Portfolio-Board).
	 *
	 * @return list<array<string, mixed>> eskalierte Konflikte
	 */
	public function escalate(): array {
		$now = $this->time->getTime();
		$escalated = [];
		foreach ($this->rows->where(self::TABLE, ['status' => 'open']) as $c) {
			if ($c['escalationLevel'] >= 3 || $c['escalateAt'] === null || $c['escalateAt'] > $now) {
				continue;
			}
			$level = $c['escalationLevel'] + 1;
			$next = $level < 3 ? $now + (int)$this->settings->get('conflictLevel2Days') * 86400 : null;
			$this->rows->update(self::TABLE, (int)$c['id'], ['escalationLevel' => $level, 'escalateAt' => $next, 'updatedAt' => $now]);
			$this->audit->log('conflict', (int)$c['id'], 'escalation', 'system', ['escalationLevel' => [$c['escalationLevel'], $level]]);
			$escalated[] = $c + ['escalationLevel' => $level];
		}
		return $escalated;
	}

	/**
	 * Konflikt lösen: Option in Prüfreihenfolge, vorrangige Optionen mit Begründung verworfen –
	 * oder bewusst akzeptieren (mit Begründung).
	 *
	 * @param array<string, mixed> $data option, rejected{option: reason}, note, accept
	 * @return array<string, mixed>
	 * @throws ForbiddenException|NotFoundException|ValidationException
	 */
	public function resolve(string $userId, int $id, array $data): array {
		if (!$this->canResolve($userId)) {
			throw new ForbiddenException('Only the portfolio office or pool managers may resolve conflicts');
		}
		$conflict = $this->rows->find(self::TABLE, $id) ?? throw new NotFoundException('Conflict not found');
		if ($conflict['status'] !== 'open') {
			throw new ValidationException('The conflict is already closed');
		}
		$note = trim((string)($data['note'] ?? ''));
		if ($note === '') {
			throw new ValidationException('This decision needs a reason');
		}
		$now = $this->time->getTime();
		if (!empty($data['accept'])) {
			$values = ['status' => 'accepted', 'resolutionNote' => $note];
		} else {
			$option = (string)($data['option'] ?? '');
			$index = array_search($option, self::OPTIONS, true);
			if ($index === false) {
				throw new ValidationException('Unknown resolution option');
			}
			// Die billigeren Optionen müssen begründet verworfen sein (Prüfreihenfolge)
			$rejected = is_array($data['rejected'] ?? null) ? $data['rejected'] : [];
			$reasons = [];
			foreach (array_slice(self::OPTIONS, 0, $index) as $earlier) {
				$why = trim((string)($rejected[$earlier] ?? ''));
				if ($why === '') {
					throw new ValidationException('Please explain why %1$s was not possible', [$earlier]);
				}
				$reasons[$earlier] = $why;
			}
			if ($option === 'deprioritised' && $conflict['escalationLevel'] < 3) {
				throw new ValidationException('Only the portfolio board may defer the higher-ranked project (level 3)');
			}
			$values = ['status' => 'resolved', 'resolutionOption' => $option, 'rejectedOptions' => $reasons, 'resolutionNote' => $note];
		}
		$this->rows->update(self::TABLE, $id, $values + ['resolvedBy' => $userId, 'resolvedAt' => $now, 'updatedAt' => $now]);
		$this->audit->log('conflict', $id, 'status_change', $userId, ['status' => ['open', $values['status']], 'option' => [null, $values['resolutionOption'] ?? null]], $note);
		return $this->list($userId);
	}

	/** @return list<array<string, mixed>> offene Konflikte */
	public function openAll(): array {
		return $this->rows->where(self::TABLE, ['status' => 'open']);
	}

	public function canResolve(string $userId): bool {
		return $this->permissions->canManageOrg($userId) || $this->permissions->isTriager($userId)
			|| $this->permissions->hasRole($userId, ['pool_manager'], null)
			|| array_filter($this->pools->findAll(), static fn (Pool $p) => $p->getManagerUserId() === $userId) !== [];
	}

	// ---- Erkennungsregeln (Kapitel 04 §4) -----------------------------------------------------

	/** @return array<string, array<string, mixed>> Fingerprint → Konflikt */
	private function findConflicts(): array {
		$now = $this->time->getTime();
		$month = date('Y-m', $now);
		$months = [];
		for ($i = 0; $i < self::HORIZON; $i++) {
			$months[] = date('Y-m', (int)strtotime($month . '-01 +' . $i . ' months'));
		}
		$pools = [];
		foreach ($this->pools->findAll() as $p) {
			$pools[$p->getId()] = $p;
		}
		$demands = [];
		foreach ($this->demandMapper->findAll() as $d) {
			$demands[$d->getId()] = $d;
		}
		// harte Zusagen je Pool/Monat und je Vorhaben
		$hard = [];
		$hardBy = [];
		foreach ($this->rows->where('nx_allocations', ['level' => 'hard']) as $a) {
			$d = $demands[$a['demandId']] ?? null;
			if ($d === null || $a['createdAt'] < $d->getCreatedAt()) {
				continue;
			}
			$hard[$a['poolId']][$a['period']] = ($hard[$a['poolId']][$a['period']] ?? 0) + (float)$a['personDays'];
			$hardBy[$a['poolId']][$a['period']][$a['demandId']] = true;
		}
		$capacity = [];
		foreach ($pools as $pid => $pool) {
			foreach ($this->capacity->capacityRows($pool, $month, self::HORIZON) as $row) {
				$capacity[$pid][$row['period']] = (float)$row['project'];
			}
		}
		$found = [];

		// Überbuchung und Engpassspitze
		foreach ($hard as $pid => $periods) {
			$pool = $pools[$pid] ?? null;
			if ($pool === null) {
				continue;
			}
			$streak = [];
			foreach ($months as $m) {
				$load = $periods[$m] ?? 0.0;
				$cap = $capacity[$pid][$m] ?? 0.0;
				if ($load > $cap + 0.001) {
					$found["overbooking:$pid:$m"] = $this->conflict('overbooking', $pid, $m, array_keys($hardBy[$pid][$m] ?? []), sprintf('%.1f / %.1f', $load, $cap));
					$streak[] = $m;
				} else {
					if ($pool->getIsBottleneck() === 1 && count($streak) >= 2) {
						$found["bottleneck_peak:$pid:{$streak[0]}"] = $this->conflict('bottleneck_peak', $pid, $streak[0], $this->involved($hardBy[$pid] ?? [], $streak), implode(', ', $streak));
					}
					$streak = [];
				}
			}
			if ($pool->getIsBottleneck() === 1 && count($streak) >= 2) {
				$found["bottleneck_peak:$pid:{$streak[0]}"] = $this->conflict('bottleneck_peak', $pid, $streak[0], $this->involved($hardBy[$pid] ?? [], $streak), implode(', ', $streak));
			}
		}

		// Ungedeckter Bedarf: freigegebenes Projekt mit Bedarf im Engpass-Pool ohne harte Zusage
		$needsBy = [];
		foreach ($this->needs->findRange($month, end($months)) as $need) {
			$d = $demands[$need->getDemandId()] ?? null;
			if ($d !== null && $need->getUpdatedAt() >= $d->getCreatedAt()) {
				$needsBy[$need->getDemandId()][] = $need;
			}
		}
		foreach ($demands as $d) {
			if (!in_array($d->getStatus(), ['released', 'in_execution'], true)) {
				continue;
			}
			foreach ($needsBy[$d->getId()] ?? [] as $need) {
				$pool = $pools[$need->getPoolId()] ?? null;
				if ($pool !== null && $pool->getIsBottleneck() === 1 && !isset($hardBy[$pool->getId()][$need->getPeriod()][$d->getId()])) {
					$found['uncovered_demand:' . $pool->getId() . ':' . $d->getId()] = $this->conflict('uncovered_demand', $pool->getId(), $need->getPeriod(), [$d->getId()], sprintf('%.1f PD', $need->getPersonDays()));
					break;
				}
			}
		}

		// WIP-Überschreitung im laufenden Monat
		foreach ($pools as $pid => $pool) {
			$active = array_keys($hardBy[$pid][$month] ?? []);
			if ($pool->getWipLimit() !== null && $pool->getWipLimit() > 0 && count($active) > $pool->getWipLimit()) {
				$found["wip_exceeded:$pid:$month"] = $this->conflict('wip_exceeded', $pid, $month, $active, sprintf('%d / %d', count($active), $pool->getWipLimit()));
			}
		}

		// Zombie-Projekt: in Umsetzung, aber über zwei Perioden kein Ist-Aufwand
		$prev = [date('Y-m', (int)strtotime($month . '-01 -1 month')), date('Y-m', (int)strtotime($month . '-01 -2 months'))];
		$effort = [];
		foreach ($this->rows->where('nx_actuals', ['kind' => 'effort']) as $a) {
			$effort[$a['demandId']][$a['period']] = ($effort[$a['demandId']][$a['period']] ?? 0) + (float)$a['amount'];
		}
		foreach ($demands as $d) {
			if ($d->getStatus() !== 'in_execution' || ($d->getReleasedAt() ?? $now) > $now - 62 * 86400) {
				continue;
			}
			if (($effort[$d->getId()][$prev[0]] ?? 0) + ($effort[$d->getId()][$prev[1]] ?? 0) < 0.5) {
				$found['zombie_project:' . $d->getId()] = $this->conflict('zombie_project', null, $prev[1], [$d->getId()], implode(', ', array_reverse($prev)));
			}
		}

		// Prioritätsinversion: ein wartendes Vorhaben mit höherer Wertdichte bekommt im Engpass-Pool
		// keinen Platz, während ein Vorhaben mit niedrigerer Wertdichte dort hart zugesagt ist
		$all = $this->scoring->assessments();
		$density = [];
		foreach ($demands as $d) {
			$current = AssessmentService::current($this->assessments->valid($d, $all));
			$density[$d->getId()] = $current['density'] ?? null;
		}
		foreach ($demands as $waiting) {
			if ($waiting->getStatus() !== 'prioritized' || $density[$waiting->getId()] === null) {
				continue;
			}
			foreach ($needsBy[$waiting->getId()] ?? [] as $need) {
				$pid = $need->getPoolId();
				$pool = $pools[$pid] ?? null;
				if ($pool === null || $pool->getIsBottleneck() !== 1) {
					continue;
				}
				$free = ($capacity[$pid][$need->getPeriod()] ?? 0) - ($hard[$pid][$need->getPeriod()] ?? 0);
				if ($need->getPersonDays() <= $free) {
					continue;
				}
				foreach (array_keys($hardBy[$pid][$need->getPeriod()] ?? []) as $holderId) {
					$holder = $demands[$holderId] ?? null;
					if ($holder !== null && $density[$holderId] !== null && $density[$holderId] < $density[$waiting->getId()]
						&& ($holder->getBucket() ?? '') === ($waiting->getBucket() ?? '')) {
						$found["priority_inversion:$pid:{$waiting->getId()}"] = $this->conflict('priority_inversion', $pid, $need->getPeriod(), [$waiting->getId(), $holderId],
							sprintf('%.2f > %.2f', $density[$waiting->getId()], $density[$holderId]));
						break 2;
					}
				}
			}
		}
		return $found;
	}

	/**
	 * @param array<string, array<int, bool>> $byPeriod
	 * @param list<string> $periods
	 * @return list<int>
	 */
	private function involved(array $byPeriod, array $periods): array {
		$ids = [];
		foreach ($periods as $p) {
			$ids = array_merge($ids, array_keys($byPeriod[$p] ?? []));
		}
		return array_values(array_unique($ids));
	}

	/**
	 * @param list<int> $demandIds
	 * @return array<string, mixed>
	 */
	private function conflict(string $type, ?int $poolId, ?string $period, array $demandIds, string $detail): array {
		return ['conflictType' => $type, 'poolId' => $poolId, 'period' => $period, 'demandIds' => array_map('intval', $demandIds), 'detail' => $detail];
	}

	/**
	 * Beteiligte Vorhaben eines Konflikts (für Benachrichtigungen).
	 *
	 * @param array<string, mixed> $conflict
	 * @return list<Demand>
	 */
	public function demandsOf(array $conflict): array {
		$result = [];
		foreach ($conflict['demandIds'] ?? [] as $id) {
			try {
				$result[] = $this->demandMapper->find((int)$id);
			} catch (\Throwable) {
			}
		}
		return $result;
	}
}
