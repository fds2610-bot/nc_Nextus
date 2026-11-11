<?php

declare(strict_types=1);

namespace OCA\Nexus\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'nc_nexus';

	/** @param array<string, mixed> $urlParams */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(\OCA\Nexus\Notification\Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
