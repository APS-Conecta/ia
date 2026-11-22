<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Run;

use OCA\Ia\Bulletin\EditionRun;
use OCA\Ia\Db\Run;
use OCA\Ia\Reading\ReadingRun;
use OCA\Ia\Review\Reviews;

/** Which Kind moves a Run, by its kind. */
class Kinds {
	public function __construct(
		private Reviews $reviews,
		private EditionRun $editions,
		private ReadingRun $readings,
	) {
	}

	public function of(Run $run): Kind {
		return match ($run->getKind()) {
			Run::EDITION => $this->editions,
			Run::READING => $this->readings,
			default => $this->reviews,
		};
	}
}
