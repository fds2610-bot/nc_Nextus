<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Migration\Version001000Date20261112120000;
use OCA\Nexus\Service\AssessmentService;
use PHPUnit\Framework\TestCase;

class AssessmentServiceTest extends TestCase {
	/** @return list<array<string, mixed>> */
	private function criteria(): array {
		return array_map(static fn (array $c) => ['code' => $c[0], 'name' => $c[1], 'dimension' => $c[2], 'weight' => (float)$c[3]], Version001000Date20261112120000::defaultCriteria());
	}

	public function testDefaultWeightsAddUpTo100(): void {
		$this->assertSame(100.0, array_sum(array_column($this->criteria(), 'weight')));
	}

	public function testValueDensityFollowsChapter03(): void {
		// Nutzen 5,2 · Dringlichkeit 3 · Aufwand 3 · Risiko 2,0 → (5,2 × 1,375) / (3 × 1,125) = 2,12
		$criteria = [
			['code' => 'N', 'dimension' => 'benefit', 'weight' => 65.0],
			['code' => 'Z1', 'dimension' => 'urgency', 'weight' => 0.0],
			['code' => 'Z2', 'dimension' => 'urgency', 'weight' => 0.0],
			['code' => 'R1', 'dimension' => 'risk', 'weight' => 0.0],
			['code' => 'R2', 'dimension' => 'risk', 'weight' => 0.0],
		];
		$result = AssessmentService::compute($criteria, ['N' => 8, 'Z1' => 3, 'Z2' => 1, 'R1' => 3, 'R2' => 1], 3);
		$this->assertSame(5.2, $result['benefit']);
		$this->assertSame(3.0, $result['urgency']); // Maximum, nicht Mittelwert
		$this->assertSame(2.0, $result['risk']);
		$this->assertSame(2.12, $result['density']);
	}

	public function testMandatoryBucketWorksWithoutBenefitScores(): void {
		$result = AssessmentService::compute($this->criteria(), ['Z1' => 8, 'Z2' => 0, 'R1' => 1, 'R2' => 1, 'R3' => 1, 'R4' => 1], 3);
		$this->assertSame(0.0, $result['benefit']);
		$this->assertSame(8.0, $result['urgency']);
	}

	public function testReviewSupersedesSelfAssessmentButKeepsBothVisible(): void {
		$current = AssessmentService::current([
			['kind' => 'self', 'density' => 2.4],
			['kind' => 'review', 'density' => 1.6],
			['kind' => 'self', 'density' => 2.8],
		]);
		$this->assertSame('review', $current['kind']);
		$this->assertSame(2.8, $current['selfDensity']);
		$this->assertSame(1.6, $current['reviewDensity']);
		$this->assertNull(AssessmentService::current([]));
	}

	public function testEffortClassFromPersonDays(): void {
		$this->assertSame([1, 3, 5, 8], array_map(AssessmentService::effortForDays(...), [4.0, 29.0, 199.0, 200.0]));
	}
}
