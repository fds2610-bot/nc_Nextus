<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Service;

use OCA\Nexus\Service\PlanningService;
use PHPUnit\Framework\TestCase;

class PlanningServiceTest extends TestCase {
	public function testSummaryFromGanttTasks(): void {
		$summary = PlanningService::summarize([
			['start_date' => '2026-11-02', 'end_date' => '2026-11-11', 'status' => 'done', 'progress' => 100],     // 10 Tage
			['start_date' => '2026-11-12', 'end_date' => '2026-11-21', 'status' => 'in_progress', 'progress' => 50], // 10 Tage
			['start_date' => '2026-11-01', 'end_date' => '2026-11-05', 'status' => 'not_started', 'progress' => 0],  // 5 Tage, überfällig
		], '2026-11-11');
		$this->assertSame(3, $summary['tasks']);
		$this->assertSame(1, $summary['done']);
		$this->assertSame(60, $summary['progress']); // (100×10 + 50×10 + 0×5) / 25
		$this->assertSame('2026-11-01', $summary['start']);
		$this->assertSame('2026-11-21', $summary['end']);
		$this->assertSame(1, $summary['overdue']);
	}

	public function testEmptyProject(): void {
		$this->assertSame(['tasks' => 0, 'done' => 0, 'progress' => 0, 'start' => null, 'end' => null, 'overdue' => 0, 'milestones' => [], 'demand' => []], PlanningService::summarize([], '2026-11-11'));
	}
}
