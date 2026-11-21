<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Ia\Dashboard\BulletinsWidget;
use OCA\Ia\Listener\ActionListener;
use OCA\Ia\Listener\FileListener;
use OCA\Ia\Listener\FilesScriptListener;
use OCA\Ia\Listener\TaskListener;
use OCA\Ia\Notification\Notifier;
use OCA\Ia\SetupCheck\ModelCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
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
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, FilesScriptListener::class);
		foreach ([NodeCreatedEvent::class, NodeWrittenEvent::class, NodeRenamedEvent::class, NodeCopiedEvent::class] as $event) {
			$context->registerEventListener($event, ActionListener::class);
		}
		$context->registerNotifierService(Notifier::class);
		$context->registerDashboardWidget(BulletinsWidget::class);
		$context->registerSetupCheck(ModelCheck::class);
	}

	public function boot(IBootContext $context): void {
	}
}
