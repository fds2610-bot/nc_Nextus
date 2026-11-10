<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Service\DemandService;
use OCA\Nexus\Service\TriageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('demand_types', DemandService::TYPES);
		$this->initialState->provideInitialState('project_classes', TriageService::CLASSES);
		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		return new TemplateResponse(Application::APP_ID, 'main');
	}
}
