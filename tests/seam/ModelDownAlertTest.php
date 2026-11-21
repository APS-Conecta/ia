<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * The model-down alert (APS-Conecta/ia#8), with Ollama stopped (`make test-seam`'s model-down
 * group). Each tick, and TaskProcessing's worker after it, runs minutes later than now
 * (libfaketime), as on the server's own clock:
 * the Model is down from the first failed check, and still down half an hour later the admins are
 * told once and the setup check warns. A server stopped in between, as the nightly backup stops
 * it, starts the count again.
 */
#[Group('model-down')]
final class ModelDownAlertTest extends SeamTestCase {
	private const STATUS = '/apps/ia/api/v1/admin/status';
	private const ALERT = 'El modelo de «IA» no responde';

	private static string $admin;

	public static function setUpBeforeClass(): void {
		self::$admin = self::admin();
	}

	public function testTheFirstFailedCheckMarksTheModelDownAndTellsNobody(): int {
		// Over half an hour after its last answer: a probe goes out, and fails.
		self::later(31);
		self::later(35);
		$model = self::model();
		self::assertSame('down', $model['state']);
		self::assertFalse($model['alerted']);
		self::assertSame([], self::notified(self::$admin, self::ALERT));
		self::assertSame('info', self::setupCheck());
		return $model['since'];
	}

	#[Depends('testTheFirstFailedCheckMarksTheModelDownAndTellsNobody')]
	public function testAServerStoppedLongerThanAFewTicksStartsTheCountAgain(int $since): void {
		// 40 minutes without a tick, as during the nightly backup.
		self::later(75);
		$model = self::model();
		self::assertSame('down', $model['state']);
		self::assertGreaterThanOrEqual($since + 39 * 60, $model['since'], 'the count started again');
		self::assertFalse($model['alerted'], '40 minutes since the first failed check, but the server was stopped');
		self::assertSame([], self::notified(self::$admin, self::ALERT));
	}

	#[Depends('testAServerStoppedLongerThanAFewTicksStartsTheCountAgain')]
	public function testStillDownHalfAnHourOnTheAdminsAreToldOnceAndTheSetupCheckWarns(): void {
		foreach ([85, 95, 106] as $minutes) {
			self::later($minutes);
		}
		self::assertTrue(self::model()['alerted']);
		self::assertCount(1, self::notified(self::$admin, self::ALERT));
		self::assertSame('warning', self::setupCheck());
		self::later(111);
		self::assertCount(1, self::notified(self::$admin, self::ALERT), 'told once, however many ticks');
	}

	/** @return array<string, mixed> the Model's part of «Estado» */
	private static function model(): array {
		$answer = self::ocs(self::$admin, 'GET', self::STATUS);
		self::assertSame(200, $answer['status']);
		return $answer['data']['model'];
	}

	/** A tick $minutes later than now, then TaskProcessing's worker: the probe it sends fails. */
	private static function later(int $minutes): void {
		self::tick($minutes);
		self::work($minutes);
		self::assertSame([], array_intersect(array_column(self::probes(), 'status'), ['STATUS_SCHEDULED', 'STATUS_RUNNING']), 'the probe ended');
	}
}
