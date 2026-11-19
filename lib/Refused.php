<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia;

use RuntimeException;

/** A request «IA» turns down, with the reason in Spanish, as the person reads it. */
class Refused extends RuntimeException {
}
