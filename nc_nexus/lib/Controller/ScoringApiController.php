<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\AssessmentService;
use OCA\Nexus\Service\RankingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Bewertungsmodell (Buckets, Kriterienkatalog), Bewertungen und Rangliste.
 */
class ScoringApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private AssessmentService $assessments,
		private RankingService $ranking,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Aktiver Kriterienkatalog, Versionen und Buckets.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Bewertungsmodell
	 */
	#[NoAdminRequired]
	public function model(): DataResponse {
		return $this->handle(fn () => $this->assessments->model($this->uid()));
	}

	/**
	 * Kriterienkatalog als neue Version speichern.
	 *
	 * @param list<array<string, mixed>> $criteria Kriterien mit code, name, dimension, weight, anchors
	 * @param string|null $note Anlass der Änderung
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: neue Version aktiv
	 * 400: Katalog ungültig
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function newVersion(array $criteria = [], ?string $note = null): DataResponse {
		return $this->handle(fn () => $this->assessments->newVersion($this->uid(), $criteria, $note), Http::STATUS_CREATED);
	}

	/**
	 * Zielquote und Budgetrahmen eines Buckets ändern.
	 *
	 * @param float $targetSharePct Zielquote der Kapazität in Prozent
	 * @param float|null $targetBudget Budgetrahmen
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gespeichert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function updateBucket(string $code, float $targetSharePct = 0, ?float $targetBudget = null): DataResponse {
		return $this->handle(fn () => $this->assessments->updateBucket($this->uid(), $code, $targetSharePct, $targetBudget));
	}

	/**
	 * Bewertungen eines Antrags mit geltender Bewertung und Aufwandsvorschlag.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Bewertungen
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function ofDemand(int $id): DataResponse {
		return $this->handle(fn () => $this->assessments->ofDemand($this->uid(), $id));
	}

	/**
	 * Antrag bewerten (Selbstbewertung oder Fremdbewertung des PMO); unveränderlich.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: Bewertung angelegt
	 * 400: Bewertung unvollständig
	 * 403: keine Berechtigung oder falscher Status
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function assess(int $id): DataResponse {
		return $this->handle(fn () => $this->assessments->assess($this->uid(), $id, $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Rangliste je Bucket.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Rangliste
	 * 403: keine Governance-Rolle
	 */
	#[NoAdminRequired]
	public function ranking(): DataResponse {
		return $this->handle(fn () => $this->ranking->ranking($this->uid()));
	}

	/**
	 * Rang übersteuern (Board-Korrektur) oder Korrektur zurücknehmen.
	 *
	 * @param int|null $rank gewünschter Rang; null nimmt die Korrektur zurück
	 * @param string|null $reason Begründung (Pflicht beim Setzen)
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gespeichert
	 * 400: Rang oder Begründung fehlt
	 * 403: keine Berechtigung
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function override(int $id, ?int $rank = null, ?string $reason = null): DataResponse {
		return $this->handle(fn () => $this->ranking->override($this->uid(), $id, $rank, $reason)->jsonSerialize());
	}
}
