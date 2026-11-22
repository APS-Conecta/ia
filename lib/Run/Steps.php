<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Run;

use OCA\Ia\Db\Run;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Db\StaleRun;
use OCA\Ia\Model\ModelGateway;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use Throwable;

/** How every Kind asks the Model a Run's step (gestion-aio#162, Runs). */
class Steps {
	use TTransactional;

	public function __construct(
		private ModelGateway $model,
		private RunMapper $runs,
		private IDBConnection $db,
	) {
	}

	/** The asks $pieces take: one for a single piece; one each, and one more to merge them, for several. */
	public static function asks(int $pieces): int {
		return $pieces <= 1 ? $pieces : $pieces + 1;
	}

	/**
	 * Asks the Model the Run's current step. The task and the Run that names it are saved in one
	 * transaction, so the worker never finishes a task before its Run expects it, and a Run another
	 * process saved first rolls the new task back. A failed ask is a failed attempt.
	 */
	public function ask(Run $run, string $prompt): void {
		try {
			$this->atomic(function () use ($run, $prompt): void {
				$run->setTaskId($this->model->ask($run->getId(), $prompt));
				$run->setState(Run::WORKING);
				if (!$this->runs->save($run)) {
					throw new StaleRun();
				}
			}, $this->db);
		} catch (StaleRun) {
			return;
		} catch (Throwable) {
			$run->retry('El modelo no está disponible.');
			$this->runs->save($run);
		}
	}
}
