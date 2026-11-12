<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

use OCA\Nexus\Exception\ValidationException;

/**
 * Dateibasierter Provider (Kapitel 07 §4.2): Import eines Projektplans als Schnappschuss.
 */
interface IPlanningFileImport {
	/**
	 * @return array{title: string, tasks: list<array<string, mixed>>}
	 * @throws ValidationException
	 */
	public function parseFile(string $fileName, string $content): array;
}
