<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Run;

use OCA\Ia\Db\Run;
use OCA\Ia\Refused;
use OCP\TaskProcessing\Task;

/** A kind of Run the engine moves: a Review, an Edition or a Reading. */
interface Kind {
	/** Asks the waiting Run's current step, from its last finished one. */
	public function resume(Run $run): void;

	/** Takes the answer, or the error, of the task the Run waits for. */
	public function finish(Run $run, Task $task, ?string $error): void;

	/**
	 * Puts the failed Run back in line («Reintentar»): from its last finished step, or from its
	 * first when its progress was dropped. False when another process moved it first.
	 *
	 * @throws Refused when it can't go back in line
	 */
	public function requeue(Run $run): bool;

	/**
	 * What «Estado» names the Run by: its Bulletin and Edition date, or its Automatic action or Flow
	 * rule; no name for a manual Review (it is its requester's), or once what it was for is gone.
	 *
	 * @return array{name: ?string, date: ?string}
	 */
	public function about(Run $run): array;
}
