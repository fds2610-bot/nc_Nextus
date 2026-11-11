<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\BenefitService;
use OCA\Nexus\Service\BusinessCaseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Business Case je Antrag und Nutzenregister.
 */
class BusinessCaseApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private BusinessCaseService $cases,
		private BenefitService $benefits,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Business Case eines Antrags mit Kennzahlen und Versionen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Business Case
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->handle(fn () => $this->cases->get($this->uid(), $id));
	}

	/**
	 * Entwurf des Business Case speichern.
	 *
	 * @param array<string, mixed> $content Kosten, Nutzenpositionen, Annahmen, Kalkulationszins
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gespeichert
	 * 400: Pflichtangaben fehlen
	 * 403: keine Berechtigung oder falscher Status
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function save(int $id, array $content = []): DataResponse {
		return $this->handle(fn () => $this->cases->save($this->uid(), $id, $content));
	}

	/**
	 * Nutzenregister: Nutzenpositionen lesbarer Vorhaben und eigene Positionen, mit Prognose,
	 * Reviews, Nutzenbilanz und Korrekturfaktoren.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Nutzenregister
	 */
	#[NoAdminRequired]
	public function register(): DataResponse {
		return $this->handle(fn () => $this->benefits->register($this->uid()));
	}

	/**
	 * Korrekturfaktoren je Nutzenart aus den bisherigen Nutzenreviews.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Korrekturfaktoren
	 */
	#[NoAdminRequired]
	public function factors(): DataResponse {
		return $this->handle(fn () => $this->benefits->factors());
	}

	/**
	 * Prognose, Realisierungsstatus und Messzeitpunkte einer Nutzenposition pflegen.
	 *
	 * @param array<string, mixed> $tracking forecast, forecastAmount, realizationStatus, reviewDates
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gespeichert
	 * 400: ungültige Angaben
	 * 403: keine Berechtigung
	 * 404: Position nicht gefunden
	 */
	#[NoAdminRequired]
	public function track(int $id, string $key, array $tracking = []): DataResponse {
		return $this->handle(fn () => $this->benefits->track($this->uid(), $id, $key, $tracking));
	}

	/**
	 * Nutzenreview erfassen.
	 *
	 * @param array<string, mixed> $review reviewDate, actualValue, actualAmount, achievementPct, assessment, explanation, lessonsLearned, actions
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: erfasst
	 * 400: Pflichtangaben fehlen
	 * 403: keine Berechtigung
	 * 404: Position nicht gefunden
	 */
	#[NoAdminRequired]
	public function review(int $id, string $key, array $review = []): DataResponse {
		return $this->handle(fn () => $this->benefits->review($this->uid(), $id, $key, $review), Http::STATUS_CREATED);
	}
}
