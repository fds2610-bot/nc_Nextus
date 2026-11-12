<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\Service\DashboardService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Management-Dashboard (REP-001).
 */
class DashboardApiController extends BaseApiController {
	public function __construct(
		IRequest $request,
		IL10N $l10n,
		?string $userId,
		private DashboardService $dashboard,
	) {
		parent::__construct($request, $l10n, $userId);
	}

	/**
	 * Sechs Kacheln: Portfolio, Finanzen, Kapazität, Risiken, Entscheidungen, Nutzen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Kennzahlen der Kacheln
	 * 403: keine Governance-Rolle
	 */
	#[NoAdminRequired]
	public function management(): DataResponse {
		return $this->handle(fn () => $this->dashboard->management($this->uid()));
	}
}
