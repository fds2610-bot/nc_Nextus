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
 * Gates (Kandidat, G2–G6) und Kapazitätsschnitt.
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
	 * Gate-Schritt ausführen: candidate (zur Priorisierung) oder ein Gate g2…g6 mit Beschlussart.
	 *
	 * @param string $step candidate oder g2…g6
	 * @param string|null $outcome approved, approved_with_conditions, rework, deferred, rejected, cancelled
	 * @param string|null $reason Begründung (Pflicht außer bei Go)
	 * @param string|null $resubmissionDate Wiedervorlage bzw. Frist (Zurückstellen, Nacharbeit)
	 * @param list<array{title: string, ownerId: string, dueDate: string, blocking?: bool}> $conditions Auflagen bzw. Maßnahmen
	 * @param array{amount?: float, fiscalYear?: int}|null $tranche Budgettranche
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: entschieden
	 * 400: Pflichtangaben fehlen oder Kapazität reicht nicht
	 * 403: keine Berechtigung, falsches Gate oder Gremium zuständig
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function decide(int $id, string $step = '', ?string $outcome = null, ?string $reason = null, ?string $resubmissionDate = null, array $conditions = [], ?array $tranche = null): DataResponse {
		return $this->handle(fn () => ($step === 'candidate'
			? $this->gates->toCandidate($this->uid(), $id, $reason)
			: $this->gates->decide($this->uid(), $id, $step, [
				'outcome' => $outcome ?? 'approved', 'reason' => $reason, 'resubmissionDate' => $resubmissionDate,
				'conditions' => $conditions, 'tranche' => $tranche,
			]))->jsonSerialize());
	}

	/**
	 * Gate dem zuständigen Gremium vorlegen (Pflichtprüfung, dann entscheidungsreif).
	 *
	 * @param string $step g2…g6
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Gate-Instanz
	 * 400: Gate wird direkt entschieden
	 * 403: keine Berechtigung oder falsches Gate
	 * 404: Antrag nicht gefunden
	 */
	#[NoAdminRequired]
	public function submit(int $id, string $step = ''): DataResponse {
		return $this->handle(fn () => $this->gates->submit($this->uid(), $id, $step));
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
