<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\PoolMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ForbiddenException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Rollierende Nachkalkulation (Kapitel 04 §6, Stufe 5): Ist-Aufwand abgeschlossener Projekte gegen
 * die ursprüngliche Schätzung (Baseline 1 bei G3) je Pool und Vorhabensklasse. Daraus entstehen
 * Schätzkorrekturfaktoren, die beim Erfassen neuer Bedarfe als Erfahrungswert angezeigt werden.
 *
 * Bewusst auf Pool-, nicht auf Personenebene (06 §9: kein Kontrollinstrument).
 */
class EstimationService {
	/** Projekte, deren Aufwand feststeht */
	public const FINISHED = ['completed', 'closed'];
	public const CLASSES = ['XS', 'S', 'M', 'L'];

	public function __construct(
		private Rows $rows,
		private DemandMapper $demandMapper,
		private PoolMapper $pools,
		private ProjectService $projects,
		private CapacityService $capacity,
		private SettingsService $settings,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Nachkalkulation für Portfolio-Rollen: alle abgeschlossenen Projekte mit Baseline, Aggregation
	 * je Pool × Klasse über das rollierende Zeitfenster.
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException
	 */
	public function overview(string $userId): array {
		if (!$this->capacity->canSee($userId)) {
			throw new ForbiddenException('Only portfolio roles may see the post-calculation');
		}
		$data = $this->collect();
		$projects = [];
		foreach ($data['projects'] as $p) {
			$d = $p['demand'];
			$projects[] = [
				'demandId' => $d->getId(),
				'demandKey' => $d->getDemandKey(),
				'projectKey' => $d->getProjectKey(),
				'title' => $d->getTitle(),
				'status' => $d->getStatus(),
				'projectClass' => $p['class'],
				'completedAt' => $p['completedAt'],
				'inWindow' => $p['inWindow'],
				'counted' => $p['counted'],
			] + $p['calc'];
		}
		return [
			'settings' => $data['settings'],
			'pools' => array_map(static fn ($pool) => ['id' => $pool->getId(), 'name' => $pool->getName(), 'code' => $pool->getCode()], $this->pools->findAll()),
			'projects' => $projects,
			'byPool' => $data['byPool'],
			'overall' => $data['overall'],
		];
	}

	/**
	 * Korrekturfaktoren ohne Projektbezug, für jede Person, die Bedarfe schätzt.
	 *
	 * @return array{minProjects: int, windowMonths: int, byPool: array<int, array<string, mixed>>, overall: array<string, mixed>}
	 */
	public function factors(): array {
		$data = $this->collect();
		return ['minProjects' => $data['settings']['minProjects'], 'windowMonths' => $data['settings']['windowMonths'], 'byPool' => $data['byPool'], 'overall' => $data['overall']];
	}

	/** Gesamtfaktor für die Kennzahl „Schätzgenauigkeit“; null ohne ausreichende Datenbasis. */
	public function overallFactor(): ?float {
		$overall = $this->collect()['overall'];
		return $overall['valid'] ? $overall['factor'] : null;
	}

	// ---- intern -------------------------------------------------------------------------------

	/**
	 * @return array{settings: array{windowMonths: int, minProjects: int}, projects: list<array<string, mixed>>, byPool: array<int, array<string, mixed>>, overall: array<string, mixed>}
	 */
	private function collect(): array {
		$window = max(1, (int)$this->settings->get('recalcWindowMonths'));
		$min = max(1, (int)$this->settings->get('recalcMinProjects'));
		$since = (int)strtotime(sprintf('-%d months', $window), $this->time->getTime());
		$finishedAt = $this->finishedAt();

		$projects = [];
		$byPool = [];
		$overall = self::cell();
		foreach ($this->demandMapper->findAll() as $demand) {
			if (!in_array($demand->getStatus(), self::FINISHED, true)) {
				continue;
			}
			$calc = $this->projects->postCalculation($demand);
			if (!$calc['hasBaseline']) {
				continue;
			}
			$completedAt = $finishedAt[$demand->getId()] ?? null;
			if ($completedAt !== null && $completedAt < $demand->getCreatedAt()) {
				$completedAt = null;
			}
			$completedAt ??= $demand->getUpdatedAt();
			$inWindow = $completedAt >= $since;
			$counted = $inWindow && $calc['hasActuals'] && $calc['estimate'] > 0;
			$class = $demand->getProjectClass() ?? '';
			if ($counted) {
				foreach ($calc['pools'] as $row) {
					$pool = $row['poolId'];
					$byPool[$pool] ??= ['all' => self::cell(), 'classes' => []];
					self::add($byPool[$pool]['all'], $row['estimate'], $row['actual']);
					if ($class !== '') {
						$byPool[$pool]['classes'][$class] ??= self::cell();
						self::add($byPool[$pool]['classes'][$class], $row['estimate'], $row['actual']);
					}
				}
				self::add($overall, $calc['estimate'], $calc['actual']);
			}
			$projects[] = ['demand' => $demand, 'class' => $class === '' ? null : $class, 'completedAt' => $completedAt, 'inWindow' => $inWindow, 'counted' => $counted, 'calc' => $calc];
		}
		usort($projects, static fn (array $a, array $b) => $b['completedAt'] <=> $a['completedAt']);
		foreach ($byPool as $pool => $entry) {
			$byPool[$pool]['all'] = self::finish($entry['all'], $min);
			foreach ($entry['classes'] as $class => $cell) {
				$byPool[$pool]['classes'][$class] = self::finish($cell, $min);
			}
		}
		ksort($byPool);
		return [
			'settings' => ['windowMonths' => $window, 'minProjects' => $min],
			'projects' => $projects,
			'byPool' => $byPool,
			'overall' => self::finish($overall, $min),
		];
	}

	/** @return array<int, int> Zeitpunkt der G5-Freigabe (Abschluss) je Antrag */
	private function finishedAt(): array {
		$out = [];
		foreach ($this->rows->where('nx_decisions', ['decisionType' => 'g5']) as $dec) {
			if (in_array($dec['outcome'], ['approved', 'approved_with_conditions'], true)) {
				$out[(int)$dec['subjectId']] = max($out[(int)$dec['subjectId']] ?? 0, (int)$dec['decidedAt']);
			}
		}
		return $out;
	}

	/** @return array{estimate: float, actual: float, count: int} */
	private static function cell(): array {
		return ['estimate' => 0.0, 'actual' => 0.0, 'count' => 0];
	}

	/** @param array{estimate: float, actual: float, count: int} $cell */
	private static function add(array &$cell, float $estimate, float $actual): void {
		$cell['estimate'] += $estimate;
		$cell['actual'] += $actual;
		$cell['count']++;
	}

	/**
	 * Gewichteter Faktor Σ Ist ÷ Σ Schätzung; gültig erst ab der Mindestzahl Projekte.
	 *
	 * @param array{estimate: float, actual: float, count: int} $cell
	 * @return array{estimate: float, actual: float, count: int, factor: float|null, valid: bool}
	 */
	private static function finish(array $cell, int $min): array {
		$factor = $cell['estimate'] > 0 ? round($cell['actual'] / $cell['estimate'], 2) : null;
		return [
			'estimate' => round($cell['estimate'], 1),
			'actual' => round($cell['actual'], 1),
			'count' => $cell['count'],
			'factor' => $factor,
			'valid' => $factor !== null && $cell['count'] >= $min,
		];
	}
}
