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
 * Planungs-Integration (nc_gantt) je Vorhaben.
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
	 * Verknüpfung und verdichteter Planungsstand.
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
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Projekte
	 * 400: Planungstool nicht verfügbar
	 */
	#[NoAdminRequired]
	public function projects(): DataResponse {
		return $this->handle(fn () => $this->planning->projects($this->uid()));
	}

	/**
	 * Gantt-Projekt anlegen (ohne projectId) oder bestehendes verknüpfen.
	 *
	 * @param int|null $projectId bestehendes Gantt-Projekt
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: verknüpft
	 * 400: Planungstool nicht verfügbar oder bereits verknüpft
	 * 403: keine Berechtigung oder nicht freigegeben
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function connect(int $id, ?int $projectId = null): DataResponse {
		return $this->handle(fn () => $this->planning->connect($this->uid(), $id, $projectId), Http::STATUS_CREATED);
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
}
