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
 */
class Bulletin extends Entity {
	// Null until set: Entity writes only the fields a setter changed.
	protected ?string $name = null;
	protected ?string $editorGroup = null;
	protected ?string $sources = null;
	protected ?string $readers = null;
	protected ?string $schedule = null;
}
