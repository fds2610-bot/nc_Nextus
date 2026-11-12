<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\PlanningService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Planungs-Integration je Vorhaben (Gantt, OpenProject, MS-Project-Datei, weitere Provider).
 */
class PlanningApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private PlanningService $planning,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Verknüpfung, verdichteter Planungsstand und nutzbare Planungstools.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Planungsstand
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->handle(fn () => $this->planning->get($this->uid(), $id));
	}

	/**
	 * Projekte im Planungstool zum Verknüpfen.
	 *
	 * @param string $provider Kennung des Planungstools
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Projekte
	 * 400: Planungstool nicht verfügbar
	 */
	#[NoAdminRequired]
	public function projects(string $provider = 'gantt'): DataResponse {
		return $this->handle(fn () => $this->planning->projects($this->uid(), $provider));
	}

	/**
	 * Projekt im Planungstool anlegen (ohne projectId) oder bestehendes verknüpfen.
	 *
	 * @param string $provider Kennung des Planungstools
	 * @param string|int|null $projectId bestehendes Projekt
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: verknüpft
	 * 400: Planungstool nicht verfügbar oder bereits verknüpft
	 * 403: keine Berechtigung oder nicht freigegeben
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function connect(int $id, string $provider = 'gantt', mixed $projectId = null): DataResponse {
		$projectId = is_scalar($projectId) && (string)$projectId !== '' ? (string)$projectId : null;
		return $this->handle(fn () => $this->planning->connect($this->uid(), $id, $provider, $projectId), Http::STATUS_CREATED);
	}

	/**
	 * Projektplan als Datei importieren (z. B. MS Project XML); ersetzt einen früheren Import.
	 *
	 * @param string $fileName Dateiname
	 * @param string $content Dateiinhalt (Text)
	 * @param string $provider Kennung des Planungstools
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: importiert
	 * 400: Datei ungültig oder anderes Planungstool verknüpft
	 * 403: keine Berechtigung oder nicht freigegeben
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function import(int $id, string $fileName = '', string $content = '', string $provider = 'mspdi'): DataResponse {
		return $this->handle(fn () => $this->planning->import($this->uid(), $id, $provider, $fileName, $content));
	}

	/**
	 * Startplan als MS-Project-XML (MSPDI) mit Projektrahmen und Meilensteinen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Dateiname und Inhalt
	 * 400: Export ausgeschaltet oder Vorhaben nicht freigegeben
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function export(int $id): DataResponse {
		return $this->handle(fn () => $this->planning->export($this->uid(), $id, $this->l10n->t('Project frame (governance)')));
	}

	/**
	 * Verbindung zur eingetragenen Gantt-Instanz prüfen (nur PMO).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Anzahl sichtbarer Projekte
	 * 400: nicht erreichbar oder Anmeldung abgelehnt
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function testGantt(): DataResponse {
		return $this->handle(fn () => $this->planning->testGantt($this->uid()));
	}

	/**
	 * Verknüpfung lösen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gelöst
	 * 403: keine Berechtigung
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function disconnect(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->planning->disconnect($this->uid(), $id);
			return [];
		});
	}

	/**
	 * Persönliche Zugangsdaten (z. B. OpenProject-API-Schlüssel) hinterlegen; leer löscht sie.
	 *
	 * @param string $provider Kennung des Planungstools
	 * @param string|null $secret Schlüssel
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gespeichert
	 * 400: unbekanntes Planungstool
	 */
	#[NoAdminRequired]
	public function credentials(string $provider, ?string $secret = null): DataResponse {
		return $this->handle(function () use ($provider, $secret) {
			$this->planning->setCredentials($this->uid(), $provider, $secret);
			return [];
		});
	}

	/**
	 * Einstellungen der Planungstools.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Einstellungen
	 */
	#[NoAdminRequired]
	public function config(): DataResponse {
		return $this->handle(fn () => $this->planning->config($this->uid()));
	}

	/**
	 * Einstellungen der Planungstools ändern (nur PMO).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gespeichert
	 * 400: ungültige Adresse
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function saveConfig(): DataResponse {
		return $this->handle(fn () => $this->planning->saveConfig($this->uid(), $this->payload()));
	}
}
