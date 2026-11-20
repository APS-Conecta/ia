<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * An Edition (CONTEXT.md): one run of a Bulletin, for its date. Written while «writing», then
 * «published» whole. `files` holds every document of its Sources, as it found them: the version
 * (etag) and name, whether it is new or changed since the previous Edition, and its Reading's
 * status, expiry date and Cargos. The Reading's summary is not copied: it stays in the Reading,
 * which goes with its file.
 *
 * @method int getBulletinId()
 * @method void setBulletinId(int $bulletinId)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getResumen()
 * @method void setResumen(?string $resumen)
 * @method string|null getFiles()
 * @method void setFiles(?string $files)
 * @method int|null getPublishedAt()
 * @method void setPublishedAt(?int $publishedAt)
 */
class Edition extends Entity {
	public const WRITING = 'writing';
	public const PUBLISHED = 'published';

	// Null until set: Entity writes only the fields a setter changed.
	protected ?int $bulletinId = null;
	protected ?string $date = null;
	protected ?string $status = null;
	protected ?string $resumen = null;
	protected ?string $files = null;
	protected ?int $publishedAt = null;

	public function __construct() {
		$this->addType('bulletinId', 'integer');
		$this->addType('publishedAt', 'integer');
	}
}
