<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

/**
 * Provider mit persönlichen Zugangsdaten je Konto (z. B. API-Schlüssel). Gespeichert werden sie
 * im Credential-Store von Nextcloud, nicht in App-Tabellen (Kapitel 07 §4.2).
 */
interface IPlanningCredentials {
	public function hasCredentials(string $userId): bool;

	/** null löscht die Zugangsdaten */
	public function setCredentials(string $userId, ?string $secret): void;
}
