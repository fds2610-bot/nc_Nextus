<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Governance-Konfiguration (GOV-001/003, LIF-001): Gremien mit Mitgliedern, Entscheidungsrechte-Matrix
 * und Gate-Sets. Alles sind Daten, kein Code; Entscheidungsrechte und Gate-Sets sind versioniert,
 * damit Beschlüsse gegen das damals gültige Mandat prüfbar bleiben (Kapitel 05 §11).
 */
class GovernanceService {
	public const BODY_TYPES = ['executive', 'portfolio_board', 'resource_board', 'architecture_board', 'steering_committee', 'pmo'];
	public const GATES = ['g2', 'g3', 'g4', 'g5', 'g6'];
	/** Entscheidungstypen der Matrix: Gates und Change Requests oberhalb der Schwelle */
	public const DECISION_TYPES = ['g2', 'g3', 'g4', 'g5', 'g6', 'change'];
	/** Maschinenlesbare Pflichtprüfungen, aus denen Gate-Definitionen ihre Eingangsbedingung bilden */
	public const CHECKS = ['org_unit', 'project_class', 'objectives', 'assessment_review', 'business_case', 'resource_needs', 'capacity', 'open_conditions', 'planning_link', 'benefit_reviews'];

	private const BODIES = 'nx_bodies';
	private const MEMBERS = 'nx_body_members';
	private const RIGHTS = 'nx_decision_rights';
	private const SETS = 'nx_gate_sets';
	private const DEFS = 'nx_gate_defs';

	private bool $defaultsChecked = false;

	public function __construct(
		private Rows $rows,
		private PermissionService $permissions,
		private AuditService $audit,
		private IUserManager $userManager,
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	// ---- Gremien ------------------------------------------------------------------------------

	/** @return list<array<string, mixed>> Gremien mit Mitgliedern */
	public function bodies(): array {
		$members = [];
		foreach ($this->rows->where(self::MEMBERS, [], 'userId') as $m) {
			$members[$m['bodyId']][] = $m;
		}
		return array_map(static fn (array $b) => $b + ['members' => $members[$b['id']] ?? []], $this->rows->where(self::BODIES, [], 'name'));
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function body(int $id): array {
		foreach ($this->bodies() as $body) {
			if ($body['id'] === $id) {
				return $body;
			}
		}
		throw new NotFoundException('Body not found');
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function saveBody(string $userId, ?int $id, array $data): array {
		$this->requireManage($userId);
		$before = $id === null ? [] : $this->body($id);
		$name = trim((string)($data['name'] ?? ($before['name'] ?? '')));
		$code = strtoupper(trim((string)($data['code'] ?? ($before['code'] ?? ''))));
		$type = (string)($data['bodyType'] ?? ($before['bodyType'] ?? 'portfolio_board'));
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('A name is required');
		}
		if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,15}$/', $code)) {
			throw new ValidationException('The code needs 1–16 letters or digits');
		}
		foreach ($this->rows->where(self::BODIES, ['code' => $code]) as $other) {
			if ($other['id'] !== $id) {
				throw new ValidationException('The code is already used');
			}
		}
		if (!in_array($type, self::BODY_TYPES, true)) {
			throw new ValidationException('Unknown body type');
		}
		$chair = trim((string)($data['chairId'] ?? ($before['chairId'] ?? '')));
		if ($chair !== '' && !$this->userManager->userExists($chair)) {
			throw new ValidationException('Unknown account or group');
		}
		$quorum = (int)($data['quorumMin'] ?? ($before['quorumMin'] ?? 1));
		if ($quorum < 1 || $quorum > 99) {
			throw new ValidationException('The quorum must be between 1 and 99');
		}
		$seats = $data['requiredSeats'] ?? ($before['requiredSeats'] ?? []);
		$seats = array_values(array_unique(array_filter(array_map(static fn ($s) => trim((string)$s), is_array($seats) ? $seats : []), static fn ($s) => $s !== '')));
		$values = [
			'code' => $code,
			'name' => $name,
			'bodyType' => $type,
			'chairId' => $chair === '' ? null : $chair,
			'quorumMin' => $quorum,
			'requiredSeats' => $seats,
			'description' => trim((string)($data['description'] ?? ($before['description'] ?? ''))) ?: null,
			'active' => (bool)($data['active'] ?? ($before['active'] ?? 1)) ? 1 : 0,
		];
		if ($id === null) {
			$id = $this->rows->insert(self::BODIES, $values + ['createdBy' => $userId, 'createdAt' => $this->time->getTime()]);
			$this->audit->log('body', $id, 'create', $userId, $this->changes([], $values));
		} else {
			$this->rows->update(self::BODIES, $id, $values);
			$this->audit->log('body', $id, 'update', $userId, $this->changes($before, $values));
		}
		return $this->body($id);
	}

	/** @throws ForbiddenException|ValidationException|NotFoundException */
	public function deleteBody(string $userId, int $id): void {
		$this->requireManage($userId);
		$body = $this->body($id);
		if ($this->rows->where('nx_sessions', ['bodyId' => $id]) !== [] || $this->rows->where(self::RIGHTS, ['bodyId' => $id]) !== []) {
			throw new ValidationException('The body still has sessions or decision rights; deactivate it instead');
		}
		$this->rows->deleteWhere(self::MEMBERS, ['bodyId' => $id]);
		$this->rows->delete(self::BODIES, $id);
		$this->audit->log('body', $id, 'delete', $userId, $this->changes($body, []));
	}

	/**
	 * Mitglied hinzufügen oder ändern (Fachsitz, Stimmrecht, Stellvertretung).
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed> Gremium
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function saveMember(string $userId, int $bodyId, ?int $memberId, array $data): array {
		$this->requireManage($userId);
		$this->body($bodyId);
		$before = $memberId === null ? null : $this->rows->find(self::MEMBERS, $memberId);
		if ($memberId !== null && ($before === null || $before['bodyId'] !== $bodyId)) {
			throw new NotFoundException('Member not found');
		}
		$member = $before['userId'] ?? trim((string)($data['userId'] ?? ''));
		if (!$this->userManager->userExists($member)) {
			throw new ValidationException('Unknown account or group');
		}
		if ($before === null && $this->rows->where(self::MEMBERS, ['bodyId' => $bodyId, 'userId' => $member]) !== []) {
			throw new ValidationException('The account is already a member');
		}
		$deputy = trim((string)($data['deputyId'] ?? ''));
		if ($deputy !== '' && (!$this->userManager->userExists($deputy) || $deputy === $member)) {
			throw new ValidationException('Unknown account or group');
		}
		$values = [
			'seat' => trim((string)($data['seat'] ?? '')) ?: null,
			'voting' => (bool)($data['voting'] ?? true) ? 1 : 0,
			'deputyId' => $deputy === '' ? null : $deputy,
		];
		if ($before === null) {
			$memberId = $this->rows->insert(self::MEMBERS, $values + ['bodyId' => $bodyId, 'userId' => $member, 'createdBy' => $userId, 'createdAt' => $this->time->getTime()]);
			$this->audit->log('body', $bodyId, 'member_add', $userId, ['member' => [null, $member]] + $this->changes([], $values));
		} else {
			$this->rows->update(self::MEMBERS, (int)$memberId, $values);
			$this->audit->log('body', $bodyId, 'member_update', $userId, ['member' => [$member, $member]] + $this->changes($before, $values));
		}
		return $this->body($bodyId);
	}

	/**
	 * @return array<string, mixed> Gremium
	 * @throws ForbiddenException|NotFoundException
	 */
	public function removeMember(string $userId, int $bodyId, int $memberId): array {
		$this->requireManage($userId);
		$member = $this->rows->find(self::MEMBERS, $memberId);
		if ($member === null || $member['bodyId'] !== $bodyId) {
			throw new NotFoundException('Member not found');
		}
		$this->rows->delete(self::MEMBERS, $memberId);
		$this->audit->log('body', $bodyId, 'member_remove', $userId, ['member' => [$member['userId'], null]]);
		return $this->body($bodyId);
	}

	/**
	 * Gremien, in denen das Konto Mitglied oder Stellvertretung ist.
	 *
	 * @return list<int>
	 */
	public function bodiesOf(string $userId): array {
		$ids = [];
		foreach ($this->bodies() as $body) {
			foreach ($body['members'] as $m) {
				if ($m['userId'] === $userId || $m['deputyId'] === $userId) {
					$ids[] = $body['id'];
				}
			}
			if ($body['chairId'] === $userId) {
				$ids[] = $body['id'];
			}
		}
		return array_values(array_unique($ids));
	}

	// ---- Entscheidungsrechte ------------------------------------------------------------------

	/**
	 * Gültige Entscheidungsrechte (ohne Ende) und Anzahl früherer Versionen.
	 *
	 * @return array{rights: list<array<string, mixed>>, retired: int}
	 */
	public function rights(): array {
		$all = $this->rows->where(self::RIGHTS, [], 'gateCode');
		$current = array_values(array_filter($all, static fn (array $r) => $r['validTo'] === null));
		return ['rights' => $current, 'retired' => count($all) - count($current)];
	}

	/**
	 * Recht anlegen oder ändern. Eine Änderung beendet die alte Version und legt eine neue an.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function saveRight(string $userId, ?int $id, array $data): array {
		$this->requireManage($userId);
		$gate = (string)($data['gateCode'] ?? '');
		if (!in_array($gate, self::DECISION_TYPES, true)) {
			throw new ValidationException('Unknown gate');
		}
		$classes = $data['projectClasses'] ?? null;
		$classes = is_array($classes) && $classes !== [] ? array_values(array_intersect(TriageService::CLASSES, $classes)) : null;
		$bodyId = ($data['bodyId'] ?? '') !== '' ? (int)$data['bodyId'] : null;
		if ($bodyId !== null) {
			$this->body($bodyId);
		}
		$quorum = ($data['quorumMin'] ?? '') !== '' ? (int)$data['quorumMin'] : null;
		if ($quorum !== null && ($quorum < 1 || $quorum > 99)) {
			throw new ValidationException('The quorum must be between 1 and 99');
		}
		$now = $this->time->getTime();
		$before = null;
		if ($id !== null) {
			$before = $this->rows->find(self::RIGHTS, $id);
			if ($before === null || $before['validTo'] !== null) {
				throw new NotFoundException('Decision right not found');
			}
		}
		foreach ($this->rights()['rights'] as $other) {
			if ($other['id'] !== $id && $other['gateCode'] === $gate && $other['projectClasses'] === $classes) {
				throw new ValidationException('There is already a decision right for this gate and these classes');
			}
		}
		$values = ['gateCode' => $gate, 'projectClasses' => $classes, 'bodyId' => $bodyId, 'quorumMin' => $quorum];
		$this->db->beginTransaction();
		try {
			if ($before !== null) {
				$this->rows->update(self::RIGHTS, (int)$id, ['validTo' => $now]);
			}
			$newId = $this->rows->insert(self::RIGHTS, $values + ['validFrom' => $now, 'createdBy' => $userId, 'createdAt' => $now]);
			$this->audit->log('decision_right', $newId, $before === null ? 'create' : 'update', $userId, $this->changes($before ?? [], $values), $before === null ? null : 'replaces #' . $id);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return (array)$this->rows->find(self::RIGHTS, $newId);
	}

	/** @throws ForbiddenException|NotFoundException */
	public function endRight(string $userId, int $id): void {
		$this->requireManage($userId);
		$right = $this->rows->find(self::RIGHTS, $id);
		if ($right === null || $right['validTo'] !== null) {
			throw new NotFoundException('Decision right not found');
		}
		$this->rows->update(self::RIGHTS, $id, ['validTo' => $this->time->getTime()]);
		$this->audit->log('decision_right', $id, 'delete', $userId, $this->changes($right, []));
	}

	/**
	 * Zuständige Instanz für ein Gate: (Gate, Vorhabensklasse) → Gremium oder PMO.
	 * Ein Recht für die konkrete Klasse geht einem Recht für alle Klassen vor.
	 *
	 * @return array{kind: 'pmo'|'body', body: array<string, mixed>|null, quorumMin: int|null, rightId: int|null}
	 */
	public function deciderFor(string $gate, Demand $demand): array {
		$best = null;
		foreach ($this->rights()['rights'] as $right) {
			if ($right['gateCode'] !== $gate) {
				continue;
			}
			if ($right['projectClasses'] === null) {
				$best ??= $right;
			} elseif (in_array($demand->getProjectClass(), $right['projectClasses'], true)) {
				$best = $right;
				break;
			}
		}
		if ($best === null || $best['bodyId'] === null) {
			return ['kind' => 'pmo', 'body' => null, 'quorumMin' => null, 'rightId' => $best['id'] ?? null];
		}
		$body = $this->body((int)$best['bodyId']);
		return ['kind' => 'body', 'body' => $body, 'quorumMin' => $best['quorumMin'] ?? $body['quorumMin'], 'rightId' => $best['id']];
	}

	// ---- Gate-Sets ----------------------------------------------------------------------------

	/** @return list<array<string, mixed>> aktive Gate-Sets mit ihren Gates */
	public function gateSets(bool $withRetired = false): array {
		$this->ensureDefaults();
		$defs = [];
		foreach ($this->rows->where(self::DEFS, [], 'sort') as $d) {
			$defs[$d['setId']][] = $d;
		}
		$sets = [];
		foreach ($this->rows->where(self::SETS, [], 'name') as $set) {
			if ($withRetired || $set['status'] === 'active') {
				$sets[] = $set + ['gates' => $defs[$set['id']] ?? []];
			}
		}
		return $sets;
	}

	/**
	 * Gate-Set eines Vorhabens: das erste aktive Set, dessen Klassen (und ggf. Antragstypen) passen;
	 * sonst das Set ohne Klasseneinschränkung bzw. das erste.
	 *
	 * @return array<string, mixed>
	 */
	public function gateSetFor(Demand $demand): array {
		$sets = $this->gateSets();
		$fallback = null;
		foreach ($sets as $set) {
			$typeOk = empty($set['demandTypes']) || in_array($demand->getDemandType(), $set['demandTypes'], true);
			if (!$typeOk) {
				continue;
			}
			if (!empty($set['projectClasses']) && in_array($demand->getProjectClass(), $set['projectClasses'], true)) {
				return $set;
			}
			if (empty($set['projectClasses']) || $demand->getProjectClass() === null) {
				$fallback ??= $set;
			}
		}
		return $fallback ?? $sets[0];
	}

	/**
	 * Neue Version eines Gate-Sets (oder neues Set). G2 und G3 sind Pflicht, weil an ihnen das
	 * Vorhaben Projekt wird und die Kapazität verbindlich zugesagt wird.
	 *
	 * @param array<string, mixed> $data name, projectClasses, demandTypes, note, gates[{code, name, purpose, requiredChecks, budgetTranche}]
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function saveGateSet(string $userId, ?int $id, array $data): array {
		$this->requireManage($userId);
		$before = null;
		if ($id !== null) {
			$before = $this->rows->find(self::SETS, $id);
			if ($before === null || $before['status'] !== 'active') {
				throw new NotFoundException('Gate set not found');
			}
		}
		$name = trim((string)($data['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('A name is required');
		}
		$classes = is_array($data['projectClasses'] ?? null) ? array_values(array_intersect(TriageService::CLASSES, $data['projectClasses'])) : [];
		$types = is_array($data['demandTypes'] ?? null) ? array_values(array_intersect(DemandService::TYPES, $data['demandTypes'])) : [];
		$gates = [];
		foreach (is_array($data['gates'] ?? null) ? $data['gates'] : [] as $g) {
			$code = (string)($g['code'] ?? '');
			if (!in_array($code, self::GATES, true) || isset($gates[$code])) {
				throw new ValidationException('Unknown gate');
			}
			$checks = is_array($g['requiredChecks'] ?? null) ? array_values(array_intersect(self::CHECKS, $g['requiredChecks'])) : [];
			$gates[$code] = [
				'code' => $code,
				'name' => trim((string)($g['name'] ?? '')) ?: strtoupper($code),
				'purpose' => trim((string)($g['purpose'] ?? '')) ?: null,
				'sort' => array_search($code, self::GATES, true),
				'requiredChecks' => $checks,
				'budgetTranche' => (bool)($g['budgetTranche'] ?? false) ? 1 : 0,
			];
		}
		if (!isset($gates['g2'], $gates['g3'])) {
			throw new ValidationException('A gate set needs at least G2 and G3');
		}
		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			if ($before !== null) {
				$this->rows->update(self::SETS, (int)$id, ['status' => 'retired']);
			}
			$newId = $this->rows->insert(self::SETS, [
				'name' => $name, 'projectClasses' => $classes, 'demandTypes' => $types,
				'version' => ($before['version'] ?? 0) + 1, 'status' => 'active',
				'note' => trim((string)($data['note'] ?? '')) ?: null, 'createdBy' => $userId, 'createdAt' => $now,
			]);
			foreach ($gates as $gate) {
				$this->rows->insert(self::DEFS, $gate + ['setId' => $newId]);
			}
			$this->audit->log('gate_set', $newId, $before === null ? 'create' : 'update', $userId, [
				'name' => [$before['name'] ?? null, $name],
				'gates' => [null, implode(', ', array_map('strtoupper', array_keys($gates)))],
			], $before === null ? null : 'replaces #' . $id);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		foreach ($this->gateSets() as $set) {
			if ($set['id'] === $newId) {
				return $set;
			}
		}
		throw new NotFoundException('Gate set not found');
	}

	/** @throws ForbiddenException|ValidationException|NotFoundException */
	public function retireGateSet(string $userId, int $id): void {
		$this->requireManage($userId);
		$set = $this->rows->find(self::SETS, $id);
		if ($set === null || $set['status'] !== 'active') {
			throw new NotFoundException('Gate set not found');
		}
		if (count($this->gateSets()) < 2) {
			throw new ValidationException('At least one gate set must stay active');
		}
		$this->rows->update(self::SETS, $id, ['status' => 'retired']);
		$this->audit->log('gate_set', $id, 'delete', $userId, ['status' => ['active', 'retired']]);
	}

	/**
	 * Startmodell nach Kapitel 02 §3: Standard für M/L und verkürzter Pfad für XS/S.
	 * Läuft auch nach einer Neuinstallation (Migrationen dann nur als Schema).
	 */
	public function ensureDefaults(): void {
		if ($this->defaultsChecked) {
			return;
		}
		$this->defaultsChecked = true;
		if ($this->rows->where(self::SETS) !== []) {
			return;
		}
		$now = $this->time->getTime();
		$g2 = ['org_unit', 'assessment_review', 'business_case', 'resource_needs'];
		$sets = [
			['Standard', [], [
				['g2', 'G2 Portfolioaufnahme', 'Ist das Vorhaben im Vergleich zu anderen wertvoll genug?', $g2, 1],
				['g3', 'G3 Freigabe', 'Können wir es jetzt starten?', ['business_case', 'resource_needs', 'capacity', 'open_conditions'], 1],
				['g4', 'G4 Phasenfreigabe', 'Rechtfertigen die Erkenntnisse die nächste Investitionsstufe?', ['business_case', 'open_conditions'], 1],
				['g5', 'G5 Abnahme / Go-Live', 'Ist das Ergebnis übergabefähig?', ['open_conditions'], 0],
				['g6', 'G6 Nutzenreview', 'Ist der versprochene Nutzen eingetreten?', ['benefit_reviews', 'open_conditions'], 0],
			]],
			['Verkürzter Pfad', ['XS', 'S'], [
				['g2', 'G2 Portfolioaufnahme', 'Ist das Vorhaben im Vergleich zu anderen wertvoll genug?', $g2, 0],
				['g3', 'G3 Freigabe', 'Können wir es jetzt starten?', ['resource_needs', 'capacity', 'open_conditions'], 1],
				['g5', 'G5 Abnahme / Go-Live', 'Ist das Ergebnis übergabefähig?', ['open_conditions'], 0],
			]],
		];
		foreach ($sets as [$name, $classes, $gates]) {
			$setId = $this->rows->insert(self::SETS, [
				'name' => $name, 'projectClasses' => $classes, 'demandTypes' => [], 'version' => 1, 'status' => 'active',
				'note' => 'Startmodell aus dem Konzept (Kapitel 02 §3)', 'createdBy' => 'system', 'createdAt' => $now,
			]);
			foreach ($gates as [$code, $gateName, $purpose, $checks, $tranche]) {
				$this->rows->insert(self::DEFS, [
					'setId' => $setId, 'code' => $code, 'name' => $gateName, 'purpose' => $purpose,
					'sort' => array_search($code, self::GATES, true), 'requiredChecks' => $checks, 'budgetTranche' => $tranche,
				]);
			}
		}
	}

	/** @throws ForbiddenException */
	private function requireManage(string $userId): void {
		if (!$this->permissions->canManageOrg($userId)) {
			throw new ForbiddenException('Only the portfolio office may change the governance configuration');
		}
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	private function changes(array $before, array $after): array {
		$changes = [];
		foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
			if (in_array($key, ['id', 'members', 'createdBy', 'createdAt'], true)) {
				continue;
			}
			$old = $before[$key] ?? null;
			$new = $after[$key] ?? null;
			if (is_array($old)) {
				$old = implode(', ', $old);
			}
			if (is_array($new)) {
				$new = implode(', ', $new);
			}
			if ($old !== $new && !($old === '' && $new === null) && !($old === null && $new === '')) {
				$changes[$key] = [$old, $new];
			}
		}
		return $changes;
	}
}
