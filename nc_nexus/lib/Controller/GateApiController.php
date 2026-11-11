<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\CapacityCutService;
use OCA\Nexus\Service\GateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Gates (Kandidat, G2, G3) und Kapazitätsschnitt.
 */
class GateApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private GateService $gates,
		private CapacityCutService $cut,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Nächster Schritt eines Vorhabens mit Prüfliste.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Prüfliste
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function status(int $id): DataResponse {
		return $this->handle(fn () => $this->gates->status($this->uid(), $id));
	}

	/**
	 * Gate-Schritt ausführen: candidate, g2 (prioritized/deferred/rejected) oder g3.
	 *
	 * @param string $step candidate, g2 oder g3
	 * @param string|null $outcome Ergebnis bei G2
	 * @param string|null $reason Begründung (Pflicht beim Zurückstellen und Ablehnen)
	 * @param string|null $resubmissionDate Wiedervorlage beim Zurückstellen
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: entschieden
	 * 400: Pflichtangaben fehlen oder Kapazität reicht nicht
	 * 403: keine Berechtigung oder falsches Gate
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function decide(int $id, string $step = '', ?string $outcome = null, ?string $reason = null, ?string $resubmissionDate = null): DataResponse {
		return $this->handle(fn () => (match ($step) {
			'candidate' => $this->gates->toCandidate($this->uid(), $id, $reason),
			'g2' => $this->gates->g2($this->uid(), $id, (string)$outcome, $reason, $resubmissionDate),
			'g3' => $this->gates->g3($this->uid(), $id, $reason),
			default => throw new \OCA\Nexus\Exception\ValidationException('Unknown gate'),
		})->jsonSerialize());
	}

	/**
	 * Kapazitätsschnitt: Startplan je Vorhaben der Rangliste.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Startplan
	 * 403: keine Governance-Rolle
	 */
	#[NoAdminRequired]
	public function cut(): DataResponse {
		return $this->handle(fn () => $this->cut->cut($this->uid()));
	}
}
