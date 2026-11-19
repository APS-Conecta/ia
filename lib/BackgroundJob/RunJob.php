<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\BackgroundJob;

use OCA\Ia\Run\Runs;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** The run engine's tick, every scheduler interval: 5 minutes (gestion-aio#162, Limits). */
class RunJob extends TimedJob {
	public const INTERVAL = 5 * 60;

	public function __construct(
		ITimeFactory $time,
		private Runs $runs,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	protected function run($argument): void {
		$this->runs->tick();
	}
}
