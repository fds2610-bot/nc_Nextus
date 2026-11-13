<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\AiService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * KI-Assistenz je Vorhaben (Zusammenfassung, Widerspruchsprüfung, Vorlagenentwurf) über TaskProcessing.
 */
class AiApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private AiService $ai,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Mögliche Aufträge und letzte Ergebnisse des Kontos zu einem Vorhaben.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Stand der KI-Assistenz
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->handle(fn () => $this->ai->get($this->uid(), $id));
	}

	/**
	 * Auftrag an die KI geben.
	 *
	 * @param string $kind summary, contradictions oder proposal
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: eingeplant
	 * 400: kein KI-Dienst oder Auftrag nicht möglich
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function request(int $id, string $kind): DataResponse {
		return $this->handle(fn () => $this->ai->request($this->uid(), $id, $kind), Http::STATUS_CREATED);
	}

	/**
	 * Schalter und erkannter Anbieter.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Einstellungen
	 */
	#[NoAdminRequired]
	public function config(): DataResponse {
		return $this->handle(fn () => $this->ai->config($this->uid()));
	}

	/**
	 * KI-Assistenz ein- oder ausschalten (nur PMO).
	 *
	 * @param bool $enabled an/aus
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gespeichert
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function saveConfig(bool $enabled): DataResponse {
		return $this->handle(fn () => $this->ai->saveConfig($this->uid(), $enabled));
	}
}
