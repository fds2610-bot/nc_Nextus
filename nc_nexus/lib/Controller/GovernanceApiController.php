<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\CollabService;
use OCA\Nexus\Service\GovernanceService;
use OCA\Nexus\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Governance-Konfiguration: Gremien mit Mitgliedern, Entscheidungsrechte, Gate-Sets.
 */
class GovernanceApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private GovernanceService $governance,
		private PermissionService $permissions,
		private CollabService $collab,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Gesamte Konfiguration (lesbar für alle; ändern darf das PMO).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Konfiguration
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->handle(fn () => [
			'canManage' => $this->permissions->canManageOrg($this->uid()),
			'bodies' => $this->governance->bodies(),
			'rights' => $this->governance->rights(),
			'gateSets' => $this->governance->gateSets(),
			'checks' => GovernanceService::CHECKS,
			'collab' => $this->collab->capabilities($this->uid()),
		]);
	}

	/**
	 * Gremium anlegen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function createBody(): DataResponse {
		return $this->handle(fn () => $this->governance->saveBody($this->uid(), null, $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Gremium ändern.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: geändert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateBody(int $id): DataResponse {
		return $this->handle(fn () => $this->governance->saveBody($this->uid(), $id, $this->payload()));
	}

	/**
	 * Gremium löschen (nur ohne Sitzungen und Entscheidungsrechte).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gelöscht
	 * 400: noch in Gebrauch
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function deleteBody(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->governance->deleteBody($this->uid(), $id);
			return [];
		});
	}

	/**
	 * Mitglied hinzufügen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: hinzugefügt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: Gremium nicht gefunden
	 */
	#[NoAdminRequired]
	public function addMember(int $id): DataResponse {
		return $this->handle(fn () => $this->governance->saveMember($this->uid(), $id, null, $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Mitglied ändern (Fachsitz, Stimmrecht, Stellvertretung).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: geändert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateMember(int $id, int $memberId): DataResponse {
		return $this->handle(fn () => $this->governance->saveMember($this->uid(), $id, $memberId, array_diff_key($this->payload(), ['memberId' => 0])));
	}

	/**
	 * Mitglied entfernen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: entfernt
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function removeMember(int $id, int $memberId): DataResponse {
		return $this->handle(fn () => $this->governance->removeMember($this->uid(), $id, $memberId));
	}

	/**
	 * Entscheidungsrecht anlegen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function createRight(): DataResponse {
		return $this->handle(fn () => $this->governance->saveRight($this->uid(), null, $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Entscheidungsrecht ändern (neue Version).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: geändert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateRight(int $id): DataResponse {
		return $this->handle(fn () => $this->governance->saveRight($this->uid(), $id, $this->payload()));
	}

	/**
	 * Entscheidungsrecht beenden.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: beendet
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function endRight(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->governance->endRight($this->uid(), $id);
			return [];
		});
	}

	/**
	 * Gate-Set anlegen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function createGateSet(): DataResponse {
		return $this->handle(fn () => $this->governance->saveGateSet($this->uid(), null, $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Gate-Set ändern (neue Version).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: geändert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateGateSet(int $id): DataResponse {
		return $this->handle(fn () => $this->governance->saveGateSet($this->uid(), $id, $this->payload()));
	}

	/**
	 * Gate-Set außer Kraft setzen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: beendet
	 * 400: letztes aktives Set
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function retireGateSet(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->governance->retireGateSet($this->uid(), $id);
			return [];
		});
	}
}
