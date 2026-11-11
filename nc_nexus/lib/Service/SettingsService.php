<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\ValidationException;
use OCP\IAppConfig;

/**
 * Schwellenwerte der Steuerung (Kapitel 02 §4.4/4.5, 03 §7.2, 01 §7) als Konfiguration, nicht Code.
 */
class SettingsService {
	/** Standardwerte aus dem Konzept */
	public const DEFAULTS = [
		'crBudgetPct' => 10.0,        // Change Request oberhalb Schwelle: Budget +10 % …
		'crBudgetAbs' => 50000.0,     // … oder +50 T€
		'crDays' => 30.0,             // Termin um mehr als einen Monat
		'crPersonDaysPct' => 15.0,    // Ressourcenbedarf +15 % in einem Engpass-Pool
		'facTolerancePct' => 10.0,    // FAC über Budgetrahmen: Toleranz bis zur Nachfinanzierung
		'reportDaysLarge' => 35.0,    // Statusbericht monatlich (Klasse M/L), mit Karenz
		'reportDaysSmall' => 100.0,   // quartalsweise (Klasse XS/S)
		'effortDeviationPct' => 25.0, // Ist-Aufwand weicht vom Plan ab
		'riskEscalationScore' => 15.0, // Risiko-Score, ab dem eskaliert wird
		'conflictLevel1Days' => 7.0,  // Stufe 1 bilateral, dann Ressourcen-Board
		'conflictLevel2Days' => 30.0, // Stufe 2 Ressourcen-Board, dann Portfolio-Board
		'conditionReminderDays' => 7.0, // Erinnerung vor Fälligkeit einer Auflage
		// Vorhabensklassen (01 §6.1): Untergrenzen je Klasse in Personentagen und Euro; 0 = Kriterium
		// nicht genutzt. Maßgeblich ist das höhere Kriterium.
		'classDaysS' => 5.0,
		'classDaysM' => 30.0,
		'classDaysL' => 200.0,
		'classBudgetS' => 0.0,
		'classBudgetM' => 25000.0,
		'classBudgetL' => 250000.0,
	];

	public function __construct(
		private IAppConfig $config,
		private PermissionService $permissions,
		private AuditService $audit,
	) {
	}

	/** @return array<string, float> */
	public function all(): array {
		$stored = json_decode($this->config->getValueString(Application::APP_ID, 'thresholds', '{}'), true);
		$values = self::DEFAULTS;
		foreach (is_array($stored) ? $stored : [] as $key => $value) {
			if (array_key_exists((string)$key, self::DEFAULTS) && is_numeric($value)) {
				$values[(string)$key] = (float)$value;
			}
		}
		return $values;
	}

	public function get(string $key): float {
		return $this->all()[$key] ?? self::DEFAULTS[$key] ?? 0.0;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, float>
	 * @throws ForbiddenException|ValidationException
	 */
	public function save(string $userId, array $data): array {
		if (!$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may change the governance configuration');
		}
		$before = $this->all();
		$values = $before;
		foreach ($data as $key => $value) {
			if (!array_key_exists((string)$key, self::DEFAULTS)) {
				continue;
			}
			if (!is_numeric($value) || (float)$value < 0) {
				throw new ValidationException('Thresholds must be positive numbers');
			}
			$values[(string)$key] = (float)$value;
		}
		foreach (['classDays', 'classBudget'] as $prefix) {
			$bounds = array_filter([$values[$prefix . 'S'], $values[$prefix . 'M'], $values[$prefix . 'L']], static fn (float $v) => $v > 0);
			$sorted = $bounds;
			sort($sorted);
			if (array_values($bounds) !== $sorted || count(array_unique($bounds)) !== count($bounds)) {
				throw new ValidationException('Class limits must increase from S to L');
			}
		}
		$this->config->setValueString(Application::APP_ID, 'thresholds', json_encode($values, JSON_THROW_ON_ERROR));
		$changes = [];
		foreach ($values as $key => $value) {
			if ($before[$key] !== $value) {
				$changes[$key] = [$before[$key], $value];
			}
		}
		if ($changes !== []) {
			$this->audit->log('settings', 0, 'update', $userId, $changes);
		}
		return $values;
	}
}
