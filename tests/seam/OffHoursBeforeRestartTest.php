<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Group;

/**
 * A long document waits for Off hours (APS-Conecta/ia#6), first phase: `make test-seam` restarts
 * Nextcloud after it, and OffHoursAfterRestartTest takes over.
 */
#[Group('restart-a')]
final class OffHoursBeforeRestartTest extends SeamTestCase {
	/** Seconds one step of the long document may take on a CPU runner. */
	private const STEP = 900;

	/** Over the short document size (about 10 pages) and two chunks long: three steps. */
	public static function longText(): string {
		$long = (string)file_get_contents(__DIR__ . '/fixtures/procedimientos-largo.md');
		$short = (string)file_get_contents(__DIR__ . '/fixtures/curacion-corto.txt');
		return $long . str_repeat("\n\n" . $short, 4);
	}

	public function testALongDocumentWaitsForOffHoursAndStopsBetweenStepsWhenTheyEnd(): void {
		self::offHoursLater();
		$owner = self::person();
		$fileId = self::upload($owner, 'manual-largo.md', self::longText());

		$created = self::ocs($owner, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => $fileId]);
		self::assertSame(201, $created['status']);
		self::assertSame('offHours', $created['data']['start']);
		$id = $created['data']['review']['id'];
		self::assertSame(['waiting', true, 3], [$created['data']['review']['state'], $created['data']['review']['offHours'], $created['data']['review']['steps']]);

		$twice = self::ocs($owner, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => $fileId]);
		self::assertSame(200, $twice['status'], 'a second request for the same file makes no second Run');
		self::assertSame($id, $twice['data']['review']['id']);
		self::assertSame(['waiting'], array_column(self::ocs($owner, 'GET', '/apps/ia/api/v1/reviews')['data']['reviews'], 'state'));

		self::tick();
		self::assertSame('waiting', self::ocs($owner, 'GET', "/apps/ia/api/v1/reviews/$id")['data']['review']['state'], 'nothing starts outside Off hours');
		self::assertSame([], self::tasks());

		$other = self::person();
		$second = self::ocs($other, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => self::upload($other, 'otro-largo.md', self::longText())])['data']['review']['id'];
		self::offHoursNow();
		self::tick();
		self::tick();
		self::assertSame('working', self::ocs($owner, 'GET', "/apps/ia/api/v1/reviews/$id")['data']['review']['state'], 'the oldest Run starts first');
		self::assertSame('waiting', self::ocs($other, 'GET', "/apps/ia/api/v1/reviews/$second")['data']['review']['state']);
		self::assertCount(1, self::tasks(), 'never more than one Off-hours task in flight');

		self::offHoursLater();
		$paused = self::waitUntil($owner, $id, static fn (array $review): bool => $review['state'] === 'waiting', self::STEP, 'back in line after its first step');
		self::assertSame(2, $paused['step'], 'it stops after the step under way when Off hours end');
		self::assertSame([], self::tasks());

		self::handOver(['people' => [$owner], 'id' => $id]);
	}
}
