<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Gemeinsame Basis der OCS-Controller: fachliche Ausnahmen werden zu 404/403/400 mit
 * übersetzter Meldung.
 */
abstract class BaseApiController extends OCSController {
	public function __construct(
		IRequest $request,
		protected IL10N $l10n,
		protected ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function uid(): string {
		return (string)$this->userId;
	}

	/**
	 * @param callable(): array<array-key, mixed> $fn
	 * @param Http::STATUS_OK|Http::STATUS_CREATED $status
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 */
	protected function handle(callable $fn, int $status = Http::STATUS_OK): DataResponse {
		try {
			return new DataResponse($fn(), $status);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $this->l10n->t($e->getMessage())], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $this->l10n->t($e->getMessage())], Http::STATUS_FORBIDDEN);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $this->l10n->t($e->getMessage())], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Nutzdaten der Anfrage ohne Routing-Parameter.
	 *
	 * @return array<string, mixed>
	 */
	protected function payload(): array {
		return array_diff_key($this->request->getParams(), array_flip(['id', '_route']));
	}
}
