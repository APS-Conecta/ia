<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Reading;

/** What an answer to a Reading's ask gave (Readings::take). */
enum Taken {
	/** Nothing: a failed attempt. */
	case Empty;
	/** One part of a long document, with more to ask. */
	case Part;
	/** The whole document: its Reading is stored. */
	case Read;
}
