<?php

declare(strict_types=1);

namespace OCA\Nexus\Tests\Unit\Planning;

use OCA\Nexus\Exception\ValidationException;
use OCA\Nexus\Planning\MsProjectProvider;
use OCA\Nexus\Service\PlanningService;
use PHPUnit\Framework\TestCase;

class MsProjectProviderTest extends TestCase {
	public function testParsesTasksAndSkipsSummaryAndEmptyRows(): void {
		$plan = MsProjectProvider::parse((string)file_get_contents(__DIR__ . '/../../e2e/fixtures/plan.xml'), 'plan.xml');
		$this->assertSame('Leitstand Montage', $plan['title']);
		$this->assertSame(['Anforderungen', 'Grobkonzept', 'Konzept abgenommen', 'Inbetriebnahme'], array_column($plan['tasks'], 'title'));
		$this->assertSame(['title' => 'Anforderungen', 'start_date' => '2026-11-02', 'end_date' => '2026-11-11', 'status' => 'done', 'progress' => 100.0, 'milestone' => false, 'demand' => [['role' => 'Entwicklung', 'days' => 8.0]]], $plan['tasks'][0]);
		// Personen ohne Gruppe zählen „ohne Rolle“, Material nicht
		$this->assertSame([['role' => 'Tester', 'days' => 5.0], ['role' => '', 'days' => 2.0]], $plan['tasks'][1]['demand']);
		$this->assertTrue($plan['tasks'][2]['milestone']);

		$summary = PlanningService::summarize($plan['tasks'], '2026-12-01');
		$this->assertSame(4, $summary['tasks']);
		$this->assertSame(1, $summary['done']);
		$this->assertSame('2027-03-31', $summary['end']);
		$this->assertSame(2, $summary['overdue']); // Grobkonzept und Konzept-Meilenstein
		$this->assertSame([
			['title' => 'Konzept abgenommen', 'date' => '2026-11-27', 'done' => false],
			['title' => 'Inbetriebnahme', 'date' => '2027-03-31', 'done' => false],
		], $summary['milestones']);
		$this->assertSame([
			['role' => '', 'period' => '2026-11', 'days' => 2.0],
			['role' => 'Entwicklung', 'period' => '2026-11', 'days' => 8.0],
			['role' => 'Entwicklung', 'period' => '2027-03', 'days' => 1.0],
			['role' => 'Tester', 'period' => '2026-11', 'days' => 5.0],
		], $summary['demand']);
		$this->assertStringNotContainsString('Schmidt', json_encode($summary));
	}

	public function testExportRoundTrip(): void {
		$xml = MsProjectProvider::write('PRJ-2026-0001 Leitstand', '2026-11-02', '2026-11-13', 'Projektrahmen', [
			['name' => 'Konzept abgenommen', 'date' => '2026-11-06', 'external' => true],
		]);
		$this->assertStringContainsString('<Duration>PT80H0M0S</Duration>', $xml); // 10 Arbeitstage
		$plan = MsProjectProvider::parse($xml, 'x.xml');
		$this->assertSame('PRJ-2026-0001 Leitstand', $plan['title']);
		$this->assertSame([
			['title' => 'Projektrahmen', 'start_date' => '2026-11-02', 'end_date' => '2026-11-13', 'status' => 'not_started', 'progress' => 0.0, 'milestone' => false, 'demand' => []],
			['title' => 'Konzept abgenommen', 'start_date' => '2026-11-06', 'end_date' => '2026-11-06', 'status' => 'not_started', 'progress' => 0.0, 'milestone' => true, 'demand' => []],
		], $plan['tasks']);
	}

	public function testTitleFallsBackToFileName(): void {
		$plan = MsProjectProvider::parse('<Project xmlns="http://schemas.microsoft.com/project"><Tasks/></Project>', '/x/Rollout Werk 2.xml');
		$this->assertSame('Rollout Werk 2', $plan['title']);
		$this->assertSame([], $plan['tasks']);
	}

	public function testRejectsDoctypeAndForeignXml(): void {
		foreach ([
			'<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><Project>&a;</Project>',
			'<html><body/></html>',
			'kein xml',
		] as $content) {
			try {
				MsProjectProvider::parse($content);
				$this->fail('expected ValidationException');
			} catch (ValidationException $e) {
				$this->assertSame('The file is not a MS Project XML file (MSPDI)', $e->key);
			}
		}
	}
}
