<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

use OCA\Nexus\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Alle Planungs-Provider: eingebaut Gantt, OpenProject und MS Project (MSPDI), dazu die über
 * RegisterPlanningProvidersEvent registrierten. Kennungen sind eindeutig, der erste gewinnt.
 */
class PlanningProviderRegistry {
	/** @var array<string, IPlanningProvider>|null */
	private ?array $providers = null;

	public function __construct(
		private GanttProvider $gantt,
		private OpenProjectProvider $openProject,
		private MsProjectProvider $msProject,
		private IEventDispatcher $dispatcher,
		private LoggerInterface $logger,
	) {
	}

	/** @return array<string, IPlanningProvider> */
	public function all(): array {
		if ($this->providers === null) {
			$event = new RegisterPlanningProvidersEvent();
			try {
				$this->dispatcher->dispatchTyped($event);
			} catch (\Throwable $e) {
				$this->logger->warning('nc_nexus: registering planning providers failed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
			$this->providers = [];
			foreach ([$this->gantt, $this->openProject, $this->msProject, ...$event->getProviders()] as $provider) {
				$id = $provider->getId();
				if (preg_match('/^[a-z0-9_]{2,32}$/', $id) === 1 && !isset($this->providers[$id])) {
					$this->providers[$id] = $provider;
				}
			}
		}
		return $this->providers;
	}

	public function get(string $id): ?IPlanningProvider {
		return $this->all()[$id] ?? null;
	}

	/**
	 * Gibt es für das Konto ein Planungstool, das live abgeglichen wird? Nur dann erwartet das
	 * Datenqualitäts-Cockpit eine Verknüpfung (der Dateiimport ist ein Angebot, keine Pflicht).
	 */
	public function hasLiveProvider(string $userId): bool {
		foreach ($this->all() as $provider) {
			if (in_array(IPlanningProvider::CAP_SYNC, $provider->getCapabilities(), true) && $this->safeAvailable($provider, $userId)) {
				return true;
			}
		}
		return false;
	}

	public function safeAvailable(IPlanningProvider $provider, string $userId): bool {
		try {
			return $provider->isAvailable($userId);
		} catch (\Throwable) {
			return false;
		}
	}
}
