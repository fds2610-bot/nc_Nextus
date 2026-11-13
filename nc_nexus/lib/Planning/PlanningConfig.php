<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Exception\ValidationException;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;

/**
 * Einstellungen der Planungstools, gepflegt vom PMO im Bereich „Planungstools“.
 * Adressen und Schalter liegen in IAppConfig, Passwörter im Credential-Store von Nextcloud
 * (systemweit, Konto „“), nie in App-Tabellen (Kapitel 07 §4.2).
 */
class PlanningConfig {
	private const GANTT_URL = 'gantt_url';
	private const GANTT_USER = 'gantt_user';
	private const GANTT_SECRET = Application::APP_ID . '::gantt';
	private const OPENPROJECT_URL = 'openproject_url';
	private const MSPDI_IMPORT = 'mspdi_import';
	private const MSPDI_EXPORT = 'mspdi_export';
	private const SYNC_HOURS = 'planning_sync_hours';
	private const SYNC_LAST = 'planning_sync_last';
	/** wählbare Abstände des Hintergrundabgleichs in Stunden; 0 = aus */
	public const SYNC_CHOICES = [0, 1, 6, 12, 24];
	public const SYNC_DEFAULT = 24;

	public function __construct(
		private IAppConfig $config,
		private ICredentialsManager $credentials,
	) {
	}

	/** Adresse einer anderen Nextcloud mit nc_gantt; leer = Gantt dieser Instanz */
	public function ganttUrl(): string {
		return $this->url(self::GANTT_URL);
	}

	public function ganttUser(): string {
		return $this->config->getValueString(Application::APP_ID, self::GANTT_USER, '');
	}

	public function ganttPassword(): string {
		try {
			$value = $this->credentials->retrieve('', self::GANTT_SECRET);
			return is_string($value) ? $value : '';
		} catch (\Throwable) {
			return '';
		}
	}

	/**
	 * Entfernte Gantt-Instanz setzen; ohne Adresse werden Konto und Passwort gelöscht.
	 *
	 * @param string|null $password null = gespeichertes Passwort behalten
	 * @throws ValidationException
	 */
	public function setGantt(string $url, string $user, ?string $password): void {
		$url = self::normalizeUrl($url, 'Enter the address of the Nextcloud with Gantt as http(s) URL');
		if ($url === '') {
			$this->config->deleteKey(Application::APP_ID, self::GANTT_URL);
			$this->config->deleteKey(Application::APP_ID, self::GANTT_USER);
			$this->credentials->delete('', self::GANTT_SECRET);
			return;
		}
		$user = trim($user);
		if ($user === '') {
			throw new ValidationException('Enter the account for the Gantt instance');
		}
		$this->config->setValueString(Application::APP_ID, self::GANTT_URL, $url);
		$this->config->setValueString(Application::APP_ID, self::GANTT_USER, $user);
		if ($password !== null && trim($password) !== '') {
			$this->credentials->store('', self::GANTT_SECRET, trim($password));
		}
	}

	public function openprojectUrl(): string {
		return $this->url(self::OPENPROJECT_URL);
	}

	/**
	 * @throws ValidationException
	 */
	public function setOpenprojectUrl(string $url): void {
		$url = self::normalizeUrl($url, 'Enter the address of the OpenProject instance as http(s) URL');
		if ($url === '') {
			$this->config->deleteKey(Application::APP_ID, self::OPENPROJECT_URL);
		} else {
			$this->config->setValueString(Application::APP_ID, self::OPENPROJECT_URL, $url);
		}
	}

	public function mspdiImport(): bool {
		return $this->config->getValueString(Application::APP_ID, self::MSPDI_IMPORT, '1') === '1';
	}

	public function mspdiExport(): bool {
		return $this->config->getValueString(Application::APP_ID, self::MSPDI_EXPORT, '1') === '1';
	}

	public function setMspdi(bool $import, bool $export): void {
		$this->config->setValueString(Application::APP_ID, self::MSPDI_IMPORT, $import ? '1' : '0');
		$this->config->setValueString(Application::APP_ID, self::MSPDI_EXPORT, $export ? '1' : '0');
	}

	/** Abstand des Hintergrundabgleichs in Stunden, 0 = aus */
	public function syncHours(): int {
		$hours = (int)$this->config->getValueString(Application::APP_ID, self::SYNC_HOURS, (string)self::SYNC_DEFAULT);
		return in_array($hours, self::SYNC_CHOICES, true) ? $hours : self::SYNC_DEFAULT;
	}

	/**
	 * @throws ValidationException
	 */
	public function setSyncHours(int $hours): void {
		if (!in_array($hours, self::SYNC_CHOICES, true)) {
			throw new ValidationException('Choose a valid interval for the background sync');
		}
		$this->config->setValueString(Application::APP_ID, self::SYNC_HOURS, (string)$hours);
	}

	/** @return array{at: int, ok: int, failed: int}|null letzter Lauf des Hintergrundabgleichs */
	public function lastSync(): ?array {
		$raw = json_decode($this->config->getValueString(Application::APP_ID, self::SYNC_LAST, ''), true);
		return is_array($raw) && isset($raw['at']) ? ['at' => (int)$raw['at'], 'ok' => (int)($raw['ok'] ?? 0), 'failed' => (int)($raw['failed'] ?? 0)] : null;
	}

	/** @param array{at: int, ok: int, failed: int} $run */
	public function setLastSync(array $run): void {
		$this->config->setValueString(Application::APP_ID, self::SYNC_LAST, json_encode($run, JSON_THROW_ON_ERROR));
	}

	private function url(string $key): string {
		return rtrim(trim($this->config->getValueString(Application::APP_ID, $key, '')), '/');
	}

	/**
	 * @throws ValidationException
	 */
	private static function normalizeUrl(string $url, string $message): string {
		$url = rtrim(trim($url), '/');
		if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url))) {
			throw new ValidationException($message);
		}
		return $url;
	}
}
