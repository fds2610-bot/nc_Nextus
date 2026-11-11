<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\SessionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Sitzungen der Gremien: Tagesordnung, Vorlagen, Abstimmung, Beschlüsse.
 */
class SessionApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private SessionService $sessions,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Sitzungen, die das Konto sehen darf.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Sitzungen
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->handle(fn () => $this->sessions->list($this->uid()));
	}

	/**
	 * Sitzungsmappe.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Sitzung mit Tagesordnung
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->handle(fn () => $this->sessions->detail($this->uid(), $id));
	}

	/**
	 * Sitzung anlegen (optional mit Kalendertermin und Talk-Raum).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: Gremium nicht gefunden
	 */
	#[NoAdminRequired]
	public function create(): DataResponse {
		return $this->handle(fn () => $this->sessions->create($this->uid(), $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Sitzung ändern.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: geändert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function update(int $id): DataResponse {
		return $this->handle(fn () => $this->sessions->update($this->uid(), $id, $this->payload()));
	}

	/**
	 * Geplante Sitzung löschen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gelöscht
	 * 400: nicht mehr geplant
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->sessions->delete($this->uid(), $id);
			return [];
		});
	}

	/**
	 * Status weiterschalten (agenda_final, held, minuted).
	 *
	 * @param string $status neuer Status
	 * @param string|null $minutes Protokoll beim Abschluss
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: geändert
	 * 400: Statuswechsel nicht möglich
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function status(int $id, string $status = '', ?string $minutes = null): DataResponse {
		return $this->handle(fn () => $this->sessions->setStatus($this->uid(), $id, $status, $minutes));
	}

	/**
	 * Tagesordnung aus entscheidungsreifen Gates erzeugen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Sitzung
	 * 400: Sitzung abgeschlossen
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function agenda(int $id): DataResponse {
		return $this->handle(fn () => $this->sessions->generateAgenda($this->uid(), $id));
	}

	/**
	 * Freien Tagesordnungspunkt hinzufügen.
	 *
	 * @param string $title Titel
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Sitzung
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function addTopic(int $id, string $title = ''): DataResponse {
		return $this->handle(fn () => $this->sessions->addTopic($this->uid(), $id, $title));
	}

	/**
	 * Tagesordnungspunkt ändern (Empfehlung, Optionen, Reihenfolge).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Sitzung
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateItem(int $id): DataResponse {
		return $this->handle(fn () => $this->sessions->updateItem($this->uid(), $id, $this->payload()));
	}

	/**
	 * Tagesordnungspunkt entfernen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Sitzung
	 * 400: bereits entschieden
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function removeItem(int $id): DataResponse {
		return $this->handle(fn () => $this->sessions->removeItem($this->uid(), $id));
	}

	/**
	 * Stimme abgeben (selbst oder in Stellvertretung).
	 *
	 * @param string $vote yes, no oder abstain
	 * @param string|null $memberId Mitglied, für das in Stellvertretung abgestimmt wird
	 * @param bool $conflict Interessenkonflikt (Enthaltung)
	 * @param string|null $comment Kommentar
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Sitzung
	 * 400: Abstimmung nicht offen
	 * 403: nicht stimmberechtigt
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function vote(int $id, string $vote = '', ?string $memberId = null, bool $conflict = false, ?string $comment = null): DataResponse {
		return $this->handle(fn () => $this->sessions->vote($this->uid(), $id, $memberId, $vote, $conflict, $comment));
	}

	/**
	 * Beschluss zum Tagesordnungspunkt protokollieren.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Sitzung
	 * 400: Quorum, Mehrheit oder Pflichtangaben fehlen
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function decide(int $id): DataResponse {
		return $this->handle(fn () => $this->sessions->decide($this->uid(), $id, $this->payload()));
	}

	/**
	 * Tagesordnungspunkt vertagen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Sitzung
	 * 400: nicht möglich
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function adjourn(int $id): DataResponse {
		return $this->handle(fn () => $this->sessions->adjourn($this->uid(), $id));
	}
}
