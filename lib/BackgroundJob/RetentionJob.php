<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\BackgroundJob;

use DateTimeImmutable;
use OCA\Ia\Db\EditionMapper;
use OCA\Ia\Db\ReportMapper;
use OCA\Ia\Db\Run;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Model\ModelGateway;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Retention (gestion-aio#162, APS-Conecta/ia#16), once a day in the maintenance window: reports go
 * after 90 days and Editions after 12 months, each with its Run, so old copies of document content
 * don't pile up; «IA»'s TaskProcessing tasks that ended over a day ago, which no Run took, go too.
 */
class RetentionJob extends TimedJob {
	private const REPORTS = '-90 days';
	private const EDITIONS = '-12 months';
	private const TASKS = '-1 day';

	public function __construct(
		ITimeFactory $time,
		private ReportMapper $reports,
		private EditionMapper $editions,
		private RunMapper $runs,
		private ModelGateway $model,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$now = DateTimeImmutable::createFromInterface($this->time->getDateTime());
		$this->runs->forget(Run::REVIEW, array_map('strval', $this->reports->forgetBefore($now->modify(self::REPORTS)->getTimestamp())));
		$this->runs->forget(Run::EDITION, array_map('strval', $this->editions->forgetBefore($now->modify(self::EDITIONS)->format('Y-m-d'))));
		$this->model->sweep($now->modify(self::TASKS)->getTimestamp());
	}
}
