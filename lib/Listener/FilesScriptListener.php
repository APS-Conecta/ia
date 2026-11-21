<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Ia\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Adds «Revisar con IA» to Files (APS-Conecta/ia#5): the script that registers the file action.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class FilesScriptListener implements IEventListener {
	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof LoadAdditionalScriptsEvent) {
			Util::addScript(Application::APP_ID, Application::APP_ID . '-files');
		}
	}
}
