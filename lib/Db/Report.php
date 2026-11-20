<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A Review's report: the findings for one version (etag) of one file, as JSON, null until the
 * Review is done.
 *
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getEtag()
 * @method void setEtag(string $etag)
 * @method string getOrigin()
 * @method void setOrigin(string $origin)
 * @method string|null getRequester()
 * @method void setRequester(?string $requester)
 * @method string|null getFindings()
 * @method void setFindings(?string $findings)
 * @method int|null getRule()
 * @method void setRule(?int $rule)
 * @method string|null getInstructions()
 * @method void setInstructions(?string $instructions)
 * @method int|null getAutomaticFile()
 * @method void setAutomaticFile(?int $automaticFile)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Report extends Entity {
	public const MANUAL = 'manual';
	public const ACTION = 'action';

	// Null until set: Entity writes only the fields a setter changed.
	protected ?int $fileId = null;
	protected ?string $etag = null;
	protected ?string $origin = null;
	/** Who asked for a manual report; who added or changed the file, for an automatic one (null when unknown). */
	protected ?string $requester = null;
	/** The Automatic action (or Flow rule) of an automatic report. */
	protected ?int $rule = null;
	/** The instructions the Review used, from its rule. */
	protected ?string $instructions = null;
	/** The file id again for an automatic report, null for a manual one: a file keeps one automatic report. */
	protected ?int $automaticFile = null;
	protected ?string $findings = null;
	protected ?int $createdAt = null;

	/** Whether an Automatic action (or a Flow rule) asked for it. */
	public function isAutomatic(): bool {
		return $this->automaticFile !== null;
	}

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('rule', 'integer');
		$this->addType('automaticFile', 'integer');
	}
}
