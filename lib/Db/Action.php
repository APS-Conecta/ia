<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * An Automatic action (CONTEXT.md): a rule its Editor group puts on a folder (by file id), with
 * optional instructions for the Reviews it asks for.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method int getFolder()
 * @method void setFolder(int $folder)
 * @method string getEditorGroup()
 * @method void setEditorGroup(string $editorGroup)
 * @method string|null getInstructions()
 * @method void setInstructions(?string $instructions)
 */
class Action extends Entity {
	// Null until set: Entity writes only the fields a setter changed.
	protected ?string $name = null;
	protected ?int $folder = null;
	protected ?string $editorGroup = null;
	protected ?string $instructions = null;

	public function __construct() {
		$this->addType('folder', 'integer');
	}
}
