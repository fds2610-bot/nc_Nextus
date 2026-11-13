<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

/**
 * Ressourcenbedarf aus dem Planungstool (Kapitel 07 §4.3): Aufwand je Rolle und Monat.
 *
 * Provider hängen an jeden Vorgang `demand` = Liste aus Rolle und Personentagen. Personen werden
 * schon im Provider auf Rollen verdichtet (ohne Rolle = „“), sodass keine Personendaten
 * gespeichert werden (Datenschutz, Kapitel 06 §7). Der Aufwand wird nach Arbeitstagen (Mo–Fr)
 * auf die Monate der Vorgangsdauer verteilt; die Rollen übersetzt ROLE_POOL_MAPPING in Pools.
 */
class PlanDemand {
	/** Rollen mit mehr Zeichen werden gekürzt (Spaltenlänge im Mapping) */
	public const MAX_ROLE = 120;

	/**
	 * @param list<array<string, mixed>> $tasks Vorgänge mit start_date, end_date und demand
	 * @return list<array{role: string, period: string, days: float}>
	 */
	public static function aggregate(array $tasks): array {
		$sum = [];
		foreach ($tasks as $task) {
			if (!isset($task['demand']) || !is_array($task['demand'])) {
				continue;
			}
			$start = isset($task['start_date']) ? (string)$task['start_date'] : '';
			$end = isset($task['end_date']) ? (string)$task['end_date'] : '';
			foreach ($task['demand'] as $d) {
				$days = (float)($d['days'] ?? 0);
				if ($days <= 0) {
					continue;
				}
				$role = self::role((string)($d['role'] ?? ''));
				foreach (self::spread($start, $end, $days) as $period => $part) {
					$sum[$role][$period] = ($sum[$role][$period] ?? 0.0) + $part;
				}
			}
		}
		ksort($sum, SORT_STRING);
		$result = [];
		foreach ($sum as $role => $periods) {
			ksort($periods);
			foreach ($periods as $period => $days) {
				if (round($days, 1) > 0) {
					$result[] = ['role' => (string)$role, 'period' => (string)$period, 'days' => round($days, 1)];
				}
			}
		}
		return $result;
	}

	/**
	 * Aufwand nach Arbeitstagen auf Monate verteilen. Ohne Termine zählt er nicht (kein Monat).
	 *
	 * @return array<string, float> JJJJ-MM => Personentage
	 */
	public static function spread(string $start, string $end, float $days): array {
		$valid = static fn (string $d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
		if (!$valid($start) && !$valid($end)) {
			return [];
		}
		$start = $valid($start) ? $start : $end;
		$end = $valid($end) ? $end : $start;
		if ($end < $start) {
			[$start, $end] = [$end, $start];
		}
		$from = new \DateTimeImmutable($start);
		$to = new \DateTimeImmutable($end);
		$perMonth = [];
		$total = 0;
		for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
			if ((int)$d->format('N') <= 5) {
				$period = $d->format('Y-m');
				$perMonth[$period] = ($perMonth[$period] ?? 0) + 1;
				$total++;
			}
		}
		if ($total === 0) {
			// nur Wochenende: in den Monat des Beginns
			return [$from->format('Y-m') => $days];
		}
		return array_map(static fn (int $n) => $days * $n / $total, $perMonth);
	}

	/**
	 * Planbedarf über das Mapping in Pools übersetzen.
	 *
	 * @param list<array{role: string, period: string, days: float}> $demand
	 * @param array<string, int> $mapping Rolle => Pool-ID
	 * @return array{pools: array<int, array<string, float>>, unmapped: array<string, float>, periods: list<string>}
	 */
	public static function map(array $demand, array $mapping): array {
		$pools = [];
		$unmapped = [];
		$periods = [];
		foreach ($demand as $d) {
			$periods[$d['period']] = true;
			$poolId = $mapping[$d['role']] ?? null;
			if ($poolId === null) {
				$unmapped[$d['role']] = round(($unmapped[$d['role']] ?? 0) + $d['days'], 1);
				continue;
			}
			$pools[$poolId][$d['period']] = round(($pools[$poolId][$d['period']] ?? 0) + $d['days'], 1);
		}
		$periods = array_keys($periods);
		sort($periods);
		ksort($unmapped, SORT_STRING);
		return ['pools' => $pools, 'unmapped' => $unmapped, 'periods' => $periods];
	}

	public static function role(string $role): string {
		return mb_substr(trim((string)preg_replace('/\s+/u', ' ', $role)), 0, self::MAX_ROLE);
	}

	/**
	 * ISO-8601-Dauer (z. B. PT16H0M0S, P1DT4H) in Personentage zu 8 Stunden.
	 */
	public static function isoDays(?string $duration): float {
		if ($duration === null || $duration === '' || !preg_match('/^P(?:(\d+(?:\.\d+)?)D)?(?:T(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $duration, $m)) {
			return 0.0;
		}
		$hours = (float)($m[1] ?? 0) * 24 + (float)($m[2] ?? 0) + (float)($m[3] ?? 0) / 60 + (float)($m[4] ?? 0) / 3600;
		return $hours / 8;
	}
}
