<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\ChangeService;
use OCA\Nexus\Service\ProjectService;
use OCA\Nexus\Service\RiskService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Projektsteuerung: Cockpit, Meilensteine, Statusberichte, Ist-Werte, Risiken, Issues, Change Requests.
 */
class ProjectApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private ProjectService $projects,
		private RiskService $risks,
		private ChangeService $changes,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Projekte mit Ampel, Health und Finanzen (Portfolio-Sicht).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Projekte
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->handle(fn () => $this->projects->list($this->uid()));
	}

	/**
	 * Projekt-Cockpit.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->handle(fn () => $this->projects->cockpit($this->uid(), $id));
	}

	/**
	 * Projektstammdaten pflegen (Projektleitung, Sponsor, Plantermine, gebundenes Budget).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function update(int $id): DataResponse {
		return $this->handle(fn () => $this->projects->saveProject($this->uid(), $id, $this->payload()));
	}

	/**
	 * Meilenstein anlegen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function addMilestone(int $id): DataResponse {
		return $this->handle(fn () => $this->projects->saveMilestone($this->uid(), $id, null, $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Meilenstein ändern.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateMilestone(int $id, int $milestoneId): DataResponse {
		return $this->handle(fn () => $this->projects->saveMilestone($this->uid(), $id, $milestoneId, array_diff_key($this->payload(), ['milestoneId' => 0])));
	}

	/**
	 * Meilenstein löschen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function deleteMilestone(int $id, int $milestoneId): DataResponse {
		return $this->handle(fn () => $this->projects->deleteMilestone($this->uid(), $id, $milestoneId));
	}

	/**
	 * Vorlage für den nächsten Statusbericht (vorbefüllt).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function reportTemplate(int $id): DataResponse {
		return $this->handle(fn () => $this->projects->reportTemplate($this->uid(), $id));
	}

	/**
	 * Statusbericht abgeben; überschreitet der Forecast den Rahmen, entsteht ein Change Request.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: abgegeben
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function addReport(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->projects->saveReport($this->uid(), $id, $this->payload());
			$this->changes->checkForecast($this->uid(), $this->projects->manageable($this->uid(), $id));
			return $this->projects->cockpit($this->uid(), $id);
		}, Http::STATUS_CREATED);
	}

	/**
	 * Ist-Wert erfassen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: erfasst
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function addActual(int $id): DataResponse {
		return $this->handle(fn () => $this->projects->addActual($this->uid(), $id, $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Ist-Werte per CSV importieren (PMO).
	 *
	 * @param string $csv Zeilen „Projekt;Monat;Art;Pool;Wert“
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Ergebnis
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function importActuals(string $csv = ''): DataResponse {
		return $this->handle(fn () => $this->projects->importActuals($this->uid(), $csv));
	}

	/**
	 * Risiken eines Projekts.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function risks(int $id): DataResponse {
		return $this->handle(fn () => $this->risks->risks($this->uid(), $id));
	}

	/**
	 * Risiko anlegen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function addRisk(int $id): DataResponse {
		return $this->handle(fn () => $this->risks->saveRisk($this->uid(), null, $this->payload() + ['demandIds' => [$id]]), Http::STATUS_CREATED);
	}

	/**
	 * Risiko ändern.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateRisk(int $riskId): DataResponse {
		return $this->handle(fn () => $this->risks->saveRisk($this->uid(), $riskId, array_diff_key($this->payload(), ['riskId' => 0])));
	}

	/**
	 * Portfolio-Risikosicht.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Risiken
	 */
	#[NoAdminRequired]
	public function portfolioRisks(): DataResponse {
		return $this->handle(fn () => $this->risks->portfolio($this->uid()));
	}

	/**
	 * Issues eines Projekts.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function issues(int $id): DataResponse {
		return $this->handle(fn () => $this->risks->issues($this->uid(), $id));
	}

	/**
	 * Issue anlegen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function addIssue(int $id): DataResponse {
		return $this->handle(fn () => $this->risks->saveIssue($this->uid(), $id, null, $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Issue ändern.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateIssue(int $id, int $issueId): DataResponse {
		return $this->handle(fn () => $this->risks->saveIssue($this->uid(), $id, $issueId, array_diff_key($this->payload(), ['issueId' => 0])));
	}

	/**
	 * Maßnahme aus Risiko, Issue oder Statusbericht.
	 *
	 * @param string $originType risk, issue oder status_report
	 * @param int|null $originId Herkunft
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function addAction(int $id, string $originType = '', ?int $originId = null): DataResponse {
		return $this->handle(function () use ($id, $originType, $originId) {
			$this->risks->addAction($this->uid(), $id, $originType, $originId, $this->payload());
			return [];
		}, Http::STATUS_CREATED);
	}

	/**
	 * Change Requests eines Projekts.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function changes(int $id): DataResponse {
		return $this->handle(fn () => $this->changes->list($this->uid(), $id));
	}

	/**
	 * Change Request anlegen (Entwurf).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function addChange(int $id): DataResponse {
		return $this->handle(fn () => $this->changes->save($this->uid(), $id, null, $this->payload()), Http::STATUS_CREATED);
	}

	/**
	 * Change Request ändern (Entwurf).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateChange(int $id, int $changeId): DataResponse {
		return $this->handle(fn () => $this->changes->save($this->uid(), $id, $changeId, array_diff_key($this->payload(), ['changeId' => 0])));
	}

	/**
	 * Change Request einreichen (Schwellen auswerten).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function submitChange(int $id, int $changeId): DataResponse {
		return $this->handle(fn () => $this->changes->submit($this->uid(), $id, $changeId));
	}

	/**
	 * Change Request entscheiden.
	 *
	 * @param string $outcome approved oder rejected
	 * @param string|null $reason Begründung
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: OK
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function decideChange(int $id, int $changeId, string $outcome = '', ?string $reason = null): DataResponse {
		return $this->handle(fn () => $this->changes->decide($this->uid(), $id, $changeId, $outcome, $reason));
	}
}
