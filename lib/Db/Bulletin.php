<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A Bulletin (CONTEXT.md): owned by its Editor group, read by its Reader groups. Sources and
 * Readers are JSON lists; the schedule is «daily», «weekly:N», «monthly:N» or «sources»
 * (Bulletin\Schedule).
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string getEditorGroup()
 * @method void setEditorGroup(string $editorGroup)
 * @method string getSources()
 * @method void setSources(string $sources)
 * @method string getReaders()
 * @method void setReaders(string $readers)
 * @method string getSchedule()
 * @method void setSchedule(string $schedule)
 * @method string getNext()
 * @method void setNext(string $next)
 * @method string|null getFingerprint()
 * @method void setFingerprint(?string $fingerprint)
 */
class Bulletin extends Entity {
	// Null until set: Entity writes only the fields a setter changed.
	protected ?string $name = null;
	protected ?string $editorGroup = null;
	protected ?string $sources = null;
	protected ?string $readers = null;
	protected ?string $schedule = null;
	/** The earliest date (Y-m-d) its next Edition may be for: one Edition however many dates were missed. */
	protected ?string $next = null;
	/** The Sources' state its Editions are up to (Db\Changes), when it comes «cuando cambien sus fuentes». */
	protected ?string $fingerprint = null;
}
