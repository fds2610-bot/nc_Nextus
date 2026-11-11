<?php

declare(strict_types=1);

namespace OCA\Nexus\BackgroundJob;

use OCA\Nexus\Service\BenefitService;
use OCA\Nexus\Service\ConflictService;
use OCA\Nexus\Service\NotificationService;
use OCA\Nexus\Service\ProjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Täglicher Lauf (Kapitel 06 §4.2/4.4): Konflikte erkennen, Fristen eskalieren, an fällige
 * Auflagen, Statusberichte und Nutzenreviews erinnern, Tagesüberblick fürs PMO.
 */
class DailyJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ConflictService $conflicts,
		private ProjectService $projects,
		private NotificationService $notifications,
		private BenefitService $benefits,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		try {
			$detected = $this->conflicts->detect();
			$escalated = $this->conflicts->escalate();
			$this->notifications->conflicts($detected['created'], $escalated);
			$overdue = [];
			$toTriage = 0;
			foreach ($this->notifications->allDemands() as $demand) {
				$due = $this->projects->reportDue($demand);
				if ($due !== null && $due['overdue']) {
					$overdue[] = ['demand' => $demand, 'due' => $due['due']];
				}
				if ($demand->getStatus() === 'submitted') {
					$toTriage++;
				}
			}
			$open = count($this->conflicts->openAll());
			$this->notifications->daily($overdue, $toTriage, $open);
			$this->notifications->benefitReviewsDue($this->benefits->due());
		} catch (\Throwable $e) {
			$this->logger->error('Nexus: daily job failed', ['exception' => $e]);
		}
	}
}
