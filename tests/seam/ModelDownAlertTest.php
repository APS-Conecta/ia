<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * The model-down alert (APS-Conecta/ia#8), with Ollama stopped (`make test-seam`'s model-down
 * group). Each tick, and TaskProcessing's worker after it, runs minutes later than now
 * (libfaketime), as on the server's own clock: the Model is down from the first failed check, and
 * once it has been down half an hour the admins are told once and the setup check warns. The time
 * a server is stopped, as the nightly backup stops it, doesn't count.
 */
#[Group('model-down')]
final class ModelDownAlertTest extends SeamTestCase {
	private const ESTADO = '/apps/ia/api/v1/admin/estado';
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
	public function testTheTimeAServerIsStoppedDoesNotCount(int $since): void {
		self::later(45);
		// 40 minutes without a tick, as during the nightly backup.
		self::later(85);
		$model = self::model();
		self::assertSame('down', $model['state']);
		// Moved on by the 40 minutes, and the seconds the last tick and worker took.
		self::assertGreaterThanOrEqual($since + 40 * 60, $model['since'], 'the time it went down moved on by the stop');
		self::assertLessThan($since + 45 * 60, $model['since']);
		self::assertFalse($model['alerted'], '50 minutes since the first failed check, 10 of them with the server running');
		self::assertSame([], self::notified(self::$admin, self::ALERT));
	}

	#[Depends('testTheTimeAServerIsStoppedDoesNotCount')]
	public function testDownHalfAnHourTheAdminsAreToldOnceAndTheSetupCheckWarns(): void {
		self::later(95);
		self::assertFalse(self::model()['alerted'], '20 minutes counted');
		self::later(106);
		self::assertTrue(self::model()['alerted']);
		self::assertCount(1, self::notified(self::$admin, self::ALERT));
		self::assertSame('warning', self::setupCheck());
		self::later(111);
		self::assertCount(1, self::notified(self::$admin, self::ALERT), 'told once, however many ticks');
	}

	/** @return array<string, mixed> the Model's part of «Estado» */
	private static function model(): array {
		$answer = self::ocs(self::$admin, 'GET', self::ESTADO);
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
