<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\ObjectiveService;
use OCA\Nexus\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Strategische Ziele (STR-001) und ihre Zuordnung zu Anträgen.
 *
 * @psalm-type Res = DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
 */
class ObjectiveApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private ObjectiveService $objectives,
		private PermissionService $permissions,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Alle Ziele mit Abdeckung (Anzahl zugeordneter, lesbarer Anträge).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Ziele
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->handle(fn () => [
			'canManage' => $this->permissions->canManageOrg($this->uid()),
			'objectives' => $this->objectives->all($this->uid()),
		]);
	}

	/**
	 * Ziel anlegen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function create(): DataResponse {
		return $this->handle(fn () => $this->objectives->save($this->uid(), null, $this->payload())->jsonSerialize(), Http::STATUS_CREATED);
	}

	/**
	 * Ziel ändern (erzeugt eine neue Version).
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
		return $this->handle(fn () => $this->objectives->save($this->uid(), $id, $this->payload())->jsonSerialize());
	}

	/**
	 * Ziel löschen (nur ohne Unterziele und Zuordnungen).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gelöscht
	 * 400: noch in Verwendung
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->objectives->delete($this->uid(), $id);
			return [];
		});
	}

	/**
	 * Ziele eines Antrags.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: IDs der zugeordneten Ziele
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function ofDemand(int $id): DataResponse {
		return $this->handle(fn () => $this->objectives->ofDemand($this->uid(), $id));
	}

	/**
	 * Ziele eines Antrags ersetzen.
	 *
	 * @param list<int> $objectiveIds IDs der Ziele
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gespeichert
	 * 400: unbekanntes Ziel
	 * 403: keine Berechtigung
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function assign(int $id, array $objectiveIds = []): DataResponse {
		return $this->handle(fn () => $this->objectives->assign($this->uid(), $id, $objectiveIds));
	}
}
