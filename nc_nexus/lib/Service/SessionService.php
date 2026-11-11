<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\Rows;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Gremienworkflow (DEC-002, Kapitel 06 §4.3): Sitzung → Tagesordnung aus entscheidungsreifen
 * Gates → Entscheidungsvorlagen → Abstimmung mit Quorum und Stellvertretung → Beschluss.
 *
 * Harte Regeln (Kapitel 06 §3): das PMO protokolliert, stimmt aber nicht für andere ab; eine Stimme
 * ist nach Sitzungsschluss unveränderlich; wer Antragsteller ist, stimmt nicht mit (Interessenkonflikt);
 * ein Beschluss braucht das Quorum der Entscheidungsrechte-Matrix.
 */
class SessionService {
	public const STATUSES = ['planned', 'agenda_final', 'held', 'minuted'];
	public const VOTES = ['yes', 'no', 'abstain'];
	private const SESSIONS = 'nx_sessions';
	private const ITEMS = 'nx_agenda_items';
	private const VOTE_TABLE = 'nx_votes';
	private const VIEW_ROLES = ['board_member', 'auditor', 'portfolio_manager'];

	public function __construct(
		private Rows $rows,
		private GovernanceService $governance,
		private GateService $gates,
		private ConditionService $conditions,
		private BusinessCaseService $businessCases,
		private RankingService $ranking,
		private DemandMapper $demands,
		private PermissionService $permissions,
		private CollabService $collab,
		private AuditService $audit,
		private ITimeFactory $time,
	) {
	}

	/** @return list<array<string, mixed>> Sitzungen, die das Konto sehen darf */
	public function list(string $userId): array {
		$bodies = [];
		foreach ($this->governance->bodies() as $b) {
			$bodies[$b['id']] = $b;
		}
		$result = [];
		$counts = [];
		foreach ($this->rows->where(self::ITEMS) as $item) {
			$counts[$item['sessionId']] = ($counts[$item['sessionId']] ?? 0) + 1;
		}
		foreach (array_reverse($this->rows->where(self::SESSIONS, [], 'scheduledAt')) as $s) {
			if (!$this->canView($userId, $s)) {
				continue;
			}
			$result[] = $s + [
				'bodyName' => $bodies[$s['bodyId']]['name'] ?? '?',
				'bodyCode' => $bodies[$s['bodyId']]['code'] ?? '?',
				'items' => $counts[$s['id']] ?? 0,
			];
		}
		return $result;
	}

	/**
	 * Sitzungsmappe: Sitzung, Gremium, Tagesordnung mit Vorlagen, Stimmen und Rechten.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException|ForbiddenException
	 */
	public function detail(string $userId, int $id): array {
		$session = $this->session($id);
		if (!$this->canView($userId, $session)) {
			throw new ForbiddenException('Only members of the body and governance roles may open the session');
		}
		$body = $this->governance->body((int)$session['bodyId']);
		$ranks = $this->ranks($userId);
		$votes = [];
		$itemIds = array_column($this->rows->where(self::ITEMS, ['sessionId' => $id]), 'id');
		foreach ($this->rows->where(self::VOTE_TABLE, ['itemId' => $itemIds], 'memberId') as $v) {
			$votes[$v['itemId']][] = $v;
		}
		$items = [];
		foreach ($this->rows->where(self::ITEMS, ['sessionId' => $id], 'position') as $item) {
			$items[] = $this->decorateItem($userId, $session, $body, $item, $votes[$item['id']] ?? [], $ranks);
		}
		$manage = $this->canManage($userId);
		$open = $session['status'] !== 'minuted';
		$followUp = array_values(array_filter(
			$this->conditions->list($userId),
			static fn (array $c) => $c['status'] === 'open' && $c['bodyId'] === $body['id'],
		));
		return $session + [
			'body' => $body,
			'items' => $items,
			'followUp' => $followUp,
			'readyCount' => count(array_filter($this->gates->readyFor($body['id']), fn (array $i) => !$this->onOpenAgenda((int)$i['id']))),
			'can' => [
				'manage' => $manage && $open,
				'edit' => $manage && in_array($session['status'], ['planned', 'agenda_final'], true),
				'finalize' => $manage && $session['status'] === 'planned',
				'start' => $manage && in_array($session['status'], ['planned', 'agenda_final'], true),
				'close' => $manage && $session['status'] === 'held',
				'delete' => $manage && $session['status'] === 'planned'
					&& !in_array('decided', array_column($items, 'status'), true),
			],
		];
	}

	/**
	 * @param array<string, mixed> $data bodyId, title, scheduledAt (JJJJ-MM-TTTHH:MM), durationMin, location, talk, calendar
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function create(string $userId, array $data): array {
		$this->requireManage($userId);
		$body = $this->governance->body((int)($data['bodyId'] ?? 0));
		$values = $this->validate($data, $body);
		$now = $this->time->getTime();
		$id = $this->rows->insert(self::SESSIONS, $values + ['bodyId' => $body['id'], 'status' => 'planned', 'createdBy' => $userId, 'createdAt' => $now]);
		$this->audit->log('session', $id, 'create', $userId, ['title' => [null, $values['title']], 'scheduledAt' => [null, $values['scheduledAt']]]);

		$people = array_values(array_unique(array_filter(array_merge(array_column($body['members'], 'userId'), array_column($body['members'], 'deputyId'), [$body['chairId'], $userId]))));
		$extra = [];
		if (!empty($data['talk'])) {
			$room = $this->collab->createRoom($values['title'], $people);
			if ($room !== null) {
				$extra['talkToken'] = $room['token'];
				$extra['talkUrl'] = $room['url'];
			}
		}
		if (!empty($data['calendar'])) {
			$description = $body['name'] . ($extra['talkUrl'] ?? '' ? "\nTalk: " . $extra['talkUrl'] : '');
			$uid = $this->collab->createEvent($userId, $values['title'], $values['scheduledAt'], $values['durationMin'], $values['location'], $description, $people);
			if ($uid !== null) {
				$extra['calendarUid'] = $uid;
			}
		}
		$this->rows->update(self::SESSIONS, $id, $extra);
		return $this->detail($userId, $id);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function update(string $userId, int $id, array $data): array {
		$session = $this->editable($userId, $id);
		$body = $this->governance->body((int)$session['bodyId']);
		$values = $this->validate($data + [
			'title' => $session['title'], 'scheduledAt' => date('Y-m-d\TH:i', (int)$session['scheduledAt']),
			'durationMin' => $session['durationMin'], 'location' => $session['location'],
		], $body);
		$this->rows->update(self::SESSIONS, $id, $values);
		$this->audit->log('session', $id, 'update', $userId, ['title' => [$session['title'], $values['title']], 'scheduledAt' => [$session['scheduledAt'], $values['scheduledAt']]]);
		return $this->detail($userId, $id);
	}

	/** @throws ForbiddenException|ValidationException|NotFoundException */
	public function delete(string $userId, int $id): void {
		$session = $this->session($id);
		$this->requireManage($userId);
		if ($session['status'] !== 'planned') {
			throw new ValidationException('Only planned sessions can be deleted');
		}
		foreach ($this->rows->where(self::ITEMS, ['sessionId' => $id]) as $item) {
			if ($item['status'] === 'decided') {
				throw new ValidationException('Only planned sessions can be deleted');
			}
			if ($item['gateInstanceId'] !== null) {
				$this->gates->setInstanceStatus((int)$item['gateInstanceId'], 'ready');
			}
			$this->rows->deleteWhere(self::VOTE_TABLE, ['itemId' => (int)$item['id']]);
		}
		$this->rows->deleteWhere(self::ITEMS, ['sessionId' => $id]);
		$this->rows->delete(self::SESSIONS, $id);
		if ($session['talkToken'] !== null) {
			$this->collab->deleteRoom((string)$session['talkToken']);
		}
		$this->audit->log('session', $id, 'delete', $userId, ['title' => [$session['title'], null]]);
	}

	/**
	 * Tagesordnung erzeugen: alle entscheidungsreifen (auch vertagten) Gates des Gremiums, die noch
	 * auf keiner offenen Tagesordnung stehen. Empfehlung und Optionen ergänzt das PMO danach.
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function generateAgenda(string $userId, int $id): array {
		$session = $this->editable($userId, $id);
		$position = count($this->rows->where(self::ITEMS, ['sessionId' => $id]));
		$added = 0;
		foreach ($this->gates->readyFor((int)$session['bodyId']) as $instance) {
			if ($this->onOpenAgenda((int)$instance['id'])) {
				continue;
			}
			try {
				$demand = $this->demands->find((int)$instance['demandId']);
			} catch (DoesNotExistException) {
				continue;
			}
			$this->rows->insert(self::ITEMS, [
				'sessionId' => $id, 'position' => ++$position, 'gateInstanceId' => $instance['id'], 'demandId' => $demand->getId(),
				'title' => strtoupper((string)$instance['gateCode']) . ' · ' . $demand->getTitle(),
				'options' => $this->defaultOptions((string)$instance['gateCode']), 'status' => 'open',
			]);
			$this->gates->setInstanceStatus((int)$instance['id'], 'scheduled');
			$added++;
		}
		$this->audit->log('session', $id, 'agenda', $userId, ['items' => [null, $added]]);
		return $this->detail($userId, $id);
	}

	/**
	 * Freier Tagesordnungspunkt (Bericht, Information) ohne Gate.
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function addTopic(string $userId, int $id, string $title): array {
		$this->editable($userId, $id);
		$title = trim($title);
		if ($title === '' || mb_strlen($title) > 255) {
			throw new ValidationException('A title is required');
		}
		$position = count($this->rows->where(self::ITEMS, ['sessionId' => $id]));
		$this->rows->insert(self::ITEMS, ['sessionId' => $id, 'position' => $position + 1, 'title' => $title, 'options' => [], 'status' => 'open']);
		return $this->detail($userId, $id);
	}

	/**
	 * Vorlage pflegen (Empfehlung, Optionen) oder Reihenfolge ändern (move: -1/+1).
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function updateItem(string $userId, int $itemId, array $data): array {
		$item = $this->item($itemId);
		$session = $this->editable($userId, (int)$item['sessionId']);
		if (isset($data['move'])) {
			$items = $this->rows->where(self::ITEMS, ['sessionId' => $session['id']], 'position');
			$index = array_search($itemId, array_column($items, 'id'), true);
			$target = (int)$index + ((int)$data['move'] < 0 ? -1 : 1);
			if ($index !== false && isset($items[$target])) {
				[$items[$index], $items[$target]] = [$items[$target], $items[$index]];
				foreach ($items as $pos => $row) {
					$this->rows->update(self::ITEMS, (int)$row['id'], ['position' => $pos + 1]);
				}
			}
			return $this->detail($userId, (int)$session['id']);
		}
		$values = [];
		if (array_key_exists('recommendation', $data)) {
			$values['recommendation'] = trim((string)$data['recommendation']) ?: null;
		}
		if (array_key_exists('options', $data) && $item['gateInstanceId'] !== null) {
			$instance = $this->instance((int)$item['gateInstanceId']);
			$allowed = $this->defaultOptions((string)$instance['gateCode']);
			$values['options'] = array_values(array_intersect($allowed, is_array($data['options']) ? $data['options'] : []));
		}
		$this->rows->update(self::ITEMS, $itemId, $values);
		$this->audit->log('session', (int)$session['id'], 'agenda_item', $userId, ['recommendation' => [$item['recommendation'], $values['recommendation'] ?? $item['recommendation']]]);
		return $this->detail($userId, (int)$session['id']);
	}

	/**
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function removeItem(string $userId, int $itemId): array {
		$item = $this->item($itemId);
		$session = $this->editable($userId, (int)$item['sessionId']);
		if ($item['status'] === 'decided') {
			throw new ValidationException('Decided items stay on the agenda');
		}
		if ($item['gateInstanceId'] !== null) {
			$this->gates->setInstanceStatus((int)$item['gateInstanceId'], 'ready');
		}
		$this->rows->deleteWhere(self::VOTE_TABLE, ['itemId' => $itemId]);
		$this->rows->delete(self::ITEMS, $itemId);
		return $this->detail($userId, (int)$session['id']);
	}

	/**
	 * Status weiterschalten: agenda_final (Tagesordnung steht, Mappe freigeschaltet), held (Sitzung
	 * läuft), minuted (Protokoll; offene Punkte werden vertagt, Stimmen unveränderlich).
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function setStatus(string $userId, int $id, string $status, ?string $minutes): array {
		$session = $this->session($id);
		$this->requireManage($userId);
		$from = (string)$session['status'];
		$allowed = ['planned' => ['agenda_final', 'held'], 'agenda_final' => ['held', 'planned'], 'held' => ['minuted']][$from] ?? [];
		if (!in_array($status, $allowed, true)) {
			throw new ValidationException('This status change is not possible');
		}
		$values = ['status' => $status];
		if ($status === 'minuted') {
			$values['minutes'] = trim((string)$minutes) ?: null;
			$values['closedAt'] = $this->time->getTime();
			foreach ($this->rows->where(self::ITEMS, ['sessionId' => $id]) as $item) {
				if ($item['status'] === 'open') {
					$this->rows->update(self::ITEMS, (int)$item['id'], ['status' => 'adjourned']);
					if ($item['gateInstanceId'] !== null) {
						$this->gates->setInstanceStatus((int)$item['gateInstanceId'], 'adjourned');
					}
				}
			}
		}
		$this->rows->update(self::SESSIONS, $id, $values);
		$this->audit->log('session', $id, 'status_change', $userId, ['status' => [$from, $status]]);
		return $this->detail($userId, $id);
	}

	/**
	 * Stimme abgeben oder ändern – als Mitglied selbst oder als Stellvertretung für ein Mitglied.
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function vote(string $userId, int $itemId, ?string $memberId, string $vote, bool $conflict, ?string $comment): array {
		$item = $this->item($itemId);
		$session = $this->session((int)$item['sessionId']);
		if (!in_array($session['status'], ['agenda_final', 'held'], true) || $item['status'] !== 'open' || $item['gateInstanceId'] === null) {
			throw new ValidationException('Voting is not open for this item');
		}
		if (!in_array($vote, self::VOTES, true)) {
			throw new ValidationException('Unknown vote');
		}
		$body = $this->governance->body((int)$session['bodyId']);
		$memberId = $memberId ?: $userId;
		$member = null;
		foreach ($body['members'] as $m) {
			if ($m['userId'] === $memberId) {
				$member = $m;
			}
		}
		if ($member === null || $member['voting'] !== 1) {
			throw new ForbiddenException('Only voting members of the body may vote');
		}
		if ($memberId !== $userId && $member['deputyId'] !== $userId) {
			throw new ForbiddenException('You may only vote for yourself or as deputy');
		}
		$demand = $item['demandId'] === null ? null : $this->demands->find((int)$item['demandId']);
		// Funktionstrennung: wer den Antrag gestellt hat, stimmt nicht mit (Interessenkonflikt)
		if ($demand !== null && $demand->getRequestorId() === $memberId) {
			$conflict = true;
		}
		if ($conflict) {
			$vote = 'abstain';
		}
		$now = $this->time->getTime();
		$existing = $this->rows->where(self::VOTE_TABLE, ['itemId' => $itemId, 'memberId' => $memberId])[0] ?? null;
		$values = ['vote' => $vote, 'conflict' => $conflict ? 1 : 0, 'comment' => trim((string)$comment) ?: null, 'castBy' => $userId];
		if ($existing === null) {
			$this->rows->insert(self::VOTE_TABLE, $values + ['itemId' => $itemId, 'memberId' => $memberId, 'createdBy' => $userId, 'createdAt' => $now]);
		} else {
			$this->rows->update(self::VOTE_TABLE, (int)$existing['id'], $values);
		}
		$this->audit->log('session', (int)$session['id'], 'vote', $userId, ['vote' => [$existing['vote'] ?? null, $vote]], $memberId !== $userId ? 'on behalf of ' . $memberId : null);
		return $this->detail($userId, (int)$session['id']);
	}

	/**
	 * Beschluss zu einem Tagesordnungspunkt protokollieren (PMO oder Vorsitz). Prüft Quorum,
	 * Pflichtsitze und Mehrheit; das Gate wird mit dem Gremium als Mandat entschieden.
	 *
	 * @param array<string, mixed> $data outcome, reason, resubmissionDate, conditions, tranche
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function decide(string $userId, int $itemId, array $data): array {
		$item = $this->item($itemId);
		$session = $this->session((int)$item['sessionId']);
		$body = $this->governance->body((int)$session['bodyId']);
		if (!$this->canRecord($userId, $body)) {
			throw new ForbiddenException('Only the portfolio office or the chair records decisions');
		}
		if ($session['status'] !== 'held') {
			throw new ValidationException('Decisions are recorded while the session is held');
		}
		if ($item['status'] !== 'open' || $item['gateInstanceId'] === null) {
			throw new ValidationException('This item cannot be decided');
		}
		$instance = $this->instance((int)$item['gateInstanceId']);
		$demand = $this->demands->find((int)$item['demandId']);
		$votes = $this->rows->where(self::VOTE_TABLE, ['itemId' => $itemId]);
		$quorum = $this->quorum($body, $demand, (string)$instance['gateCode'], $votes);
		if (!$quorum['ok']) {
			throw new ValidationException('The quorum is not met: %1$s of %2$s votes', [(string)$quorum['present'], (string)$quorum['required']]);
		}
		if ($quorum['missingSeats'] !== []) {
			throw new ValidationException('Required seats have not voted: %1$s', [implode(', ', $quorum['missingSeats'])]);
		}
		$outcome = (string)($data['outcome'] ?? '');
		if (in_array($outcome, ['approved', 'approved_with_conditions'], true) && $quorum['tally']['yes'] <= $quorum['tally']['no']) {
			throw new ValidationException('An approval needs more yes than no votes');
		}
		// Das Gate läuft in einer eigenen Transaktion; danach den Punkt als entschieden markieren
		$decision = $this->gates->decideInSession($userId, $demand, (string)$instance['gateCode'], $data, $body, [
			'session' => $session['title'],
			'votes' => $quorum['tally'],
			'recommendation' => $item['recommendation'],
		]);
		$this->rows->update(self::ITEMS, $itemId, ['status' => 'decided', 'decisionId' => $decision->getId()]);
		return $this->detail($userId, (int)$session['id']);
	}

	/**
	 * Punkt vertagen: das Gate bleibt entscheidungsreif und kommt auf die nächste Tagesordnung.
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	public function adjourn(string $userId, int $itemId): array {
		$item = $this->item($itemId);
		$session = $this->session((int)$item['sessionId']);
		if (!$this->canRecord($userId, $this->governance->body((int)$session['bodyId']))) {
			throw new ForbiddenException('Only the portfolio office or the chair records decisions');
		}
		if ($item['status'] !== 'open' || $session['status'] === 'minuted') {
			throw new ValidationException('This item cannot be decided');
		}
		$this->rows->update(self::ITEMS, $itemId, ['status' => 'adjourned']);
		if ($item['gateInstanceId'] !== null) {
			$this->gates->setInstanceStatus((int)$item['gateInstanceId'], 'adjourned');
		}
		$this->audit->log('session', (int)$session['id'], 'adjourn', $userId, ['item' => [null, $item['title']]]);
		return $this->detail($userId, (int)$session['id']);
	}

	// ---- intern -------------------------------------------------------------------------------

	/**
	 * Quorum nach Entscheidungsrechte-Matrix (sonst Standard des Gremiums): anwesend ist, wer
	 * abgestimmt hat (auch Enthaltung, auch in Stellvertretung); Pflichtsitze müssen vertreten sein.
	 *
	 * @param array<string, mixed> $body
	 * @param list<array<string, mixed>> $votes
	 * @return array{ok: bool, present: int, required: int, missingSeats: list<string>, tally: array{yes: int, no: int, abstain: int, conflict: int}}
	 */
	private function quorum(array $body, ?Demand $demand, string $gate, array $votes): array {
		$required = (int)$body['quorumMin'];
		if ($demand !== null && in_array($gate, GovernanceService::GATES, true)) {
			$decider = $this->governance->deciderFor($gate, $demand);
			$required = (int)($decider['quorumMin'] ?? $required);
		}
		$voting = [];
		foreach ($body['members'] as $m) {
			if ($m['voting'] === 1) {
				$voting[$m['userId']] = $m;
			}
		}
		$tally = ['yes' => 0, 'no' => 0, 'abstain' => 0, 'conflict' => 0];
		$seats = [];
		$present = 0;
		foreach ($votes as $v) {
			if (!isset($voting[$v['memberId']])) {
				continue;
			}
			$present++;
			$tally[$v['conflict'] === 1 ? 'conflict' : $v['vote']]++;
			if ($voting[$v['memberId']]['seat'] !== null) {
				$seats[] = $voting[$v['memberId']]['seat'];
			}
		}
		$missing = array_values(array_diff($body['requiredSeats'] ?? [], $seats));
		return ['ok' => $present >= $required, 'present' => $present, 'required' => $required, 'missingSeats' => $missing, 'tally' => $tally];
	}

	/**
	 * Entscheidungsvorlage auf einer Bildschirmseite (Kapitel 06 §2.5).
	 *
	 * @param array<string, mixed> $session
	 * @param array<string, mixed> $body
	 * @param array<string, mixed> $item
	 * @param list<array<string, mixed>> $votes
	 * @param array<int, int> $ranks
	 * @return array<string, mixed>
	 */
	private function decorateItem(string $userId, array $session, array $body, array $item, array $votes, array $ranks): array {
		$demand = null;
		if ($item['demandId'] !== null) {
			try {
				$demand = $this->demands->find((int)$item['demandId']);
			} catch (DoesNotExistException) {
			}
		}
		$instance = $item['gateInstanceId'] === null ? null : $this->rows->find(GateService::INSTANCES, (int)$item['gateInstanceId']);
		$gate = $instance['gateCode'] ?? null;
		$brief = null;
		if ($demand !== null && ($this->permissions->canReadDemand($userId, $demand) || in_array($body['id'], $this->governance->bodiesOf($userId), true))) {
			$bc = null;
			try {
				$bc = $this->businessCases->get($this->readerFor($userId, $demand), $demand->getId());
			} catch (\Throwable) {
			}
			$brief = [
				'demandId' => $demand->getId(),
				'key' => $demand->getProjectKey() ?? $demand->getDemandKey(),
				'title' => $demand->getTitle(),
				'status' => $demand->getStatus(),
				'demandType' => $demand->getDemandType(),
				'projectClass' => $demand->getProjectClass(),
				'requestorId' => $demand->getRequestorId(),
				'problemStatement' => $demand->getProblemStatement(),
				'expectedBenefit' => $demand->getExpectedBenefit(),
				'kpis' => $bc['kpis'] ?? null,
				'rank' => $ranks[$demand->getId()] ?? null,
				'checks' => $gate === null ? [] : $this->gates->checks($demand, (string)$gate),
				'conditions' => array_values(array_filter($this->conditions->forDemand($userId, $demand), static fn (array $c) => $c['status'] === 'open')),
			];
		}
		$quorum = $this->quorum($body, $demand, (string)$gate, $votes);
		$canVoteFor = [];
		$open = in_array($session['status'], ['agenda_final', 'held'], true) && $item['status'] === 'open' && $gate !== null;
		if ($open) {
			foreach ($body['members'] as $m) {
				if ($m['voting'] === 1 && ($m['userId'] === $userId || $m['deputyId'] === $userId)) {
					$canVoteFor[] = $m['userId'];
				}
			}
		}
		$decision = null;
		if ($item['decisionId'] !== null) {
			foreach ($this->rows->where('nx_decisions', ['id' => (int)$item['decisionId']]) as $d) {
				$decision = ['key' => $d['decisionKey'], 'outcome' => $d['outcome'], 'rationale' => $d['rationale']];
			}
		}
		return $item + [
			'gateCode' => $gate,
			'brief' => $brief,
			'votes' => array_map(static fn (array $v) => array_intersect_key($v, array_flip(['memberId', 'castBy', 'vote', 'conflict', 'comment'])), $votes),
			'quorum' => $quorum,
			'decision' => $decision,
			'can' => [
				'voteFor' => $canVoteFor,
				'decide' => $open && $session['status'] === 'held' && $this->canRecord($userId, $body),
				'adjourn' => $item['status'] === 'open' && $session['status'] !== 'minuted' && $this->canRecord($userId, $body),
			],
		];
	}

	/** Lesekonto für Vorlagendaten: Gremiumsmitglieder sehen die Vorlage auch ohne Rolle im Bereich. */
	private function readerFor(string $userId, Demand $demand): string {
		return $this->permissions->canReadDemand($userId, $demand) ? $userId : $demand->getRequestorId();
	}

	/** @return array<int, int> Vorhaben → Rang in seinem Bucket */
	private function ranks(string $userId): array {
		$ranks = [];
		try {
			foreach ($this->ranking->ranking($userId) as $bucket) {
				foreach ($bucket['items'] as $item) {
					$ranks[$item['id']] = $item['rank'];
				}
			}
		} catch (\Throwable) {
		}
		return $ranks;
	}

	/** @return list<string> */
	private function defaultOptions(string $gate): array {
		return match ($gate) {
			'g2', 'g3' => ['approved', 'approved_with_conditions', 'rework', 'deferred', 'rejected'],
			'g4' => ['approved', 'approved_with_conditions', 'rework', 'deferred', 'cancelled'],
			default => ['approved', 'approved_with_conditions', 'rework', 'rejected'],
		};
	}

	private function onOpenAgenda(int $instanceId): bool {
		foreach ($this->rows->where(self::ITEMS, ['gateInstanceId' => $instanceId]) as $item) {
			if ($item['status'] === 'open') {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $body
	 * @return array{title: string, scheduledAt: int, durationMin: int, location: string|null}
	 * @throws ValidationException
	 */
	private function validate(array $data, array $body): array {
		$title = trim((string)($data['title'] ?? ''));
		$when = (string)($data['scheduledAt'] ?? '');
		$ts = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $when) ? strtotime($when) : false;
		if ($ts === false) {
			throw new ValidationException('Please enter date and time');
		}
		if ($title === '') {
			$title = $body['name'] . ' ' . date('d.m.Y', $ts);
		}
		if (mb_strlen($title) > 255) {
			throw new ValidationException('A title is required');
		}
		$duration = (int)($data['durationMin'] ?? 90);
		if ($duration < 15 || $duration > 600) {
			throw new ValidationException('The duration must be between 15 and 600 minutes');
		}
		$location = trim((string)($data['location'] ?? ''));
		return ['title' => $title, 'scheduledAt' => $ts, 'durationMin' => $duration, 'location' => $location === '' ? null : $location];
	}

	/**
	 * @param array<string, mixed> $session
	 */
	private function canView(string $userId, array $session): bool {
		return $this->canManage($userId)
			|| in_array($session['bodyId'], $this->governance->bodiesOf($userId), true)
			|| $this->permissions->hasRole($userId, self::VIEW_ROLES, null);
	}

	private function canManage(string $userId): bool {
		return $this->permissions->canManageOrg($userId);
	}

	/** @param array<string, mixed> $body */
	private function canRecord(string $userId, array $body): bool {
		return $this->canManage($userId) || $body['chairId'] === $userId;
	}

	/** @throws ForbiddenException */
	private function requireManage(string $userId): void {
		if (!$this->canManage($userId)) {
			throw new ForbiddenException('Only the portfolio office may prepare sessions');
		}
	}

	/**
	 * @return array<string, mixed>
	 * @throws ForbiddenException|ValidationException|NotFoundException
	 */
	private function editable(string $userId, int $id): array {
		$session = $this->session($id);
		$this->requireManage($userId);
		if (!in_array($session['status'], ['planned', 'agenda_final', 'held'], true)) {
			throw new ValidationException('The session is already closed');
		}
		return $session;
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	private function session(int $id): array {
		return $this->rows->find(self::SESSIONS, $id) ?? throw new NotFoundException('Session not found');
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	private function item(int $id): array {
		return $this->rows->find(self::ITEMS, $id) ?? throw new NotFoundException('Agenda item not found');
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	private function instance(int $id): array {
		return $this->rows->find(GateService::INSTANCES, $id) ?? throw new NotFoundException('Gate not found');
	}
}
