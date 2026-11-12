<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\ScenarioService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Szenarioanalyse (POR-003): Was-wäre-wenn auf Kopien des Portfoliostands.
 */
class ScenarioApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private ScenarioService $scenarios,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Szenarien (POR-003).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Szenarien
	 * 403: keine Governance-Rolle
	 */
	#[NoAdminRequired]
	public function list(): DataResponse {
		return $this->handle(fn () => $this->scenarios->list($this->uid()));
	}

	/**
	 * Szenario mit Änderungen und Vergleich gegen den Ist-Schnitt.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Szenario
	 * 403: keine Governance-Rolle
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function get(int $id): DataResponse {
		return $this->handle(fn () => $this->scenarios->get($this->uid(), $id));
	}

	/**
	 * Szenario anlegen (optional als Kopie).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function create(): DataResponse {
		return $this->handle(fn () => $this->scenarios->create($this->uid(), $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Name und Beschreibung ändern (Entwurf).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function update(int $id): DataResponse {
		return $this->handle(fn () => $this->scenarios->update($this->uid(), $id, $this->payload()));
	}

	/**
	 * Entwurf löschen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gelöscht
	 * 400: kein Entwurf
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function delete(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->scenarios->delete($this->uid(), $id);
			return [];
		});
	}

	/**
	 * Änderung hinzufügen (Entwurf).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function addChange(int $id): DataResponse {
		return $this->handle(fn () => $this->scenarios->addChange($this->uid(), $id, $this->payload()));
	}

	/**
	 * Änderung entfernen (Entwurf).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function removeChange(int $id, int $changeId): DataResponse {
		return $this->handle(fn () => $this->scenarios->removeChange($this->uid(), $id, $changeId));
	}

	/**
	 * Vorlegen: Ergebnis einfrieren.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: keine Änderungen
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function present(int $id): DataResponse {
		return $this->handle(fn () => $this->scenarios->present($this->uid(), $id));
	}

	/**
	 * Vorgelegtes Szenario zurück in den Entwurf.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: falscher Status
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function reopen(int $id): DataResponse {
		return $this->handle(fn () => $this->scenarios->reopen($this->uid(), $id));
	}

	/**
	 * Szenario verwerfen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: falscher Status
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function discard(int $id): DataResponse {
		return $this->handle(fn () => $this->scenarios->discard($this->uid(), $id, (string)($this->payload()['reason'] ?? '')));
	}

	/**
	 * Szenario annehmen: erzeugt Maßnahmen und Umsetzungsaufgaben.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function adopt(int $id): DataResponse {
		return $this->handle(fn () => $this->scenarios->adopt($this->uid(), $id, $this->payload()));
	}

	/**
	 * Umsetzungsaufgabe erledigt melden oder wieder öffnen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function task(int $id, int $index): DataResponse {
		return $this->handle(fn () => $this->scenarios->setTask($this->uid(), $id, $index, (bool)($this->payload()['done'] ?? true)));
	}
}
