<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\AllocationMapper;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\Pool;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Szenarioanalyse (POR-003, Kapitel 04 §5.1): ein Szenario ist eine benannte Liste von Änderungen am
 * aktuellen Portfoliostand. Der Kapazitätsschnitt läuft auf einer Kopie der Ausgangsdaten und wird
 * gegen den Ist-Schnitt verglichen – welche Vorhaben steigen über oder fallen unter die Linie, welche
 * Starts verschieben sich, wie ändert sich die Bucket-Balance.
 *
 * Szenarien verändern nie Allokationen oder Ränge. Entwürfe rechnen live auf dem aktuellen Stand; beim
 * Vorlegen wird das Ergebnis eingefroren. Ein angenommenes Szenario erzeugt Maßnahmen bzw.
 * Umsetzungsaufgaben, die die Änderungen regulär umsetzen.
 */
class ScenarioService {
	public const TABLE = 'nx_scenarios';
	public const CHANGES = 'nx_scenario_changes';
	public const TYPES = ['add_project', 'remove_project', 'pause', 'shift_start', 'change_rank', 'change_capacity', 'change_budget', 'change_quota'];
	/** Änderungen, die sich auf ein Vorhaben beziehen (werden bei Annahme zu Maßnahmen) */
	public const DEMAND_TYPES = ['add_project', 'remove_project', 'pause', 'shift_start', 'change_rank'];
	/** Anträge, die ein Szenario neu aufnehmen kann (noch nicht in der Rangliste) */
	public const ADDABLE = ['submitted', 'query', 'further_analysis', 'deferred'];
	private const EDIT_ROLES = ['portfolio_manager', 'board_member', 'pool_manager'];
	private const ADOPT_ROLES = ['portfolio_manager', 'board_member'];
	private const PLACED = ['startable', 'shifted'];

	public function __construct(
		private Rows $rows,
		private IDBConnection $db,
		private CapacityCutService $cut,
		private CapacityService $capacity,
		private RankingService $ranking,
		private DemandService $demands,
		private DemandMapper $demandMapper,
		private AllocationMapper $allocations,
		private PermissionService $permissions,
		private ConditionService $conditions,
		private AuditService $audit,
		private IUserManager $userManager,
		private IL10N $l10n,
		private ITimeFactory $time,
	) {
	}

	// ---- Rechte -------------------------------------------------------------------------------

	public function canEdit(string $userId): bool {
		return $this->hasAnyRole($userId, self::EDIT_ROLES);
	}

	public function canAdopt(string $userId): bool {
		return $this->hasAnyRole($userId, self::ADOPT_ROLES);
	}

	/** @param list<string> $roles Rolle in beliebigem Geltungsbereich */
	private function hasAnyRole(string $userId, array $roles): bool {
		if ($this->permissions->isAdmin($userId)) {
			return true;
		}
		foreach ($this->permissions->assignmentsOf($userId) as $a) {
			if (in_array($a->getRole(), $roles, true)) {
				return true;
			}
		}
		return false;
	}

	/** @throws ForbiddenException */
	private function assertSee(string $userId): void {
		if (!$this->capacity->canSee($userId)) {
			throw new ForbiddenException('Only governance roles may see scenarios');
		}
	}

	/** @throws ForbiddenException */
	private function assertEdit(string $userId): void {
		if (!$this->canEdit($userId)) {
			throw new ForbiddenException('Only the portfolio office, the board or pool managers may edit scenarios');
		}
	}

	// ---- Lesen --------------------------------------------------------------------------------

	/**
	 * @return array{scenarios: list<array<string, mixed>>, canEdit: bool, canAdopt: bool}
	 * @throws ForbiddenException
	 */
	public function list(string $userId): array {
		$this->assertSee($userId);
		$counts = [];
		foreach ($this->rows->where(self::CHANGES) as $c) {
			$counts[$c['scenarioId']] = ($counts[$c['scenarioId']] ?? 0) + 1;
		}
		$list = [];
		foreach (array_reverse($this->rows->where(self::TABLE)) as $s) {
			$list[] = $this->head($s) + [
				'changeCount' => $counts[$s['id']] ?? 0,
				'summary' => $s['snapshot']['summary'] ?? null,
			];
		}
		return ['scenarios' => $list, 'canEdit' => $this->canEdit($userId), 'canAdopt' => $this->canAdopt($userId)];
	}

	/**
	 * Szenario mit Änderungen und Vergleich (Entwurf: live gerechnet, sonst eingefroren).
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|NotFoundException
	 */
	public function get(string $userId, int $id): array {
		$this->assertSee($userId);
		$s = $this->find($id);
		$changes = $this->rows->where(self::CHANGES, ['scenarioId' => $id]);
		$draft = $s['status'] === 'draft';
		$editable = $draft && $this->canEdit($userId);
		$options = null;
		if ($draft) {
			$in = $this->cut->input($userId);
			$comparison = $this->evaluate($userId, $in, $changes);
			$options = $editable ? $this->options($userId, $in) : null;
		} else {
			$comparison = $s['snapshot'];
		}
		return $this->head($s) + [
			'description' => $s['description'],
			'decisionNote' => $s['decisionNote'],
			'decidedBy' => $s['decidedBy'],
			'decidedByName' => $s['decidedBy'] === null ? null : ($this->userManager->getDisplayName((string)$s['decidedBy']) ?? $s['decidedBy']),
			'decidedAt' => $s['decidedAt'],
			'changes' => array_map(static fn (array $c) => [
				'id' => $c['id'],
				'changeType' => $c['changeType'],
				'targetRef' => $c['targetRef'],
				'parameters' => $c['parameters'] ?? [],
			], $changes),
			'comparison' => $comparison,
			'options' => $options,
			'can' => [
				'edit' => $editable,
				'present' => $draft && $this->canEdit($userId),
				'reopen' => $s['status'] === 'presented' && $this->canEdit($userId),
				'discard' => in_array($s['status'], ['draft', 'presented'], true) && $this->canEdit($userId),
				'adopt' => $s['status'] === 'presented' && $this->canAdopt($userId),
				'delete' => $draft && $this->canEdit($userId),
				'tasks' => $s['status'] === 'adopted' && $this->canEdit($userId),
			],
		];
	}

	/**
	 * @param array<string, mixed> $s
	 * @return array<string, mixed>
	 */
	private function head(array $s): array {
		return [
			'id' => $s['id'],
			'name' => $s['name'],
			'status' => $s['status'],
			'createdBy' => $s['createdBy'],
			'createdByName' => $this->userManager->getDisplayName((string)$s['createdBy']) ?? $s['createdBy'],
			'createdAt' => $s['createdAt'],
			'updatedAt' => $s['updatedAt'],
			'baseSnapshotAt' => $s['baseSnapshotAt'],
		];
	}

	/**
	 * Auswahllisten für den Änderungs-Editor.
	 *
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	private function options(string $userId, array $in): array {
		$ranked = [];
		$rankedIds = [];
		foreach ($in['buckets'] as $bucket) {
			foreach ($bucket['items'] as $item) {
				$rankedIds[$item['id']] = true;
				$ranked[] = ['id' => $item['id'], 'key' => $item['key'], 'title' => $item['title'], 'bucket' => $bucket['code'], 'rank' => $item['rank']];
			}
		}
		$addable = [];
		$pausable = [];
		foreach ($this->demands->findAll($userId) as $d) {
			if (isset($rankedIds[$d->getId()])) {
				continue;
			}
			if (in_array($d->getStatus(), self::ADDABLE, true)) {
				$addable[] = ['id' => $d->getId(), 'key' => $d->getDemandKey(), 'title' => $d->getTitle(), 'status' => $d->getStatus(), 'bucket' => RankingService::bucketOf($d)];
			} elseif ($this->hardRows($d->getId(), $in['from']) !== []) {
				$pausable[] = ['id' => $d->getId(), 'key' => $d->getProjectKey() ?? $d->getDemandKey(), 'title' => $d->getTitle(), 'status' => $d->getStatus()];
			}
		}
		return [
			'ranked' => $ranked,
			'addable' => $addable,
			'pausable' => $pausable,
			'pools' => array_values(array_map(static fn (Pool $p) => ['id' => $p->getId(), 'code' => $p->getCode(), 'name' => $p->getName()], $in['pools'])),
			'buckets' => array_map(static fn (array $b) => [
				'code' => $b['code'], 'name' => $b['name'], 'scored' => $b['scored'],
				'targetSharePct' => $b['targetSharePct'], 'targetBudget' => $b['targetBudget'],
			], $in['buckets']),
			'periods' => $in['periods'],
		];
	}

	// ---- Rechnen ------------------------------------------------------------------------------

	/**
	 * Ist-Schnitt und Szenario-Schnitt rechnen und vergleichen.
	 *
	 * @param array<string, mixed> $in Ausgangsdaten (CapacityCutService::input)
	 * @param list<array<string, mixed>> $changes
	 * @return array<string, mixed>
	 */
	private function evaluate(string $userId, array $in, array $changes): array {
		$extra = ['added' => [], 'hard' => []];
		foreach ($changes as $c) {
			$demandId = (int)$c['targetRef'];
			if ($c['changeType'] === 'add_project') {
				try {
					$demand = $this->demands->findReadable($userId, $demandId);
					$extra['added'][$demandId] = $this->ranking->itemFor($userId, $demand) + ['bucket' => RankingService::bucketOf($demand)];
				} catch (NotFoundException) {
					// Antrag nicht mehr lesbar: Änderung bleibt wirkungslos
				}
			} elseif ($c['changeType'] === 'pause') {
				$extra['hard'][$demandId] = $this->hardRows($demandId, $in['from']);
			}
		}
		$scenarioIn = self::apply($in, $changes, $extra);
		$base = $this->cut->compute($userId, $in);
		$scenario = $this->cut->compute($userId, $scenarioIn);
		$costs = [];
		foreach ([$in, $scenarioIn] as $input) {
			foreach ($input['buckets'] as $bucket) {
				foreach ($bucket['items'] as $item) {
					$costs[$item['id']] ??= $this->cut->oneTimeCost($userId, $item['id']);
				}
			}
		}
		return self::compare($in, $base, $scenarioIn, $scenario, $costs) + ['computedAt' => $this->time->getTime()];
	}

	/** @return list<array<string, mixed>> harte Zusagen eines Vorhabens ab dem Monat */
	private function hardRows(int $demandId, string $from): array {
		return array_values(array_filter(
			$this->allocations->forDemand($demandId),
			static fn (array $a) => $a['level'] === 'hard' && $a['period'] >= $from,
		));
	}

	/**
	 * Änderungen auf eine Kopie der Ausgangsdaten anwenden.
	 *
	 * @param array<string, mixed> $in
	 * @param list<array<string, mixed>> $changes {changeType, targetRef, parameters}
	 * @param array{added: array<int, array<string, mixed>>, hard: array<int, list<array<string, mixed>>>} $extra
	 *        Ranglisten-Einträge neu aufgenommener Anträge, harte Zusagen pausierter Vorhaben
	 * @return array<string, mixed>
	 */
	public static function apply(array $in, array $changes, array $extra): array {
		$out = $in;
		foreach ($changes as $c) {
			$type = (string)$c['changeType'];
			$ref = (string)$c['targetRef'];
			$p = is_array($c['parameters'] ?? null) ? $c['parameters'] : [];
			$demandId = (int)$ref;
			switch ($type) {
				case 'add_project':
					$item = $extra['added'][$demandId] ?? null;
					if ($item === null || self::locate($out, $demandId) !== null) {
						break;
					}
					$bucketCode = (string)($p['bucket'] ?? $item['bucket'] ?? 'C');
					unset($item['bucket']);
					if ($item['assessment'] === null && isset($p['density'])) {
						$item['assessment'] = ['kind' => 'assumed', 'density' => (float)$p['density'], 'benefit' => null, 'urgency' => null, 'effort' => null, 'risk' => null];
					}
					$item['rankOverride'] = null;
					foreach ($out['buckets'] as $i => $bucket) {
						if ($bucket['code'] === $bucketCode) {
							$out['buckets'][$i]['items'][] = $item;
						}
					}
					break;
				case 'remove_project':
					foreach ($out['buckets'] as $i => $bucket) {
						$out['buckets'][$i]['items'] = array_values(array_filter($bucket['items'], static fn (array $it) => $it['id'] !== $demandId));
					}
					break;
				case 'change_rank':
					$at = self::locate($out, $demandId);
					if ($at !== null) {
						$out['buckets'][$at[0]]['items'][$at[1]]['rankOverride'] = max(1, (int)($p['rank'] ?? 1));
					}
					break;
				case 'shift_start':
					$months = (int)($p['months'] ?? 0);
					$out['needs'][$demandId] = array_map(static fn (array $n) => ['period' => CapacityCutService::shift($n['period'], $months)] + $n, $out['needs'][$demandId] ?? []);
					break;
				case 'pause':
					$from = (string)($p['from'] ?? $in['from']);
					foreach ($extra['hard'][$demandId] ?? [] as $a) {
						if ($a['period'] >= $from && isset($out['hard'][$a['poolId']][$a['period']])) {
							$out['hard'][$a['poolId']][$a['period']] = max(0.0, $out['hard'][$a['poolId']][$a['period']] - $a['personDays']);
						}
					}
					break;
				case 'change_capacity':
					$pool = $out['pools'][$demandId] ?? null;
					if ($pool === null) {
						break;
					}
					$from = (string)($p['from'] ?? $in['from']);
					$fte = (float)($p['fte'] ?? 0);
					foreach ($out['periods'] as $period) {
						if ($period >= $from) {
							$delta = CapacityService::calculate($pool, abs($fte), CapacityService::weekdays($period))['project'];
							$out['capacity'][$demandId][$period] = max(0.0, ($out['capacity'][$demandId][$period] ?? 0.0) + ($fte < 0 ? -$delta : $delta));
						}
					}
					break;
				case 'change_budget':
				case 'change_quota':
					foreach ($out['buckets'] as $i => $bucket) {
						if ($bucket['code'] === $ref) {
							if ($type === 'change_budget') {
								$out['buckets'][$i]['targetBudget'] = isset($p['budget']) ? (float)$p['budget'] : null;
							} else {
								$out['buckets'][$i]['targetSharePct'] = (float)($p['sharePct'] ?? $bucket['targetSharePct']);
							}
						}
					}
					break;
			}
		}
		foreach ($out['buckets'] as $i => $bucket) {
			$out['buckets'][$i]['items'] = RankingService::rank($bucket['items'], $bucket['scored']);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $in
	 * @return array{0: int, 1: int}|null Bucket- und Eintragsindex
	 */
	private static function locate(array $in, int $demandId): ?array {
		foreach ($in['buckets'] as $i => $bucket) {
			foreach ($bucket['items'] as $j => $item) {
				if ($item['id'] === $demandId) {
					return [$i, $j];
				}
			}
		}
		return null;
	}

	/**
	 * Vergleich Ist ↔ Szenario.
	 *
	 * @param array<string, mixed> $inBase
	 * @param array<string, mixed> $base Ergebnis des Ist-Schnitts
	 * @param array<string, mixed> $inScenario
	 * @param array<string, mixed> $scenario Ergebnis des Szenario-Schnitts
	 * @param array<int, float> $costs einmalige Kosten je Antrag
	 * @return array<string, mixed>
	 */
	public static function compare(array $inBase, array $base, array $inScenario, array $scenario, array $costs): array {
		$rows = [];
		$order = [];
		foreach ([['base', $inBase], ['scenario', $inScenario]] as [$side, $in]) {
			foreach ($in['buckets'] as $bucket) {
				foreach ($bucket['items'] as $item) {
					$id = $item['id'];
					if (!isset($rows[$id])) {
						$order[] = $id;
						$rows[$id] = ['id' => $id, 'key' => $item['key'] ?? null, 'title' => $item['title'] ?? null, 'status' => $item['status'] ?? null,
							'personDays' => $item['personDays'] ?? 0.0, 'cost' => $costs[$id] ?? 0.0,
							'bucketBase' => null, 'bucketScenario' => null, 'rankBase' => null, 'rankScenario' => null, 'base' => null, 'scenario' => null];
					}
					$rows[$id]['bucket' . ucfirst($side)] = $bucket['code'];
					$rows[$id]['rank' . ucfirst($side)] = $item['rank'] ?? null;
					$rows[$id][$side] = ($side === 'base' ? $base : $scenario)['results'][$id] ?? null;
				}
			}
		}
		$summary = ['rises' => 0, 'falls' => 0, 'earlier' => 0, 'later' => 0, 'added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0];
		foreach ($rows as $id => $row) {
			$effect = self::effect($row['base'], $row['scenario'], $inBase['from']);
			if ($row['rankBase'] !== null && $row['rankScenario'] !== null && $row['rankBase'] !== $row['rankScenario'] && $effect === 'unchanged') {
				$effect = 'changed';
			}
			$rows[$id]['effect'] = $effect;
			$summary[$effect]++;
		}

		$buckets = [];
		foreach ($inScenario['buckets'] as $i => $bucket) {
			$b = $inBase['buckets'][$i] ?? $bucket;
			$entry = ['code' => $bucket['code'], 'name' => $bucket['name'] ?? $bucket['code'], 'scored' => $bucket['scored'],
				'shareBase' => $b['targetSharePct'], 'shareScenario' => $bucket['targetSharePct'],
				'budgetBase' => $b['targetBudget'], 'budgetScenario' => $bucket['targetBudget']];
			foreach (['base', 'scenario'] as $side) {
				$placed = array_filter($rows, static fn (array $r) => $r['bucket' . ucfirst($side)] === $bucket['code'] && in_array($r[$side]['state'] ?? null, self::PLACED, true));
				$entry[$side] = [
					'placed' => count($placed),
					'total' => count(array_filter($rows, static fn (array $r) => $r['bucket' . ucfirst($side)] === $bucket['code'])),
					'personDays' => round(array_sum(array_column($placed, 'personDays')), 1),
					'cost' => round(array_sum(array_column($placed, 'cost')), 2),
				];
			}
			$buckets[] = $entry;
		}

		$pools = [];
		foreach ($inScenario['pools'] as $poolId => $pool) {
			$pools[] = [
				'id' => $poolId,
				'code' => $pool->getCode(),
				'name' => $pool->getName(),
				'availableBase' => self::available($inBase, $poolId),
				'availableScenario' => self::available($inScenario, $poolId),
				'remainingBase' => round(array_sum($base['remaining'][$poolId] ?? []), 1),
				'remainingScenario' => round(array_sum($scenario['remaining'][$poolId] ?? []), 1),
			];
		}

		$items = array_map(static fn (int $id) => $rows[$id], $order);
		return ['horizon' => $inBase['periods'], 'items' => $items, 'buckets' => $buckets, 'pools' => $pools, 'summary' => $summary];
	}

	/**
	 * Freie Projekt-Kapazität eines Pools im Horizont (nach harten Zusagen)
	 *
	 * @param array<string, mixed> $in
	 */
	private static function available(array $in, int $poolId): float {
		$sum = 0.0;
		foreach ($in['periods'] as $period) {
			$sum += max(0.0, ($in['capacity'][$poolId][$period] ?? 0.0) - ($in['hard'][$poolId][$period] ?? 0.0));
		}
		return round($sum, 1);
	}

	/**
	 * @param array<string, mixed>|null $base
	 * @param array<string, mixed>|null $scenario
	 */
	private static function effect(?array $base, ?array $scenario, string $from): string {
		if ($base === null) {
			return 'added';
		}
		if ($scenario === null) {
			return 'removed';
		}
		$wasIn = in_array($base['state'], self::PLACED, true);
		$isIn = in_array($scenario['state'], self::PLACED, true);
		if ($wasIn !== $isIn) {
			return $isIn ? 'rises' : 'falls';
		}
		if ($isIn) {
			$a = $base['start'] ?? $from;
			$b = $scenario['start'] ?? $from;
			return $a === $b ? 'unchanged' : ($b > $a ? 'later' : 'earlier');
		}
		return ($base['state'] === $scenario['state'] && ($base['pool'] ?? null) === ($scenario['pool'] ?? null)) ? 'unchanged' : 'changed';
	}

	// ---- Schreiben ----------------------------------------------------------------------------

	/**
	 * Neues Szenario, optional als Kopie eines anderen (Änderungen werden übernommen).
	 *
	 * @param array<string, mixed> $data {name, description, copyOf}
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function create(string $userId, array $data): array {
		$this->assertEdit($userId);
		$name = $this->name($data);
		$copyOf = ($data['copyOf'] ?? '') !== '' ? $this->find((int)$data['copyOf']) : null;
		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			$id = $this->rows->insert(self::TABLE, [
				'name' => $name, 'description' => $this->text($data['description'] ?? null), 'status' => 'draft',
				'createdBy' => $userId, 'createdAt' => $now, 'updatedAt' => $now,
			]);
			if ($copyOf !== null) {
				foreach ($this->rows->where(self::CHANGES, ['scenarioId' => $copyOf['id']]) as $c) {
					$this->rows->insert(self::CHANGES, [
						'scenarioId' => $id, 'changeType' => $c['changeType'], 'targetRef' => $c['targetRef'], 'parameters' => $c['parameters'],
						'createdBy' => $userId, 'createdAt' => $now,
					]);
				}
			}
			$this->audit->log('scenario', $id, 'create', $userId, ['name' => [null, $name]], $copyOf === null ? null : 'copy of #' . $copyOf['id']);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->get($userId, $id);
	}

	/**
	 * @param array<string, mixed> $data {name, description}
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function update(string $userId, int $id, array $data): array {
		$s = $this->draft($userId, $id);
		$name = $this->name($data);
		$description = $this->text($data['description'] ?? null);
		$this->rows->update(self::TABLE, $id, ['name' => $name, 'description' => $description, 'updatedAt' => $this->time->getTime()]);
		$this->audit->log('scenario', $id, 'update', $userId, array_filter([
			'name' => [$s['name'], $name],
			'description' => [$s['description'], $description],
		], static fn (array $d) => $d[0] !== $d[1]));
		return $this->get($userId, $id);
	}

	/** @throws ForbiddenException|ValidationException|NotFoundException */
	public function delete(string $userId, int $id): void {
		$s = $this->draft($userId, $id);
		$this->rows->deleteWhere(self::CHANGES, ['scenarioId' => $id]);
		$this->rows->delete(self::TABLE, $id);
		$this->audit->log('scenario', $id, 'delete', $userId, ['name' => [$s['name'], null]]);
	}

	/**
	 * Änderung hinzufügen; eine vorhandene Änderung gleicher Art am selben Ziel wird ersetzt.
	 *
	 * @param array<string, mixed> $data {changeType, targetRef, parameters}
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function addChange(string $userId, int $id, array $data): array {
		$this->draft($userId, $id);
		[$type, $ref, $params] = $this->validateChange($userId, $data);
		$now = $this->time->getTime();
		foreach ($this->rows->where(self::CHANGES, ['scenarioId' => $id, 'changeType' => $type, 'targetRef' => $ref]) as $old) {
			$this->rows->delete(self::CHANGES, $old['id']);
		}
		$changeId = $this->rows->insert(self::CHANGES, [
			'scenarioId' => $id, 'changeType' => $type, 'targetRef' => $ref, 'parameters' => $params,
			'createdBy' => $userId, 'createdAt' => $now,
		]);
		$this->rows->update(self::TABLE, $id, ['updatedAt' => $now]);
		$this->audit->log('scenario', $id, 'change_add', $userId, ['change' => [null, ['id' => $changeId, 'type' => $type, 'target' => $ref] + $params]]);
		return $this->get($userId, $id);
	}

	/**
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function removeChange(string $userId, int $id, int $changeId): array {
		$this->draft($userId, $id);
		$change = $this->rows->find(self::CHANGES, $changeId);
		if ($change === null || $change['scenarioId'] !== $id) {
			throw new NotFoundException('Change not found');
		}
		$this->rows->delete(self::CHANGES, $changeId);
		$this->rows->update(self::TABLE, $id, ['updatedAt' => $this->time->getTime()]);
		$this->audit->log('scenario', $id, 'change_remove', $userId, ['change' => [['id' => $changeId, 'type' => $change['changeType'], 'target' => $change['targetRef']], null]]);
		return $this->get($userId, $id);
	}

	/**
	 * Vorlegen: Ergebnis einfrieren (Grundlage der Entscheidung).
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function present(string $userId, int $id): array {
		$this->draft($userId, $id);
		$changes = $this->rows->where(self::CHANGES, ['scenarioId' => $id]);
		if ($changes === []) {
			throw new ValidationException('The scenario has no changes yet');
		}
		$snapshot = $this->evaluate($userId, $this->cut->input($userId), $changes);
		$now = $this->time->getTime();
		$this->rows->update(self::TABLE, $id, ['status' => 'presented', 'snapshot' => $snapshot, 'baseSnapshotAt' => $now, 'updatedAt' => $now]);
		$this->audit->log('scenario', $id, 'status_change', $userId, ['status' => ['draft', 'presented']]);
		return $this->get($userId, $id);
	}

	/**
	 * Zurück in den Entwurf (eingefrorenes Ergebnis verwerfen).
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function reopen(string $userId, int $id): array {
		$this->assertEdit($userId);
		$s = $this->find($id);
		if ($s['status'] !== 'presented') {
			throw new ValidationException('Only a presented scenario can be reopened');
		}
		$this->rows->update(self::TABLE, $id, ['status' => 'draft', 'snapshot' => null, 'baseSnapshotAt' => null, 'updatedAt' => $this->time->getTime()]);
		$this->audit->log('scenario', $id, 'status_change', $userId, ['status' => ['presented', 'draft']]);
		return $this->get($userId, $id);
	}

	/**
	 * Verwerfen (bleibt als Dokumentation erhalten).
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function discard(string $userId, int $id, string $reason): array {
		$this->assertEdit($userId);
		$s = $this->find($id);
		if (!in_array($s['status'], ['draft', 'presented'], true)) {
			throw new ValidationException('The scenario is already decided');
		}
		$reason = trim($reason);
		$now = $this->time->getTime();
		$values = ['status' => 'discarded', 'decisionNote' => $reason === '' ? null : $reason, 'decidedBy' => $userId, 'decidedAt' => $now, 'updatedAt' => $now];
		if ($s['status'] === 'draft') {
			// das Ergebnis zum Zeitpunkt des Verwerfens festhalten
			$values['snapshot'] = $this->evaluate($userId, $this->cut->input($userId), $this->rows->where(self::CHANGES, ['scenarioId' => $id]));
			$values['baseSnapshotAt'] = $now;
		}
		$this->rows->update(self::TABLE, $id, $values);
		$this->audit->log('scenario', $id, 'status_change', $userId, ['status' => [$s['status'], 'discarded']], $reason === '' ? null : $reason);
		return $this->get($userId, $id);
	}

	/**
	 * Annehmen (Board, PMO): erzeugt je Änderung an einem Vorhaben eine Maßnahme und für Kapazitäts-
	 * und Bucket-Änderungen Umsetzungsaufgaben – nichts wird stillschweigend geändert.
	 *
	 * @param array<string, mixed> $data {reason, ownerId, dueDate}
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function adopt(string $userId, int $id, array $data): array {
		if (!$this->canAdopt($userId)) {
			throw new ForbiddenException('Only the board or the portfolio office may adopt a scenario');
		}
		$s = $this->find($id);
		if ($s['status'] !== 'presented') {
			throw new ValidationException('Only a presented scenario can be adopted');
		}
		$reason = trim((string)($data['reason'] ?? ''));
		if ($reason === '') {
			throw new ValidationException('Adopting a scenario needs a reason');
		}
		$owner = trim((string)($data['ownerId'] ?? ''));
		$due = (string)($data['dueDate'] ?? '');
		if (!$this->userManager->userExists($owner)) {
			throw new ValidationException('Every condition needs an owner');
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
			throw new ValidationException('Every condition needs a due date');
		}
		$changes = $this->rows->where(self::CHANGES, ['scenarioId' => $id]);
		$now = $this->time->getTime();
		$snapshot = $s['snapshot'] ?? [];
		$tasks = [];
		$this->db->beginTransaction();
		try {
			foreach ($changes as $c) {
				$title = $this->taskTitle($s['name'], $c);
				if (in_array($c['changeType'], self::DEMAND_TYPES, true)) {
					try {
						$demand = $this->demandMapper->find((int)$c['targetRef']);
					} catch (\OCP\AppFramework\Db\DoesNotExistException) {
						continue;
					}
					$this->conditions->createFor($userId, $demand, null, null, 'action',
						[['title' => mb_substr($title, 0, 255), 'ownerId' => $owner, 'dueDate' => $due, 'blocking' => false]], 'scenario', $id);
				} else {
					$tasks[] = ['changeId' => $c['id'], 'title' => $title, 'ownerId' => $owner, 'dueDate' => $due, 'done' => false, 'doneBy' => null, 'doneAt' => null];
				}
			}
			$snapshot['tasks'] = $tasks;
			$this->rows->update(self::TABLE, $id, [
				'status' => 'adopted', 'snapshot' => $snapshot, 'decisionNote' => $reason, 'decidedBy' => $userId, 'decidedAt' => $now, 'updatedAt' => $now,
			]);
			$this->audit->log('scenario', $id, 'status_change', $userId, ['status' => ['presented', 'adopted']], $reason);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->get($userId, $id);
	}

	/**
	 * Umsetzungsaufgabe eines angenommenen Szenarios erledigt melden (oder wieder öffnen).
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function setTask(string $userId, int $id, int $index, bool $done): array {
		$s = $this->find($id);
		$tasks = $s['snapshot']['tasks'] ?? [];
		if ($s['status'] !== 'adopted' || !isset($tasks[$index])) {
			throw new NotFoundException('Task not found');
		}
		if (!$this->canEdit($userId) && $tasks[$index]['ownerId'] !== $userId) {
			throw new ForbiddenException('Only the owner or the portfolio office may close the task');
		}
		$now = $this->time->getTime();
		$tasks[$index] = ['done' => $done, 'doneBy' => $done ? $userId : null, 'doneAt' => $done ? $now : null] + $tasks[$index];
		$snapshot = $s['snapshot'];
		$snapshot['tasks'] = $tasks;
		$this->rows->update(self::TABLE, $id, ['snapshot' => $snapshot, 'updatedAt' => $now]);
		$this->audit->log('scenario', $id, 'task', $userId, ['done' => [!$done, $done]], $tasks[$index]['title']);
		return $this->get($userId, $id);
	}

	/** @param array<string, mixed> $c */
	private function taskTitle(string $scenario, array $c): string {
		$p = $c['parameters'] ?? [];
		$label = (string)($p['label'] ?? $c['targetRef']);
		$text = match ($c['changeType']) {
			'add_project' => $this->l10n->t('Include %1$s in the ranking (bucket %2$s)', [$label, (string)($p['bucket'] ?? '–')]),
			'remove_project' => $this->l10n->t('Take %s out of the capacity cut', [$label]),
			'pause' => $this->l10n->t('Pause %1$s from %2$s and release its commitments', [$label, (string)($p['from'] ?? '')]),
			'shift_start' => $this->l10n->n('Shift the start of %2$s by %1$d month', 'Shift the start of %2$s by %1$d months', (int)($p['months'] ?? 0), [(int)($p['months'] ?? 0), $label]),
			'change_rank' => $this->l10n->t('Set the rank of %1$s to %2$d (board correction)', [$label, (int)($p['rank'] ?? 0)]),
			'change_capacity' => $this->l10n->t('Change capacity of pool %1$s by %2$s FTE from %3$s', [$label, self::signed((float)($p['fte'] ?? 0)), (string)($p['from'] ?? '')]),
			'change_budget' => isset($p['budget'])
				? $this->l10n->t('Set the budget frame of bucket %1$s to %2$s', [$label, number_format((float)$p['budget'], 0, ',', '.')])
				: $this->l10n->t('Remove the budget frame of bucket %s', [$label]),
			'change_quota' => $this->l10n->t('Set the target share of bucket %1$s to %2$s %%', [$label, (string)(float)($p['sharePct'] ?? 0)]),
			default => $c['changeType'],
		};
		return $this->l10n->t('Scenario “%1$s”: %2$s', [$scenario, $text]);
	}

	private static function signed(float $v): string {
		return ($v > 0 ? '+' : '') . rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
	}

	// ---- Prüfen -------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $data
	 * @return array{0: string, 1: string, 2: array<string, mixed>}
	 * @throws ValidationException|NotFoundException|ForbiddenException
	 */
	private function validateChange(string $userId, array $data): array {
		$type = (string)($data['changeType'] ?? '');
		if (!in_array($type, self::TYPES, true)) {
			throw new ValidationException('Unknown change type');
		}
		$ref = trim((string)($data['targetRef'] ?? ''));
		$raw = is_array($data['parameters'] ?? null) ? $data['parameters'] : [];
		$in = $this->cut->input($userId);
		$params = [];
		$from = $in['from'];
		switch ($type) {
			case 'add_project':
				$demand = $this->demands->findReadable($userId, (int)$ref);
				if (!in_array($demand->getStatus(), self::ADDABLE, true) || self::locate($in, $demand->getId()) !== null) {
					throw new ValidationException('Only demands that are not in the ranking yet can be added');
				}
				$bucket = (string)($raw['bucket'] ?? '') ?: RankingService::bucketOf($demand);
				if (!in_array($bucket, array_column($in['buckets'], 'code'), true)) {
					throw new ValidationException('Unknown bucket');
				}
				$params = ['label' => $this->demandLabel($demand), 'bucket' => $bucket];
				if (($raw['density'] ?? null) !== null && $raw['density'] !== '') {
					if (!is_numeric($raw['density']) || (float)$raw['density'] <= 0) {
						throw new ValidationException('The assumed value density must be greater than 0');
					}
					$params['density'] = round((float)$raw['density'], 2);
				}
				break;
			case 'remove_project':
			case 'change_rank':
			case 'shift_start':
				$demand = $this->demands->findReadable($userId, (int)$ref);
				if (self::locate($in, $demand->getId()) === null) {
					throw new ValidationException('The project is not part of the ranking');
				}
				$params = ['label' => $this->demandLabel($demand)];
				if ($type === 'change_rank') {
					$rank = filter_var($raw['rank'] ?? null, FILTER_VALIDATE_INT);
					if ($rank === false || $rank < 1) {
						throw new ValidationException('The rank must be at least 1');
					}
					$params['rank'] = $rank;
				} elseif ($type === 'shift_start') {
					$months = filter_var($raw['months'] ?? null, FILTER_VALIDATE_INT);
					if ($months === false || $months < 1 || $months > CapacityCutService::HORIZON) {
						throw new ValidationException('Shift by 1 to 12 months');
					}
					$params['months'] = $months;
				}
				break;
			case 'pause':
				$demand = $this->demands->findReadable($userId, (int)$ref);
				if ($this->hardRows($demand->getId(), $from) === []) {
					throw new ValidationException('The project has no firm commitments to release');
				}
				$params = ['label' => $this->demandLabel($demand), 'from' => $this->period($raw['from'] ?? $from, $from)];
				break;
			case 'change_capacity':
				$pool = $in['pools'][(int)$ref] ?? null;
				if ($pool === null) {
					throw new ValidationException('Only bottleneck pools take part in the capacity cut');
				}
				if (!is_numeric($raw['fte'] ?? null) || (float)$raw['fte'] == 0.0 || abs((float)$raw['fte']) > 1000) {
					throw new ValidationException('Enter the change in FTE (not 0)');
				}
				$params = ['label' => $pool->getCode(), 'fte' => round((float)$raw['fte'], 2), 'from' => $this->period($raw['from'] ?? $from, $from)];
				break;
			case 'change_budget':
			case 'change_quota':
				$bucket = null;
				foreach ($in['buckets'] as $b) {
					if ($b['code'] === $ref) {
						$bucket = $b;
					}
				}
				if ($bucket === null) {
					throw new ValidationException('Unknown bucket');
				}
				$params = ['label' => $bucket['code']];
				if ($type === 'change_budget') {
					$budget = $raw['budget'] ?? null;
					if ($budget !== null && $budget !== '' && (!is_numeric($budget) || (float)$budget < 0)) {
						throw new ValidationException('The budget must not be negative');
					}
					$params['budget'] = $budget === null || $budget === '' ? null : round((float)$budget, 2);
				} else {
					if (!$bucket['scored']) {
						throw new ValidationException('The mandatory bucket has no target share');
					}
					$share = $raw['sharePct'] ?? null;
					if (!is_numeric($share) || (float)$share < 0 || (float)$share > 100) {
						throw new ValidationException('The target share must be between 0 and 100 %');
					}
					$params['sharePct'] = round((float)$share, 1);
				}
				break;
		}
		return [$type, $ref, $params];
	}

	private function demandLabel(Demand $demand): string {
		return ($demand->getProjectKey() ?? $demand->getDemandKey()) . ' ' . $demand->getTitle();
	}

	/** @throws ValidationException */
	private function period(mixed $value, string $min): string {
		$value = (string)$value;
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
			throw new ValidationException('Invalid month');
		}
		return max($value, $min);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws ValidationException
	 */
	private function name(array $data): string {
		$name = trim((string)($data['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('The scenario needs a name');
		}
		return $name;
	}

	private function text(mixed $value): ?string {
		$value = trim((string)$value);
		return $value === '' ? null : $value;
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	private function find(int $id): array {
		$s = $this->rows->find(self::TABLE, $id);
		if ($s === null) {
			throw new NotFoundException('Scenario not found');
		}
		return $s;
	}

	/**
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	private function draft(string $userId, int $id): array {
		$this->assertEdit($userId);
		$s = $this->find($id);
		if ($s['status'] !== 'draft') {
			throw new ValidationException('Only draft scenarios can be changed');
		}
		return $s;
	}
}
