<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Reading;

use OCA\Ia\Refused;

/** A scanned document: an image with no text layer, which «IA» doesn't read in v1 (no OCR). */
class Scanned extends Refused {
}
