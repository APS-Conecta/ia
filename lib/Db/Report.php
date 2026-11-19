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
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Report extends Entity {
	public const MANUAL = 'manual';

	// Null until set: Entity writes only the fields a setter changed.
	protected ?int $fileId = null;
	protected ?string $etag = null;
	protected ?string $origin = null;
	protected ?string $requester = null;
	protected ?string $findings = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
