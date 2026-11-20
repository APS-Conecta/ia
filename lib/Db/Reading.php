<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A Reading (CONTEXT.md): what the Model took, once, from one version (etag) of a file: a short
 * summary, its expiry date (Y-m-d) and the Cargos it concerns (JSON). One per file: a newer
 * version replaces it. Status «read», «scan» (no text layer, not read in v1) or «failed».
 *
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getEtag()
 * @method void setEtag(string $etag)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method string|null getExpires()
 * @method void setExpires(?string $expires)
 * @method string|null getCargos()
 * @method void setCargos(?string $cargos)
 * @method int getReadAt()
 * @method void setReadAt(int $readAt)
 */
class Reading extends Entity {
	public const READ = 'read';
	public const SCAN = 'scan';
	public const FAILED = 'failed';

	// Null until set: Entity writes only the fields a setter changed.
	protected ?int $fileId = null;
	protected ?string $etag = null;
	protected ?string $status = null;
	protected ?string $summary = null;
	protected ?string $expires = null;
	protected ?string $cargos = null;
	protected ?int $readAt = null;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('readAt', 'integer');
	}

	/** @return list<string> */
	public function cargoList(): array {
		return json_decode((string)$this->cargos, true) ?: [];
	}
}
