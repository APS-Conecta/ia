<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia;

use RuntimeException;

/** The caller may not do this (it is another Editor group's), with the reason in Spanish. */
class Forbidden extends RuntimeException {
}
