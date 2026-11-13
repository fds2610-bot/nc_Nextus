<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Planning;

use OCA\Nexus\Planning\OpenProjectProvider;
use PHPUnit\Framework\TestCase;

class OpenProjectProviderTest extends TestCase {
	public function testNormalizesWorkPackagesAndMilestones(): void {
		$tasks = OpenProjectProvider::normalize([
			['id' => 11, 'subject' => 'Spezifikation', 'startDate' => '2026-11-02', 'dueDate' => '2026-11-11', 'percentageDone' => 60, 'estimatedTime' => 'PT20H', '_links' => ['status' => ['title' => 'Closed']]],
			['id' => 12, 'subject' => 'Umsetzung', 'startDate' => '2026-11-12', 'dueDate' => null, 'percentageDone' => 20, '_links' => ['status' => ['title' => 'In progress']]],
			['id' => 13, 'subject' => 'Go-live', 'date' => '2027-01-15', '_links' => ['status' => ['title' => 'New']]],
		], [11]);
		$this->assertSame(['title' => 'Spezifikation', 'start_date' => '2026-11-02', 'end_date' => '2026-11-11', 'status' => 'done', 'progress' => 100.0, 'milestone' => false, 'demand' => [['role' => '', 'days' => 2.5]]], $tasks[0]);
		$this->assertSame(['title' => 'Umsetzung', 'start_date' => '2026-11-12', 'end_date' => '2026-11-12', 'status' => 'In progress', 'progress' => 20.0, 'milestone' => false, 'demand' => []], $tasks[1]);
		$this->assertSame(['title' => 'Go-live', 'start_date' => '2027-01-15', 'end_date' => '2027-01-15', 'status' => 'New', 'progress' => 0.0, 'milestone' => true, 'demand' => []], $tasks[2]);
	}
}
