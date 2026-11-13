<?php

declare(strict_types=1);

namespace OCA\NexusDevAi;

use OCP\Server;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ITriggerableProvider;
use OCP\TaskProcessing\TaskTypes\TextToText;

/**
 * DEV-Dummy für „Text zu Text“: antwortet sofort mit einem festen Text, der den Auftrag und die
 * erste Datenzeile wiederholt. Verarbeitet neue Aufgaben direkt beim Einplanen (trigger), damit
 * die E2E-Tests nicht auf den Cronjob warten müssen.
 */
class Provider implements ISynchronousProvider, ITriggerableProvider {
	public function getId(): string {
		return 'nexus_dev_ai-text2text';
	}

	public function getName(): string {
		return 'Nexus DEV-KI (Dummy)';
	}

	public function getTaskTypeId(): string {
		return TextToText::ID;
	}

	public function getExpectedRuntime(): int {
		return 1;
	}

	public function getOptionalInputShape(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		$prompt = (string)($input['input'] ?? '');
		preg_match('/^TASK: (.+)$/m', $prompt, $task);
		// zweite Datenzeile ist der Titel des Vorhabens (erste: Schlüssel)
		preg_match('/DATA START\n[^\n]*\n[^:]+: ([^\n]+)/', $prompt, $title);
		return ['output' => sprintf(
			"[DEV-KI] %s\n\nVorhaben: %s\n\nDies ist eine feste Testantwort ohne Sprachmodell (%d Zeichen Eingabe).",
			mb_substr(trim($task[1] ?? 'Auftrag'), 0, 60) . ' …',
			trim($title[1] ?? '–'),
			mb_strlen($prompt),
		)];
	}

	public function trigger(): void {
		$manager = Server::get(IManager::class);
		try {
			$task = $manager->getNextScheduledTask([TextToText::ID]);
		} catch (\Throwable) {
			return;
		}
		$manager->processTask($task, $this);
	}
}
