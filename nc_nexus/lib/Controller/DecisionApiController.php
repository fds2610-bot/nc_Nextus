<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Service\DecisionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Entscheidungsregister (DEC-001), nur lesend: /ocs/v2.php/apps/nc_nexus/api/v1/decisions
 */
class DecisionApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private DecisionService $decisions,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Beschlüsse zu allen Anträgen, die das Konto lesen darf, neueste zuerst.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<array<string, mixed>>, array{}>
	 *
	 * 200: Beschlüsse
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse(array_map(static fn ($row) => $row['decision']->jsonSerialize() + [
			'demand' => [
				'id' => $row['demand']->getId(),
				'key' => $row['demand']->getDemandKey(),
				'title' => $row['demand']->getTitle(),
				'orgUnitId' => $row['demand']->getOrgUnitId(),
			],
		], $this->decisions->register((string)$this->userId)));
	}
}
