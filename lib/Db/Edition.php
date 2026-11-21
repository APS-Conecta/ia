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
	/** Its Editors took it back: Readers no longer see it, and its Editors can undo that. */
	public const WITHDRAWN = 'withdrawn';

	// Null until set: Entity writes only the fields a setter changed.
	protected ?int $bulletinId = null;
	protected ?string $date = null;
	/**
	 * The end of its period, exclusive (Y-m-d): the next Edition's date as the schedule had it then;
	 * for a Bulletin that comes «cuando cambien sus fuentes», a week on, then the next Edition's date.
	 */
	protected ?string $until = null;
	/** Whether the Off hours of its date ended before it was published. */
	protected ?bool $late = null;
	protected ?string $status = null;
	protected ?string $resumen = null;
	/** The Editor who last corrected the Resumen; null while it is the Model's. */
	protected ?string $correctedBy = null;
	protected ?string $files = null;
	protected ?int $publishedAt = null;

	/** Whether it was published: published, or withdrawn since. */
	public function wasPublished(): bool {
		return in_array($this->getStatus(), [self::PUBLISHED, self::WITHDRAWN], true);
	}

	public function isWithdrawn(): bool {
		return $this->getStatus() === self::WITHDRAWN;
	}

	public function __construct() {
		$this->addType('bulletinId', 'integer');
		$this->addType('late', 'boolean');
		$this->addType('publishedAt', 'integer');
	}
}
