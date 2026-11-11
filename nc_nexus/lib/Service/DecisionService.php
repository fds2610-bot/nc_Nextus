<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\CounterMapper;
use OCA\Nexus\Db\Decision;
use OCA\Nexus\Db\DecisionMapper;
use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Entscheidungsregister (DEC-001): jeder Beschluss mit Mandat, Begründung und Details,
 * unveränderlich; ein erneuter Beschluss zum selben Antrag ersetzt den vorigen (supersedes).
 */
class DecisionService {
	public function __construct(
		private DecisionMapper $decisions,
		private DemandMapper $demands,
		private CounterMapper $counters,
		private PermissionService $permissions,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Triage-Entscheidung festhalten. Innerhalb der Transaktion der Triage aufrufen.
	 *
	 * @param array<string, mixed> $details
	 */
	public function recordTriage(string $userId, Demand $demand, string $code, ?string $rationale, array $details, bool $outOfSequence): Decision {
		return $this->record($userId, $demand, 'triage', $code, $rationale, $details, $outOfSequence);
	}

	/**
	 * Beschluss festhalten (Triage, G2, G3 …). Innerhalb der Transaktion des Vorgangs aufrufen.
	 *
	 * @param array<string, mixed> $details
	 * @param array{role: string, scopeType: string, scopeId: int|null}|null $mandate Gremium als Mandat (Sitzung), sonst das PMO-Mandat des Kontos
	 */
	public function record(string $userId, Demand $demand, string $type, string $outcome, ?string $rationale, array $details, bool $outOfSequence = false, ?array $mandate = null): Decision {
		$now = $this->time->getTime();
		$mandate ??= $this->permissions->mandateFor($userId, $demand);
		$previous = $this->decisions->findLatest(DemandService::ENTITY, $demand->getId(), $demand->getCreatedAt());
		$sequence = $this->counters->next('decision-' . date('Y', $now));

		$d = new Decision();
		$d->setDecisionKey('BES-' . date('Y', $now) . '-' . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT));
		$d->setSubjectType(DemandService::ENTITY);
		$d->setSubjectId($demand->getId());
		$d->setDecisionType($type);
		$d->setOutcome($outcome);
		$d->setRationale($rationale);
		$details = array_filter($details, static fn ($v) => $v !== null && $v !== '');
		$d->setDetails($details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$d->setMandateRole($mandate['role']);
		$d->setMandateScopeType($mandate['scopeType']);
		$d->setMandateScopeId($mandate['scopeId']);
		$d->setOutOfSequence($outOfSequence ? 1 : 0);
		$d->setSupersedesId($previous?->getId());
		$d->setDecidedBy($userId);
		$d->setDecidedAt($now);
		return $this->decisions->insert($d);
	}

	/**
	 * Register: alle Beschlüsse zu Anträgen, die das Konto lesen darf, mit Antragsbezug.
	 *
	 * @return list<array{decision: Decision, demand: Demand}>
	 */
	public function register(string $userId): array {
		$demands = [];
		foreach ($this->demands->findAll() as $demand) {
			$demands[$demand->getId()] = $demand;
		}
		$result = [];
		foreach ($this->decisions->findAll() as $decision) {
			$demand = $demands[$decision->getSubjectId()] ?? null;
			// Beschlüsse vor dem Anlegen des Antrags gehören zu einer früher vergebenen ID (SQLite)
			if ($demand === null || $decision->getDecidedAt() < $demand->getCreatedAt()
				|| !$this->permissions->canReadDemand($userId, $demand)) {
				continue;
			}
			$result[] = ['decision' => $decision, 'demand' => $demand];
		}
		return $result;
	}
}
