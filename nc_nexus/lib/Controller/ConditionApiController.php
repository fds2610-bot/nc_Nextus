<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\ConditionService;
use OCA\Nexus\Service\QualityService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Beschluss- und Maßnahmenverfolgung sowie Datenqualitäts-Cockpit.
 */
class ConditionApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private ConditionService $conditions,
		private QualityService $quality,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Auflagen und Maßnahmen, die das Konto sehen darf.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Liste
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->handle(fn () => $this->conditions->list($this->uid()));
	}

	/**
	 * Maßnahme oder Auflage zu einem Vorhaben anlegen (PMO).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: Vorhaben nicht gefunden
	 */
	#[NoAdminRequired]
	public function create(int $id): DataResponse {
		return $this->handle(fn () => $this->conditions->create($this->uid(), $id, $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Erledigt melden (done) oder erlassen (waived).
	 *
	 * @param string $status done oder waived
	 * @param string $evidence Nachweis bzw. Begründung
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: geschlossen
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function close(int $id, string $status = '', string $evidence = ''): DataResponse {
		return $this->handle(fn () => $this->conditions->close($this->uid(), $id, $status, $evidence));
	}

	/**
	 * Datenqualitäts-Cockpit.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Regeln mit Befunden
	 * 403: nur PMO
	 */
	#[NoAdminRequired]
	public function quality(): DataResponse {
		return $this->handle(fn () => $this->quality->cockpit($this->uid()));
	}
}
