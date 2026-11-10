<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCA\Nexus\Service\DemandService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;

/**
 * OCS-API für Anträge: /ocs/v2.php/apps/nc_nexus/api/v1/demands
 */
class DemandApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private DemandService $service,
		private IL10N $l10n,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Alle Anträge, neueste zuerst.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<array<string, mixed>>, array{}>
	 *
	 * 200: Liste der Anträge
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse(array_map(static fn ($d) => $d->jsonSerialize(), $this->service->findAll()));
	}

	/**
	 * Einzelner Antrag.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_NOT_FOUND, array<string, mixed>, array{}>
	 *
	 * 200: Antrag
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->handle(fn () => $this->service->find($id)->jsonSerialize());
	}

	/**
	 * Antrag als Entwurf anlegen.
	 *
	 * @param string $title Titel
	 * @param string $demandType Antragstyp (mandatory, strategic, efficiency, lifecycle, innovation)
	 * @param string|null $description Beschreibung
	 * @param string|null $problemStatement Was passiert, wenn wir nichts tun?
	 * @param string|null $expectedBenefit Erwarteter Nutzen
	 * @return DataResponse<Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST, array<string, mixed>, array{}>
	 *
	 * 201: Antrag angelegt
	 * 400: Eingaben ungültig
	 */
	#[NoAdminRequired]
	public function create(string $title = '', string $demandType = '', ?string $description = null, ?string $problemStatement = null, ?string $expectedBenefit = null): DataResponse {
		return $this->handle(fn () => $this->service->create($this->uid(), [
			'title' => $title,
			'demandType' => $demandType,
			'description' => $description,
			'problemStatement' => $problemStatement,
			'expectedBenefit' => $expectedBenefit,
		])->jsonSerialize(), Http::STATUS_CREATED);
	}

	/**
	 * Entwurf ändern; nur übergebene Felder werden geändert.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<string, mixed>, array{}>
	 *
	 * 200: Antrag geändert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung oder kein Entwurf
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function update(int $id): DataResponse {
		$data = array_intersect_key($this->request->getParams(), array_flip(['title', 'demandType', 'description', 'problemStatement', 'expectedBenefit']));
		return $this->handle(fn () => $this->service->update($this->uid(), $id, $data)->jsonSerialize());
	}

	/**
	 * Entwurf einreichen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<string, mixed>, array{}>
	 *
	 * 200: Antrag eingereicht
	 * 400: Pflichtangaben fehlen
	 * 403: keine Berechtigung oder kein Entwurf
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function submit(int $id): DataResponse {
		return $this->handle(fn () => $this->service->submit($this->uid(), $id)->jsonSerialize());
	}

	/**
	 * Entwurf löschen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<string, mixed>, array{}>
	 *
	 * 200: Entwurf gelöscht
	 * 403: keine Berechtigung oder kein Entwurf
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->service->delete($this->uid(), $id);
			return [];
		});
	}

	/**
	 * Änderungsverlauf eines Antrags (Audit-Log).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_NOT_FOUND, list<array<string, mixed>>|array<string, mixed>, array{}>
	 *
	 * 200: Verlauf
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function history(int $id): DataResponse {
		return $this->handle(fn () => array_map(static fn ($e) => $e->jsonSerialize(), $this->service->history($id)));
	}

	private function uid(): string {
		return (string)$this->userId;
	}

	/**
	 * @param callable(): array<array-key, mixed> $fn
	 * @param Http::STATUS_OK|Http::STATUS_CREATED $status
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 */
	private function handle(callable $fn, int $status = Http::STATUS_OK): DataResponse {
		try {
			return new DataResponse($fn(), $status);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $this->l10n->t($e->getMessage())], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $this->l10n->t($e->getMessage())], Http::STATUS_FORBIDDEN);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $this->l10n->t($e->getMessage())], Http::STATUS_BAD_REQUEST);
		}
	}
}
