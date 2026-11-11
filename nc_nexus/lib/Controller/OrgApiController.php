<?php

declare(strict_types=1);

namespace OCA\Nexus\Controller;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCA\Nexus\Service\OrgService;
use OCA\Nexus\Service\GovernanceService;
use OCA\Nexus\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;

/**
 * OCS-API für Organisationsmodell und Rollen: /ocs/v2.php/apps/nc_nexus/api/v1/…
 */
class OrgApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private OrgService $org,
		private PermissionService $permissions,
		private GovernanceService $governance,
		private IL10N $l10n,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Rechte und Rollen des angemeldeten Kontos.
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Rechte und Rollen
	 */
	#[NoAdminRequired]
	public function me(): DataResponse {
		$uid = (string)$this->userId;
		return new DataResponse([
			'userId' => $uid,
			'canManageOrg' => $this->permissions->canManageOrg($uid),
			'canTriage' => $this->permissions->isTriager($uid),
			'roles' => array_map(static fn ($a) => $a->jsonSerialize(), $this->permissions->assignmentsOf($uid)),
			'bodies' => $this->governance->bodiesOf($uid),
		]);
	}

	/**
	 * Alle Org-Einheiten (für Auswahlfelder lesbar für alle).
	 *
	 * @return DataResponse<Http::STATUS_OK, list<array<string, mixed>>, array{}>
	 *
	 * 200: Org-Einheiten
	 */
	#[NoAdminRequired]
	public function units(): DataResponse {
		return new DataResponse(array_map(static fn ($u) => $u->jsonSerialize(), $this->org->units()));
	}

	/**
	 * Org-Einheit anlegen.
	 *
	 * @param string $name Name
	 * @param int|null $parentId übergeordnete Einheit
	 * @param string|null $costCenter Kostenstelle
	 * @param string|null $headUserId Leitung (Konto)
	 * @return DataResponse<Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: angelegt
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: übergeordnete Einheit nicht gefunden
	 */
	#[NoAdminRequired]
	public function createUnit(string $name = '', ?int $parentId = null, ?string $costCenter = null, ?string $headUserId = null): DataResponse {
		return $this->handle(fn () => $this->org->createUnit((string)$this->userId, [
			'name' => $name, 'parentId' => $parentId, 'costCenter' => $costCenter, 'headUserId' => $headUserId,
		])->jsonSerialize(), Http::STATUS_CREATED);
	}

	/**
	 * Org-Einheit ändern; nur übergebene Felder werden geändert.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: geändert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateUnit(int $id): DataResponse {
		$data = array_intersect_key($this->request->getParams(), array_flip(['name', 'parentId', 'costCenter', 'headUserId']));
		return $this->handle(fn () => $this->org->updateUnit((string)$this->userId, $id, $data)->jsonSerialize());
	}

	/**
	 * Org-Einheit löschen (nur ohne Untereinheiten, Anträge und Rollen).
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: gelöscht
	 * 400: noch in Verwendung
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function deleteUnit(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->org->deleteUnit((string)$this->userId, $id);
			return [];
		});
	}

	/**
	 * Alle Rollenzuweisungen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: Rollenzuweisungen
	 * 403: keine Berechtigung
	 */
	#[NoAdminRequired]
	public function assignments(): DataResponse {
		return $this->handle(fn () => array_map(static fn ($a) => $a->jsonSerialize(), $this->org->assignments((string)$this->userId)));
	}

	/**
	 * Rolle zuweisen.
	 *
	 * @param string $role Rolle
	 * @param string $scopeType global oder org_unit
	 * @param int|null $scopeId Org-Einheit bei scopeType org_unit
	 * @param string $principalType user oder group
	 * @param string $principalId Konto- bzw. Gruppen-ID
	 * @param string|null $validFrom gültig ab (JJJJ-MM-TT)
	 * @param string|null $validTo gültig bis (JJJJ-MM-TT)
	 * @param string|null $deputyFor Stellvertretung für Konto
	 * @return DataResponse<Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 201: zugewiesen
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: Org-Einheit nicht gefunden
	 */
	#[NoAdminRequired]
	public function assign(string $role = '', string $scopeType = 'global', ?int $scopeId = null, string $principalType = 'user', string $principalId = '', ?string $validFrom = null, ?string $validTo = null, ?string $deputyFor = null): DataResponse {
		return $this->handle(fn () => $this->org->assign((string)$this->userId, [
			'role' => $role, 'scopeType' => $scopeType, 'scopeId' => $scopeId, 'principalType' => $principalType,
			'principalId' => $principalId, 'validFrom' => $validFrom, 'validTo' => $validTo, 'deputyFor' => $deputyFor,
		])->jsonSerialize(), Http::STATUS_CREATED);
	}

	/**
	 * Rollenzuweisung ändern (Rolle, Bereich, Gültigkeit, Stellvertretung; die Person bleibt).
	 *
	 * @param string $role Rolle
	 * @param string $scopeType global oder org_unit
	 * @param int|null $scopeId Org-Einheit bei scopeType org_unit
	 * @param string|null $validFrom gültig ab (JJJJ-MM-TT)
	 * @param string|null $validTo gültig bis (JJJJ-MM-TT)
	 * @param string|null $deputyFor Stellvertretung für Konto
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: geändert
	 * 400: Eingaben ungültig
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function updateAssignment(int $id, string $role = '', string $scopeType = 'global', ?int $scopeId = null, ?string $validFrom = null, ?string $validTo = null, ?string $deputyFor = null): DataResponse {
		return $this->handle(fn () => $this->org->updateAssignment((string)$this->userId, $id, [
			'role' => $role, 'scopeType' => $scopeType, 'scopeId' => $scopeId,
			'validFrom' => $validFrom, 'validTo' => $validTo, 'deputyFor' => $deputyFor,
		])->jsonSerialize());
	}

	/**
	 * Rollenzuweisung entfernen.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 *
	 * 200: entfernt
	 * 403: keine Berechtigung
	 * 404: nicht gefunden
	 */
	#[NoAdminRequired]
	public function unassign(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->org->unassign((string)$this->userId, $id);
			return [];
		});
	}

	/**
	 * @param callable(): array<array-key, mixed> $fn
	 * @param Http::STATUS_OK|Http::STATUS_CREATED $status
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array<array-key, mixed>, array{}>
	 */
	private function handle(callable $fn, int $status = Http::STATUS_OK): DataResponse {
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
}
