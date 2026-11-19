<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use RuntimeException;

/** Another process saved the Run first: what this one did with it is rolled back. */
class StaleRun extends RuntimeException {
}
