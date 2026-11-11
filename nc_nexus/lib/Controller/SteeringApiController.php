<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\ConflictService;
use OCA\Nexus\Service\EstimationService;
use OCA\Nexus\Service\KpiService;
use OCA\Nexus\Service\NotificationService;
use OCA\Nexus\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Portfolio-Steuerung: Ressourcenkonflikte mit Eskalation, KPI-Dashboard, Schwellenwerte.
 */
class SteeringApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private ConflictService $conflicts,
		private KpiService $kpis,
		private SettingsService $settings,
		private NotificationService $notifications,
		private EstimationService $estimation,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Ressourcenkonflikte.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Konflikte
	 * 403: keine Governance-Rolle
	 */
	#[NoAdminRequired]
	public function conflicts(): DataResponse {
		return $this->handle(fn () => $this->conflicts->list($this->uid()));
	}

	/**
	 * Konflikterkennung jetzt ausführen (PMO, Pool-Management) und Fristen eskalieren.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Konflikte
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function detect(): DataResponse {
		return $this->handle(function () {
			if (!$this->conflicts->canResolve($this->uid())) {
				throw new \OCA\Nexus\Exception\ForbiddenException('Only the portfolio office or pool managers may resolve conflicts');
			}
			$found = $this->conflicts->detect($this->uid());
			$escalated = $this->conflicts->escalate();
			$this->notifications->conflicts($found['created'], $escalated);
			return $this->conflicts->list($this->uid());
		});
	}

	/**
	 * Konflikt lösen oder bewusst akzeptieren.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function resolve(int $id): DataResponse {
		return $this->handle(fn () => $this->conflicts->resolve($this->uid(), $id, $this->payload()));
	}

	/**
	 * Nachkalkulation abgeschlossener Projekte mit Korrekturfaktoren je Pool und Klasse.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Nachkalkulation
	 * 403: keine Portfolio-Rolle
	 */
	#[NoAdminRequired]
	public function postCalculation(): DataResponse {
		return $this->handle(fn () => $this->estimation->overview($this->uid()));
	}

	/**
	 * Schätzkorrekturfaktoren ohne Projektbezug (Hinweis beim Erfassen von Bedarfen).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Faktoren
	 */
	#[NoAdminRequired]
	public function estimateFactors(): DataResponse {
		return $this->handle(fn () => $this->estimation->factors());
	}

	/**
	 * KPI-Dashboard.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Kennzahlen
	 * 403: keine Governance-Rolle
	 */
	#[NoAdminRequired]
	public function kpis(): DataResponse {
		return $this->handle(fn () => $this->kpis->dashboard($this->uid()));
	}

	/**
	 * Eigene Kennzahl anlegen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function addKpi(): DataResponse {
		return $this->handle(fn () => $this->kpis->save($this->uid(), null, $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Kennzahl ändern (Schwellen, Owner, Definition).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateKpi(int $id): DataResponse {
		return $this->handle(fn () => $this->kpis->save($this->uid(), $id, $this->payload()));
	}

	/**
	 * Schwellenwerte der Steuerung.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Schwellen
	 */
	#[NoAdminRequired]
	public function settings(): DataResponse {
		return $this->handle(fn () => ['values' => $this->settings->all(), 'defaults' => SettingsService::DEFAULTS]);
	}

	/**
	 * Schwellenwerte ändern (PMO).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gespeichert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function saveSettings(): DataResponse {
		return $this->handle(fn () => ['values' => $this->settings->save($this->uid(), $this->payload()), 'defaults' => SettingsService::DEFAULTS]);
	}
}
