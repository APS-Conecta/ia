<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Run;

use OCA\Ia\Bulletin\EditionRun;
use OCA\Ia\Db\Run;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Model\ModelGateway;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The run engine (gestion-aio#162, Runs): one tick per scheduler interval, all its state in the
 * database, so a restarted server goes on where it stopped.
 *
 * 1. **Recover.** A working Run whose task is missing, failed or cancelled is put back in line, an
 *    attempt spent. A task running longer than the connector's request timeout plus 5 minutes is
 *    cancelled and its Run put back in line; a task still waiting in TaskProcessing's queue is
 *    never touched. An answer whose event was lost is taken.
 * 2. **Schedule.** Every waiting short Run is asked at once. In Off hours, the Editions due open
 *    their Runs; when no Off-hours Run is working, the next waiting one is asked, Reviews asked
 *    for by hand before Editions, oldest first: at most one Off-hours task in flight.
 * 3. **Fail.** The third failed attempt in a row fails a Run with its last error (Run::retry).
 *
 * A Run that can't be moved is logged by its id and the error's class only (a message may name a
 * file), and the tick goes on with the others.
 */
class Runs {
	/** Seconds a task may run: the connector's request timeout, as the suite sets it (gestion-aio#167), plus 5 minutes. */
	public const STUCK = 900 + 5 * 60;

	public function __construct(
		private RunMapper $runs,
		private ModelGateway $model,
		private Kinds $kinds,
		private EditionRun $editions,
		private Settings $settings,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function tick(): void {
		foreach ($this->runs->inState(Run::WORKING) as $run) {
			$this->move($run, $this->recover(...));
		}
		foreach ($this->runs->inState(Run::WAITING) as $run) {
			if (!$run->getOffHours()) {
				$this->move($run, $this->resume(...));
			}
		}
		if (!$this->settings->isOffHours($this->time->getDateTime())) {
			return;
		}
		try {
			$this->editions->due($this->time->getDateTime());
		} catch (Throwable $e) {
			$this->logger->error('The run engine could not open the Editions due: {error}', ['error' => $e::class]);
		}
		if ($this->offHoursWorking()) {
			return;
		}
		$waiting = $this->runs->inState(Run::WAITING);
		usort($waiting, static fn (Run $a, Run $b): int => [$a->getKind() !== Run::REVIEW, $a->getId()] <=> [$b->getKind() !== Run::REVIEW, $b->getId()]);
		foreach ($waiting as $run) {
			// One that ended (failed, or published without the Model) leaves the turn to the next; one
			// put back in line keeps it, so the oldest still starts first.
			if ($run->getOffHours() && $this->move($run, $this->resume(...)) && !in_array($run->getState(), [Run::DONE, Run::FAILED], true)) {
				return;
			}
		}
	}

	private function resume(Run $run): void {
		$this->kinds->of($run)->resume($run);
	}

	/**
	 * Moves one Run with $step, so that a Run that can't be moved stops neither the tick nor the
	 * others.
	 *
	 * @param callable(Run): void $step
	 * @return bool whether it moved
	 */
	private function move(Run $run, callable $step): bool {
		try {
			$step($run);
			return true;
		} catch (Throwable $e) {
			$this->logger->error('The run engine could not move the Run {run}: {error}', ['run' => $run->getId(), 'error' => $e::class]);
			return false;
		}
	}

	private function recover(Run $run): void {
		$task = $run->getTaskId() === null ? null : $this->model->task($run->getTaskId());
		switch ($task?->getStatus()) {
			case Task::STATUS_SCHEDULED:
				return;
			case Task::STATUS_SUCCESSFUL:
				$this->kinds->of($run)->finish($run, $task, null);
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
