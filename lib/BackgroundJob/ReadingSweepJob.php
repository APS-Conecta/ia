<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\BackgroundJob;

use OCA\Ia\Reading\ReadingRun;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * The night's sweep of the Automatic action folders (APS-Conecta/ia#42), checked every scheduler
 * interval: the first one in Off hours opens the night's Readings (ReadingRun::due), which the run
 * engine then moves with the other Off-hours Runs.
 */
class ReadingSweepJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ReadingRun $readings,
		private Settings $settings,
	) {
		parent::__construct($time);
		$this->setInterval(RunJob::INTERVAL);
	}

	protected function run($argument): void {
		$now = $this->time->getDateTime();
		if ($this->settings->isOffHours($now)) {
			$this->readings->due($now);
		}
	}
}
