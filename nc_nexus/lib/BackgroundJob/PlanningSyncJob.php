<?php

declare(strict_types=1);

namespace OCA\Nexus\BackgroundJob;

use OCA\Nexus\Service\PlanningService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hintergrundabgleich der Planungstools (Kapitel 07 §4.2). Läuft stündlich; welche Verknüpfungen
 * fällig sind, bestimmt der vom PMO eingestellte Abstand (Bereich „Planungstools“, 0 = aus).
 */
class PlanningSyncJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private PlanningService $planning,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		try {
			$run = $this->planning->syncAll();
			if ($run['failed'] > 0) {
				$this->logger->info('Nexus: planning sync finished with errors', ['ok' => $run['ok'], 'failed' => $run['failed']]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Nexus: planning sync failed', ['exception' => $e]);
		}
	}
}
