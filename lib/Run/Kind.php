<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Run;

use OCA\Ia\Db\Run;
use OCP\TaskProcessing\Task;

/** A kind of Run the engine moves: a Review or an Edition. */
interface Kind {
	/** Asks the waiting Run's current step, from its last finished one. */
	public function resume(Run $run): void;

	/** Takes the answer, or the error, of the task the Run waits for. */
	public function finish(Run $run, Task $task, ?string $error): void;
}
