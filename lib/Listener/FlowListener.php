<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Listener;

use OCA\Ia\AppInfo\Application;
use OCA\Ia\Flow\ReviewOperation;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use OCP\WorkflowEngine\Events\LoadSettingsScriptsEvent;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;

/**
 * Brings «Revisar con IA» to Nextcloud Flow (APS-Conecta/ia#11): the operation, and on Flow's
 * settings the script with its instructions field.
 *
 * @template-implements IEventListener<RegisterOperationsEvent|LoadSettingsScriptsEvent>
 */
class FlowListener implements IEventListener {
	public function __construct(
		private ReviewOperation $operation,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof RegisterOperationsEvent) {
			$event->registerOperation($this->operation);
		} elseif ($event instanceof LoadSettingsScriptsEvent) {
			Util::addScript(Application::APP_ID, Application::APP_ID . '-flow', 'workflowengine');
		}
	}
}
