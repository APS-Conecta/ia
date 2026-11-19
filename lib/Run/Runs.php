<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Run;

use OCA\Ia\Db\Run;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Model\ModelGateway;
use OCA\Ia\Review\Reviews;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\TaskProcessing\Task;

/**
 * The run engine (gestion-aio#162, Runs): one tick per scheduler interval, all its state in the
 * database, so a restarted server goes on where it stopped.
 *
 * 1. **Recover.** A working Run whose task is missing, failed or cancelled is put back in line, an
 *    attempt spent. A task running longer than the connector's request timeout plus 5 minutes is
 *    cancelled and its Run put back in line; a task still waiting in TaskProcessing's queue is
 *    never touched. An answer whose event was lost is taken.
 * 2. **Schedule.** Every waiting short Run is asked at once. In Off hours, when no Off-hours Run is
 *    working, the oldest waiting one is asked: at most one Off-hours task in flight.
 * 3. **Fail.** The third failed attempt fails a Run with its last error (Run::retry).
 */
class Runs {
	/** Seconds a task may run: the connector's request timeout, as the suite sets it (gestion-aio#167), plus 5 minutes. */
	public const STUCK = 900 + 5 * 60;

	public function __construct(
		private RunMapper $runs,
		private ModelGateway $model,
		private Reviews $reviews,
		private Settings $settings,
		private ITimeFactory $time,
	) {
	}

	public function tick(): void {
		foreach ($this->runs->inState(Run::WORKING) as $run) {
			$this->recover($run);
		}
		$waiting = $this->runs->inState(Run::WAITING);
		foreach ($waiting as $run) {
			if (!$run->getOffHours()) {
				$this->reviews->resume($run);
			}
		}
		if (!$this->settings->isOffHours($this->time->getDateTime()) || $this->offHoursWorking()) {
			return;
		}
		foreach ($waiting as $run) {
			if ($run->getOffHours()) {
				$this->reviews->resume($run);
				return;
			}
		}
	}

	private function recover(Run $run): void {
		$task = $run->getTaskId() === null ? null : $this->model->task($run->getTaskId());
		switch ($task?->getStatus()) {
			case Task::STATUS_SCHEDULED:
				return;
			case Task::STATUS_SUCCESSFUL:
				$this->reviews->finish($run, $task, null);
				return;
			case Task::STATUS_RUNNING:
				if ($task->getStartedAt() > $this->time->getTime() - self::STUCK) {
					return;
				}
				$this->model->cancel($task);
				break;
			case null:
				break;
			default:
				$this->model->forget($task);
		}
		$run->retry('El modelo no respondió.');
		$this->runs->save($run);
	}

	private function offHoursWorking(): bool {
		foreach ($this->runs->inState(Run::WORKING) as $run) {
			if ($run->getOffHours()) {
				return true;
			}
		}
		return false;
	}
}
