<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Planning;

use OCA\Nexus\Planning\GanttProvider;
use PHPUnit\Framework\TestCase;

class GanttProviderTest extends TestCase {
	public function testNormalizesLocalAndRemoteTasks(): void {
		$this->assertSame([
			['title' => 'lokal', 'start_date' => '2026-11-02', 'end_date' => '2026-11-11', 'status' => 'done', 'progress' => 100.0],
			['title' => 'OCS', 'start_date' => '2026-11-12', 'end_date' => null, 'status' => 'in_progress', 'progress' => 40.0],
		], GanttProvider::normalize([
			['title' => 'lokal', 'start_date' => '2026-11-02', 'end_date' => '2026-11-11 00:00:00', 'status' => 'done', 'progress' => 100],
			['title' => 'OCS', 'start_date' => '2026-11-12', 'end_date' => '', 'status' => 'in_progress', 'manual_progress' => 40],
		]));
	}
}
