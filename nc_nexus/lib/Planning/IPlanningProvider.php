<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

use OCA\Nexus\Exception\ValidationException;

/**
 * Planungs-Provider (INT-001, Kapitel 07 §4.1): ein Planungstool, mit dem ein freigegebenes
 * Vorhaben verknüpft wird. Nexus liest daraus nur Termine, Fortschritt und Meilensteine und
 * verdichtet sie selbst (PlanningService::summarize); Rechte prüft das Planungstool im Namen
 * des Kontos. Fehler werden als ValidationException gemeldet und blockieren nie die
 * Governance-Funktionen (Kapitel 07 §4.2).
 *
 * Andere Apps registrieren eigene Provider über RegisterPlanningProvidersEvent.
 */
interface IPlanningProvider {
	/** neues Projekt im Planungstool anlegen */
	public const CAP_CREATE = 'create_project';
	/** bestehendes Projekt verknüpfen */
	public const CAP_LINK = 'link_project';
	/** Stand wird beim Abruf live gelesen */
	public const CAP_SYNC = 'sync';
	/** Projektplan als Datei-Schnappschuss importieren (IPlanningFileImport) */
	public const CAP_IMPORT = 'import_file';
	/** persönliche Zugangsdaten nötig (IPlanningCredentials) */
	public const CAP_CREDENTIALS = 'credentials';

	/** kurze, stabile Kennung, z. B. „gantt“; wird in nx_planning_links.provider gespeichert */
	public function getId(): string;

	/** Anzeigename */
	public function getName(): string;

	/** @return list<string> CAP_*-Konstanten */
	public function getCapabilities(): array;

	/** installiert bzw. konfiguriert und für das Konto nutzbar */
	public function isAvailable(string $userId): bool;

	/**
	 * Projekte, die das Konto sehen darf.
	 *
	 * @return list<array{id: string, title: string, url: string}>
	 * @throws ValidationException
	 */
	public function listProjects(string $userId): array;

	/**
	 * @return array{id: string, title: string, url: string}
	 * @throws ValidationException
	 */
	public function createProject(string $userId, string $title, ?string $description): array;

	/**
	 * Vorgänge des Projekts in einheitlicher Form; status „done“ = erledigt, Datumsangaben als
	 * JJJJ-MM-TT.
	 *
	 * @return list<array{title: string, start_date: ?string, end_date: ?string, status: string, progress: float|int, milestone?: bool}>
	 * @throws ValidationException
	 */
	public function tasks(string $userId, string $externalId): array;
}
