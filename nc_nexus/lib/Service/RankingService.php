<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\ResourceNeedMapper;
use OCA\Nexus\Db\ScoringMapper;
use OCA\Nexus\Exception\ForbiddenException;
use OCA\Nexus\Exception\NotFoundException;
use OCA\Nexus\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Rangliste je Bucket (Kapitel 03 §3): Wertdichte absteigend; im Pflicht-Bucket nach Frist, dann
 * nach Aufwand. Das Board darf den Rang übersteuern – als eigenes, begründetes Feld, das in der
 * Liste sichtbar bleibt.
 */
class RankingService {
	/** Status, die um Kapazität konkurrieren */
	public const RANKED = ['in_qualification', 'candidate', 'prioritized'];
	private const OVERRIDE_ROLES = ['board_member', 'portfolio_manager', 'admin'];

	public function __construct(
		private ScoringMapper $scoring,
		private AssessmentService $assessments,
		private DemandService $demands,
		private DemandMapper $demandMapper,
		private ResourceNeedMapper $needs,
		private CapacityService $capacity,
		private PermissionService $permissions,
		private AuditService $audit,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return list<array<string, mixed>> je Bucket: Stammdaten und Rangliste
	 * @throws ForbiddenException
	 */
	public function ranking(string $userId): array {
		if (!$this->capacity->canSee($userId)) {
			throw new ForbiddenException('Only governance roles may see the ranking');
		}
		$all = $this->scoring->assessments();
		$days = [];
		foreach ($this->needs->findRange('0000-00', '9999-12') as $need) {
			$days[$need->getDemandId()][] = $need;
		}
		$byBucket = [];
		foreach ($this->demands->findAll($userId) as $demand) {
			if (!in_array($demand->getStatus(), self::RANKED, true)) {
				continue;
			}
			$current = AssessmentService::current($this->assessments->valid($demand, $all));
			$total = 0.0;
			foreach ($days[$demand->getId()] ?? [] as $need) {
				if ($need->getUpdatedAt() >= $demand->getCreatedAt()) {
					$total += $need->getPersonDays();
				}
			}
			$bucket = $demand->getBucket() ?? AssessmentService::BUCKET_OF_TYPE[$demand->getDemandType()] ?? 'C';
			$byBucket[$bucket][] = [
				'id' => $demand->getId(),
				'key' => $demand->getDemandKey(),
				'title' => $demand->getTitle(),
				'status' => $demand->getStatus(),
				'orgUnitId' => $demand->getOrgUnitId(),
				'deadline' => $demand->getDeadline(),
				'personDays' => round($total, 1),
				'assessment' => $current === null ? null : array_intersect_key($current, array_flip(
					['kind', 'benefit', 'urgency', 'risk', 'effort', 'density', 'estimateQuality', 'selfDensity', 'reviewDensity', 'createdAt'])),
				'rankOverride' => $demand->getRankOverride(),
				'overrideReason' => $demand->getOverrideReason(),
				'canOverride' => $this->canOverride($userId, $demand),
			];
		}
		$result = [];
		foreach ($this->scoring->buckets() as $bucket) {
			$result[] = $bucket + ['items' => self::rank($byBucket[$bucket['code']] ?? [], $bucket['scored'])];
		}
		return $result;
	}

	/**
	 * Reihenfolge berechnen und Board-Korrekturen einsetzen.
	 *
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public static function rank(array $items, bool $scored): array {
		usort($items, static function (array $a, array $b) use ($scored): int {
			// unbewertete Vorhaben ans Ende
			if (($a['assessment'] === null) !== ($b['assessment'] === null)) {
				return $a['assessment'] === null ? 1 : -1;
			}
			if (!$scored) {
				// Pflicht: nach Frist (ohne Frist zuletzt), dann kurze zuerst
				$byDeadline = ($a['deadline'] ?? '9999-12-31') <=> ($b['deadline'] ?? '9999-12-31');
				return $byDeadline ?: (($a['assessment']['effort'] ?? 9) <=> ($b['assessment']['effort'] ?? 9)) ?: ($a['id'] <=> $b['id']);
			}
			return (($b['assessment']['density'] ?? 0) <=> ($a['assessment']['density'] ?? 0)) ?: ($a['id'] <=> $b['id']);
		});
		foreach ($items as $i => $item) {
			$items[$i]['computedRank'] = $i + 1;
		}
		// Korrekturen: übersteuerte Vorhaben an ihre Position setzen
		$fixed = array_values(array_filter($items, static fn (array $i) => $i['rankOverride'] !== null));
		$free = array_values(array_filter($items, static fn (array $i) => $i['rankOverride'] === null));
		usort($fixed, static fn (array $a, array $b) => ($a['rankOverride'] <=> $b['rankOverride']) ?: ($a['computedRank'] <=> $b['computedRank']));
		foreach ($fixed as $item) {
			array_splice($free, max(0, min(count($free), $item['rankOverride'] - 1)), 0, [$item]);
		}
		foreach ($free as $i => $item) {
			$free[$i]['rank'] = $i + 1;
		}
		return $free;
	}

	/**
	 * Board-Korrektur setzen oder (rank = null) zurücknehmen.
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationException
	 */
	public function override(string $userId, int $demandId, ?int $rank, ?string $reason): Demand {
		$demand = $this->demands->findReadable($userId, $demandId);
		if (!$this->canOverride($userId, $demand)) {
			throw new ForbiddenException('Only the board or the portfolio office may override the rank');
		}
		if (!in_array($demand->getStatus(), self::RANKED, true)) {
			throw new ForbiddenException('The demand is not part of the ranking');
		}
		$reason = trim((string)$reason);
		if ($rank !== null && ($rank < 1 || $reason === '')) {
			throw new ValidationException('A rank override needs a rank and a reason');
		}
		$before = [$demand->getRankOverride(), $demand->getOverrideReason()];
		$demand->setRankOverride($rank);
		$demand->setOverrideReason($rank === null ? null : $reason);
		$demand->setUpdatedAt($this->time->getTime());
		$demand = $this->demandMapper->update($demand);
		$this->audit->log(DemandService::ENTITY, $demandId, 'rank_override', $userId, [
			'rankOverride' => [$before[0], $rank],
			'overrideReason' => [$before[1], $demand->getOverrideReason()],
		], $rank === null ? null : $reason);
		return $demand;
	}

	private function canOverride(string $userId, Demand $demand): bool {
		return $this->permissions->isAdmin($userId)
			|| $this->permissions->hasRole($userId, self::OVERRIDE_ROLES, $demand->getOrgUnitId());
	}
}
