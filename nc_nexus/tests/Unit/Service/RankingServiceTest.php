<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Service\RankingService;
use PHPUnit\Framework\TestCase;

class RankingServiceTest extends TestCase {
	/** @return array<string, mixed> */
	private function item(int $id, ?float $density, ?int $override = null, ?string $deadline = null, int $effort = 3): array {
		return [
			'id' => $id,
			'deadline' => $deadline,
			'rankOverride' => $override,
			'assessment' => $density === null ? null : ['density' => $density, 'effort' => $effort],
		];
	}

	/** @param list<array<string, mixed>> $items @return list<int> */
	private function ids(array $items): array {
		return array_column($items, 'id');
	}

	public function testScoredBucketRanksByValueDensity(): void {
		// Beispiel aus Kapitel 03: Rechnungsworkflow 2,12 · Self-Service 1,95 · CRM 1,03
		$ranked = RankingService::rank([$this->item(1, 1.03), $this->item(2, 2.12), $this->item(3, 1.95), $this->item(4, null)], true);
		$this->assertSame([2, 3, 1, 4], $this->ids($ranked));
		$this->assertSame([1, 2, 3, 4], array_column($ranked, 'rank'));
	}

	public function testMandatoryBucketRanksByDeadlineThenEffort(): void {
		$ranked = RankingService::rank([
			$this->item(1, 0.0, null, '2027-06-30', 3),
			$this->item(2, 0.0, null, '2027-01-31', 8),
			$this->item(3, 0.0, null, '2027-01-31', 1),
			$this->item(4, 0.0, null, null, 1),
		], false);
		$this->assertSame([3, 2, 1, 4], $this->ids($ranked));
	}

	public function testBoardOverrideMovesAnItemAndKeepsTheComputedRank(): void {
		$ranked = RankingService::rank([$this->item(1, 3.0), $this->item(2, 2.0), $this->item(3, 1.0, 1)], true);
		$this->assertSame([3, 1, 2], $this->ids($ranked));
		$this->assertSame(3, $ranked[0]['computedRank']);
		$this->assertSame(1, $ranked[0]['rank']);
	}
}
