<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCA\Nexus\Service\CapacityService;
use OCA\Nexus\Service\PortfolioService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;

/**
 * OCS-API für Ressourcenpools, Kapazität, Bedarf und Auslastung.
 */
class CapacityApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private CapacityService $capacity,
		private PortfolioService $portfolio,
		private IL10N $l10n,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Alle Pools (Stammdaten, für alle lesbar) mit den Rechten des Kontos.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Pools
	 */
	#[NoAdminRequired]
	public function pools(): DataResponse {
		$uid = $this->uid();
		return $this->handle(fn () => [
			'canManage' => $this->capacity->canManagePools($uid),
			'canSee' => $this->capacity->canSee($uid),
			'pools' => array_map(fn ($p) => $p->jsonSerialize() + ['canEdit' => $this->capacity->canEditCapacity($uid, $p)], $this->capacity->pools($uid)),
		]);
	}

	/**
	 * Pool anlegen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function createPool(): DataResponse {
		return $this->handle(fn () => $this->capacity->savePool($this->uid(), null, $this->request->getParams())->jsonSerialize(), Http::STATUS_CREATED);
	}

	/**
	 * Pool ändern.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: geändert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updatePool(int $id): DataResponse {
		$data = array_diff_key($this->request->getParams(), array_flip(['id', '_route']));
		return $this->handle(fn () => $this->capacity->savePool($this->uid(), $id, $data)->jsonSerialize());
	}

	/**
	 * Pool löschen (nur ohne Bedarf).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gelöscht
	 * 400: noch Bedarf vorhanden
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function deletePool(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->capacity->deletePool($this->uid(), $id);
			return [];
		});
	}

	/**
	 * Kapazität eines Pools mit Rechenweg.
	 *
	 * @param string $from erster Monat (JJJJ-MM)
	 * @param int $months Anzahl Monate (max. 24)
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Kapazität
	 * 404: Pool nicht gefunden
	 */
	#[NoAdminRequired]
	public function capacity(int $id, string $from = '', int $months = 12): DataResponse {
		return $this->handle(fn () => $this->capacity->capacityRows($this->capacity->pool($id), $from ?: $this->thisMonth(), $months));
	}

	/**
	 * Kapazität eines Monats setzen.
	 *
	 * @param float $fte FTE
	 * @param int|null $workingDays Arbeitstage (leer: Werktage des Monats)
	 * @param float|null $overrideDays Übersteuerung der Projekt-Kapazität in Personentagen
	 * @param string|null $overrideReason Begründung der Übersteuerung
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gespeichert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: Pool nicht gefunden
	 */
	#[NoAdminRequired]
	public function setCapacity(int $id, string $period, float $fte = 0, ?int $workingDays = null, ?float $overrideDays = null, ?string $overrideReason = null): DataResponse {
		return $this->handle(fn () => $this->capacity->setCapacity($this->uid(), $id, $period, $fte, $workingDays, $overrideDays, $overrideReason)->jsonSerialize());
	}

	/**
	 * Auslastungs-Heatmap: Pools × Monate.
	 *
	 * @param string $from erster Monat (JJJJ-MM)
	 * @param int $months Anzahl Monate (max. 24)
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Heatmap
	 * 403: keine Governance-Rolle
	 */
	#[NoAdminRequired]
	public function heatmap(string $from = '', int $months = 12): DataResponse {
		return $this->handle(fn () => $this->capacity->heatmap($this->uid(), $from ?: $this->thisMonth(), $months));
	}

	/**
	 * Anträge hinter einer Zelle der Heatmap (nicht lesbare nur als Summe).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Anträge
	 * 403: keine Governance-Rolle
	 * 404: Pool nicht gefunden
	 */
	#[NoAdminRequired]
	public function cell(int $id, string $period): DataResponse {
		return $this->handle(fn () => $this->capacity->cell($this->uid(), $id, $period));
	}

	/**
	 * Ressourcenbedarf eines Antrags.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Bedarf
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function needs(int $id): DataResponse {
		return $this->handle(fn () => array_map(static fn ($n) => $n->jsonSerialize(), $this->capacity->needs($this->uid(), $id)));
	}

	/**
	 * Ressourcenbedarf eines Antrags ersetzen.
	 *
	 * @param list<array{poolId: int, period: string, personDays: float}> $needs Bedarf je Pool und Monat
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gespeichert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function replaceNeeds(int $id, array $needs = []): DataResponse {
		return $this->handle(fn () => array_map(static fn ($n) => $n->jsonSerialize(), $this->capacity->replaceNeeds($this->uid(), $id, $needs)));
	}

	/**
	 * Portfolioübersicht: Org-Einheiten × Portfoliophasen und Bedarf je Quartal.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Übersicht
	 * 403: keine Governance-Rolle
	 */
	#[NoAdminRequired]
	public function portfolio(): DataResponse {
		return $this->handle(fn () => $this->portfolio->overview($this->uid()));
	}

	private function uid(): string {
		return (string)$this->userId;
	}

	private function thisMonth(): string {
		return date('Y-m');
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
