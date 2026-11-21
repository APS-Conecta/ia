<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\BackgroundJob;

use DateTimeImmutable;
use OCA\Ia\Bulletin\Bulletins;
use OCA\Ia\Model\ModelGateway;
use OCA\Ia\Review\Reviews;
use OCA\Ia\Run\Runs;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Retention (gestion-aio#162, APS-Conecta/ia#16), once a day in Nextcloud's maintenance window:
 * reports go after 90 days and Editions after 12 months, each with its Run and notifications, so
 * old copies of document content don't pile up; «IA»'s TaskProcessing tasks that ended and nothing
 * waits for go too.
 */
class RetentionJob extends TimedJob {
	public const INTERVAL = 24 * 60 * 60;
	private const REPORT_RETENTION = '-90 days';
	private const EDITION_RETENTION = '-12 months';

	public function __construct(
		ITimeFactory $time,
		private Reviews $reviews,
		private Bulletins $bulletins,
		private Runs $runs,
		private ModelGateway $model,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$now = DateTimeImmutable::createFromInterface($this->time->getDateTime());
		$this->reviews->forgetBefore($now->modify(self::REPORT_RETENTION)->getTimestamp());
		$this->bulletins->forgetEditionsBefore($now->modify(self::EDITION_RETENTION)->format('Y-m-d'));
		$this->model->sweep($now->getTimestamp(), $this->runs->awaited());
	}
}
