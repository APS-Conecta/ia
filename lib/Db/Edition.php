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
 * @method string getUntil()
 * @method void setUntil(string $until)
 * @method bool|null getLate()
 * @method void setLate(bool $late)
 * @method string|null getFingerprint()
 * @method void setFingerprint(?string $fingerprint)
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
	/** The end of its period, exclusive (Y-m-d): the next Edition's date as the schedule had it then. */
	protected ?string $until = null;
	/** Whether the Off hours of its date ended before it was published. */
	protected ?bool $late = null;
	/** Its Sources' state when it was opened (Db\Changes), for a Bulletin that comes when they change. */
	protected ?string $fingerprint = null;
	protected ?string $status = null;
	protected ?string $resumen = null;
	protected ?string $files = null;
	protected ?int $publishedAt = null;

	public function __construct() {
		$this->addType('bulletinId', 'integer');
		$this->addType('late', 'boolean');
		$this->addType('publishedAt', 'integer');
	}
}
