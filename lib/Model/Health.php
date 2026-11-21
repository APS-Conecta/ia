<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Model;

use OCA\Ia\AppInfo\Application;
use OCA\Ia\ResponseDefinitions;
use OCA\Ia\Run\Runs;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\TaskProcessing\Task;
use Throwable;

/**
 * Whether the Model answers (gestion-aio#162, «Estado» and the model-down alert), found out through
 * TaskProcessing like everything else «IA» asks (gestion-aio ADR 0003).
 *
 * Each tick takes the outcome of the probe, a one-word ask, once it has one: that is a check. A new
 * probe goes out when the last answer is older than EVERY, and on every tick while the Model is
 * down. The Model is down from the first failed check. When it still is ALERT later, the admins get
 * one notification and the setup check warns; its first answer after that takes the notification
 * back. A tick more than GAP after the previous one means the server itself was stopped (the
 * nightly backup stops it), so the count starts again from that tick.
 *
 * Its state is a few app config values, so that it survives a restart.
 *
 * @psalm-import-type IaModelHealth from ResponseDefinitions
 */
class Health {
	/** Seconds between probes while the Model answers. */
	private const EVERY = 30 * 60;
	/** Seconds the Model is down, from the first failed check, before the admins are told: the model-down alert. */
	public const ALERT = 30 * 60;
	/** Seconds between two ticks that mean the server was stopped in between. */
	private const GAP = 15 * 60;

	private const TICKED = 'health_ticked';
	private const PROBE = 'health_probe';
	private const DOWN = 'health_down';
	private const ANSWERED = 'health_answered';
	private const ALERTED = 'health_alerted';

	public function __construct(
		private ModelGateway $model,
		private IAppConfig $config,
		private IGroupManager $groups,
		private IManager $notifications,
		private ITimeFactory $time,
	) {
	}

	/** One tick's check. */
	public function check(): void {
		$now = $this->time->getTime();
		$ticked = $this->int(self::TICKED);
		$this->config->setValueInt(Application::APP_ID, self::TICKED, $now, true);
		if ($this->int(self::DOWN) !== 0 && $ticked !== 0 && ($now - $ticked > self::GAP || $now < $ticked)) {
			$this->config->setValueInt(Application::APP_ID, self::DOWN, $now, true);
		}
		$probe = $this->int(self::PROBE);
		if ($probe !== 0) {
			$task = $this->model->task($probe);
			$asked = in_array($task?->getStatus(), [Task::STATUS_SCHEDULED, Task::STATUS_RUNNING], true);
			if ($asked && $task->getScheduledAt() > $now - Runs::STUCK) {
				return;
			}
			if ($task !== null) {
				$asked ? $this->model->cancel($task) : $this->model->forget($task);
			}
			$this->config->setValueInt(Application::APP_ID, self::PROBE, 0, true);
			$this->record($task?->getStatus() === Task::STATUS_SUCCESSFUL, $now);
		}
		if ($this->int(self::DOWN) === 0 && $now - $this->int(self::ANSWERED) < self::EVERY) {
			return;
		}
		try {
			$this->config->setValueInt(Application::APP_ID, self::PROBE, $this->model->probe(), true);
		} catch (Throwable) {
			// No provider takes core:text2text, or TaskProcessing refused the probe: a failed check too.
			$this->record(false, $now);
		}
	}

	/** @return IaModelHealth */
	public function status(): array {
		$down = $this->int(self::DOWN);
		$answered = $this->int(self::ANSWERED);
		return [
			'state' => $down !== 0 ? 'down' : ($answered !== 0 ? 'up' : 'unknown'),
			'since' => $down !== 0 ? $down : null,
			'answered' => $answered !== 0 ? $answered : null,
			'alerted' => $this->config->getValueBool(Application::APP_ID, self::ALERTED, false, true),
			'provider' => $this->model->available(),
		];
	}

	private function record(bool $answered, int $now): void {
		if ($answered) {
			$this->config->setValueInt(Application::APP_ID, self::ANSWERED, $now, true);
			$this->config->setValueInt(Application::APP_ID, self::DOWN, 0, true);
			if ($this->config->getValueBool(Application::APP_ID, self::ALERTED, false, true)) {
				$this->config->setValueBool(Application::APP_ID, self::ALERTED, false, true);
				$this->notifications->markProcessed($this->notification());
			}
			return;
		}
		$down = $this->int(self::DOWN);
		if ($down === 0) {
			$this->config->setValueInt(Application::APP_ID, self::DOWN, $now, true);
		} elseif ($now - $down >= self::ALERT && !$this->config->getValueBool(Application::APP_ID, self::ALERTED, false, true)) {
			$this->config->setValueBool(Application::APP_ID, self::ALERTED, true, true);
			$this->tell();
		}
	}

	private function tell(): void {
		foreach ($this->groups->get('admin')?->getUsers() ?? [] as $admin) {
			if ($admin->isEnabled()) {
				$this->notifications->notify($this->notification()
					->setUser($admin->getUID())
					->setDateTime($this->time->getDateTime())
					->setSubject('model_down'));
			}
		}
	}

	private function notification(): INotification {
		return $this->notifications->createNotification()->setApp(Application::APP_ID)->setObject('model', 'down');
	}

	private function int(string $key): int {
		return $this->config->getValueInt(Application::APP_ID, $key, 0, true);
	}
}
