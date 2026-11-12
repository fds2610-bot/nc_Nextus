<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

use OCP\EventDispatcher\Event;

/**
 * Wird beim ersten Zugriff auf die Provider-Registry ausgelöst; andere Apps registrieren hier
 * ihre IPlanningProvider (Kapitel 07 §4.1).
 */
final class RegisterPlanningProvidersEvent extends Event {
	/** @var list<IPlanningProvider> */
	private array $providers = [];

	public function register(IPlanningProvider $provider): void {
		$this->providers[] = $provider;
	}

	/** @return list<IPlanningProvider> */
	public function getProviders(): array {
		return $this->providers;
	}
}
