<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Model;

use OCA\Ia\Access\Access;
use OCA\Ia\AppInfo\Application;
use OCA\Ia\ResponseDefinitions;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Notification\IManager;
use Throwable;

/**
 * Whether the Model answers (gestion-aio#162, «Estado» and the model-down alert), found out through
 * TaskProcessing like everything else «IA» asks (gestion-aio ADR 0003).
 *
 * Each tick takes the outcome of the probe, a one-word ask, once it has one: that is a check. A
 * probe still waiting in TaskProcessing's queue, however long, has none yet (ModelGateway::outcome).
 * A new probe goes out when the last answer is older than EVERY, and on every tick while the Model
 * is down. The Model is down from the first failed check. When it has been down ALERT, the admins
 * get one notification and the setup check warns, until it answers again. A tick more than GAP
 * after the previous one means the server itself was stopped (the nightly backup stops it): that
 * time doesn't count, so the time it went down moves on by it.
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

	private const TICKED_KEY = 'health_ticked';
	private const PROBE_KEY = 'health_probe';
	private const DOWN_KEY = 'health_down';
	private const ANSWERED_KEY = 'health_answered';
	private const ALERTED_KEY = 'health_alerted';

	public function __construct(
		private ModelGateway $model,
		private IAppConfig $config,
		private Access $access,
		private IManager $notifications,
		private ITimeFactory $time,
	) {
	}

	/** One tick's check. */
	public function check(): void {
		$now = $this->time->getTime();
		$ticked = $this->int(self::TICKED_KEY);
		$this->config->setValueInt(Application::APP_ID, self::TICKED_KEY, $now, true);
		$down = $this->int(self::DOWN_KEY);
		if ($down !== 0 && $ticked !== 0 && ($now - $ticked > self::GAP || $now < $ticked)) {
			// The server was stopped since the last tick: that time doesn't count. A clock gone back starts the count again.
			$this->config->setValueInt(Application::APP_ID, self::DOWN_KEY, $now < $ticked ? $now : $down + $now - $ticked, true);
		}
		$probe = $this->int(self::PROBE_KEY);
		if ($probe !== 0) {
			$task = $this->model->outcome($probe);
			if ($task === null) {
				return;
			}
			if ($task !== false) {
				$this->model->forget($task);
			}
			$this->config->setValueInt(Application::APP_ID, self::PROBE_KEY, 0, true);
			$this->record($task !== false, $now);
		}
		if ($this->int(self::DOWN_KEY) === 0 && $now - $this->int(self::ANSWERED_KEY) < self::EVERY) {
			return;
		}
		try {
			$this->config->setValueInt(Application::APP_ID, self::PROBE_KEY, $this->model->probe(), true);
		} catch (Throwable) {
			// No provider takes core:text2text, or TaskProcessing refused the probe: a failed check too.
			$this->record(false, $now);
		}
	}

	/** @return IaModelHealth */
	public function status(): array {
		$down = $this->int(self::DOWN_KEY);
		$answered = $this->int(self::ANSWERED_KEY);
		return [
			'state' => $down !== 0 ? 'down' : ($answered !== 0 ? 'up' : 'unknown'),
			'since' => $down !== 0 ? $down : null,
			'answered' => $answered !== 0 ? $answered : null,
			'alerted' => $this->config->getValueBool(Application::APP_ID, self::ALERTED_KEY, false, true),
			'provider' => $this->model->available(),
		];
	}

	private function record(bool $answered, int $now): void {
		if ($answered) {
			$this->config->setValueInt(Application::APP_ID, self::ANSWERED_KEY, $now, true);
			$this->config->setValueInt(Application::APP_ID, self::DOWN_KEY, 0, true);
			$this->config->setValueBool(Application::APP_ID, self::ALERTED_KEY, false, true);
			return;
		}
		$down = $this->int(self::DOWN_KEY);
		if ($down === 0) {
			$this->config->setValueInt(Application::APP_ID, self::DOWN_KEY, $now, true);
		} elseif ($now - $down >= self::ALERT && !$this->config->getValueBool(Application::APP_ID, self::ALERTED_KEY, false, true)) {
			$this->config->setValueBool(Application::APP_ID, self::ALERTED_KEY, true, true);
			$this->tell();
		}
	}

	private function tell(): void {
		foreach ($this->access->members('admin') as $admin) {
			$this->notifications->notify($this->notifications->createNotification()
				->setApp(Application::APP_ID)
				->setUser($admin->getUID())
				->setDateTime($this->time->getDateTime())
				->setObject('model', 'down')
				->setSubject('model_down'));
		}
	}

	private function int(string $key): int {
		return $this->config->getValueInt(Application::APP_ID, $key, 0, true);
	}
}
