<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Listener;

use OCA\Ia\AppInfo\Application;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Model\ModelGateway;
use OCA\Ia\Run\Kinds;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where TaskProcessing hands back «IA»'s tasks, success or failure: each to the Run its custom
 * id names, and a task no Run claims is deleted. A failure reaches the person in Spanish; its own
 * error stays in Nextcloud's log. Nothing here fails the worker, and nothing it logs carries a
 * document's text or the Model's answer: only the Run's id and the error's message.
 *
 * @template-implements IEventListener<Event>
 */
class TaskListener implements IEventListener {
	public function __construct(
		private RunMapper $runs,
		private Kinds $kinds,
		private ModelGateway $model,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof TaskSuccessfulEvent && !$event instanceof TaskFailedEvent) {
			return;
		}
		$task = $event->getTask();
		// Health takes its probe's outcome on its own tick.
		if ($task->getAppId() !== Application::APP_ID || $task->getCustomId() === ModelGateway::PROBE) {
			return;
		}
		try {
			$run = $this->runs->byId((int)$task->getCustomId());
			if ($run === null) {
				$this->model->forget($task);
			} else {
				$this->kinds->of($run)->finish($run, $task, $event instanceof TaskFailedEvent ? 'El modelo no respondió.' : null);
			}
		} catch (Throwable $e) {
			$this->logger->error('Taking the result of the Run {run} failed: {error}', ['run' => $task->getCustomId(), 'error' => $e::class]);
		}
	}
}
