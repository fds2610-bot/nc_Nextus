<?php

declare(strict_types=1);

namespace OCA\NexusDevAi\AppInfo;

use OCA\NexusDevAi\Provider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public function __construct() {
		parent::__construct('nexus_dev_ai');
	}

	public function register(IRegistrationContext $context): void {
		$context->registerTaskProcessingProvider(Provider::class);
	}

	public function boot(IBootContext $context): void {
	}
}
