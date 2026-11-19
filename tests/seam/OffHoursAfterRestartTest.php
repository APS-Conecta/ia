<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Group;

/**
 * A long document waits for Off hours (APS-Conecta/ia#6), second phase, after Nextcloud restarted
 * between two of its steps: the Run resumes from its last finished step and its report is complete.
 */
#[Group('restart-b')]
final class OffHoursAfterRestartTest extends SeamTestCase {
	private const WAIT = 1800;

	public function testAfterARestartTheRunResumesFromItsLastFinishedStepAndTheRequesterIsTold(): void {
		['people' => [$owner], 'id' => $id] = self::takeOver();
		$waiting = self::ocs($owner, 'GET', "/apps/ia/api/v1/reviews/$id")['data']['review'];
		self::assertSame(['waiting', 2], [$waiting['state'], $waiting['step']], 'the restart kept the Run and its progress');

		self::offHoursNow();
		self::tick();
		$resumed = self::ocs($owner, 'GET', "/apps/ia/api/v1/reviews/$id")['data']['review'];
		self::assertSame(['working', 2], [$resumed['state'], $resumed['step']], 'it resumes from step 2, not from the start');

		$review = self::waitFor($owner, $id, ['done', 'failed'], self::WAIT);
		self::offHoursLater();
		self::assertSame('done', $review['state'], 'the Review failed: ' . ($review['error'] ?? ''));
		$text = OffHoursBeforeRestartTest::longText();
		foreach ($review['findings'] as $finding) {
			self::assertContains($finding['kind'], ['error', 'missing', 'unclear']);
			if ($finding['quote'] !== null) {
				self::assertStringContainsString($finding['quote'], $text);
			}
		}
		$told = array_filter(self::ocs($owner, 'GET', '/apps/notifications/api/v2/notifications')['data'],
			static fn (array $notification): bool => $notification['app'] === 'ia' && $notification['object_id'] === (string)$id);
		self::assertCount(1, $told, 'the requester is told when it is ready');
		self::assertSame([], self::tasks());
	}
}
