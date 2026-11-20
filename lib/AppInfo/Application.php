<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\AppInfo;

use OCA\Ia\Listener\FileListener;
use OCA\Ia\Listener\TaskListener;
use OCA\Ia\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ia';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(TaskSuccessfulEvent::class, TaskListener::class);
		$context->registerEventListener(TaskFailedEvent::class, TaskListener::class);
		$context->registerEventListener(BeforeNodeDeletedEvent::class, FileListener::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
