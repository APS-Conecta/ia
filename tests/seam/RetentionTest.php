<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Depends;

/**
 * Retention (APS-Conecta/ia#16), through «IA»'s daily job run days on (libfaketime): a report goes
 * after 90 days, an Edition after 12 months, and a task of «IA» that ended over a day ago and that
 * nothing took goes too. That leftover is a Model health probe a moved tick sends and the worker
 * answers on that clock, which no later tick takes.
 */
final class RetentionTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	/** Seconds the Model may take for a short Review on the CPU, behind other Runs. */
	private const STEP = 600;
	/** Seconds for an Edition without the Model, behind the other Runs in line. */
	private const EDITION = 900;

	private static string $ana;
	private static string $carla;
	private static int $review;
	private static int $bulletin;
	private static int $edition;

	public static function setUpBeforeClass(): void {
		self::offHoursNow();
		self::$ana = self::person();
		self::$carla = self::person();
		$editors = self::group(self::$ana);
		$readers = self::group(self::$carla);
		self::editorGroups($editors);

		$fileId = self::upload(self::$ana, 'curacion.txt', (string)file_get_contents(__DIR__ . '/fixtures/curacion-corto.txt'));
		$created = self::ocs(self::$ana, 'POST', self::API . '/reviews', ['fileId' => $fileId]);
		self::assertSame(201, $created['status'], json_encode($created['data']));
		self::$review = $created['data']['review']['id'];
		self::waitFor(self::$ana, self::$review, ['done'], self::STEP);

		$folder = self::folder(self::$ana, 'Vacía');
		self::shareWithGroup(self::$ana, '/Vacía', $readers);
		$bulletin = self::ocs(self::$ana, 'POST', self::API . '/bulletins', [
			'name' => 'Boletín que se borra ' . bin2hex(random_bytes(3)),
			'editorGroup' => $editors,
			'sources' => [['type' => 'folder', 'id' => (string)$folder]],
			'readers' => [$readers],
			'schedule' => ['kind' => 'daily'],
		]);
		self::assertSame(201, $bulletin['status'], json_encode($bulletin['data']));
		self::$bulletin = $bulletin['data']['bulletin']['id'];
		self::$edition = self::published(self::$carla, self::$bulletin, 1, self::EDITION)[0]['id'];

		// A moved tick sends a probe, or first takes one already answered and sends the next half an
		// hour on; the worker answers it on that clock.
		for ($minutes = 31; self::probes() === [] && $minutes <= 93; $minutes += 31) {
			self::tick($minutes);
			self::work($minutes);
		}
		self::assertSame(['STATUS_SUCCESSFUL'], array_column(self::probes(), 'status'), 'a task of «IA» that ended and nothing took');
	}

	public function testOnlyTheLeftoverTaskGoesBeforeTheirTime(): void {
		self::retention(89);
		self::assertSame([], self::probes(), 'it ended over a day ago');
		self::assertSame(200, self::ocs(self::$ana, 'GET', self::API . '/reviews/' . self::$review)['status'], 'a report of 89 days stays');
		self::assertSame(self::$edition, self::edition(self::$carla, self::$edition)['id'], 'and an Edition');
	}

	#[Depends('testOnlyTheLeftoverTaskGoesBeforeTheirTime')]
	public function testAReportGoesAfter90Days(): void {
		self::retention(91);
		self::assertSame(404, self::ocs(self::$ana, 'GET', self::API . '/reviews/' . self::$review)['status']);
		self::assertSame([], self::ocs(self::$ana, 'GET', self::API . '/reviews')['data']['reviews']);
		self::assertSame(self::$edition, self::edition(self::$carla, self::$edition)['id'], 'an Edition stays');
	}

	#[Depends('testAReportGoesAfter90Days')]
	public function testAnEditionGoesAfter12Months(): void {
		// A year and a few days: an Edition written in Off hours that end after midnight is for the next day.
		self::retention(370);
		self::assertSame(404, self::ocs(self::$carla, 'GET', self::API . '/editions/' . self::$edition)['status']);
		self::assertSame([], self::editions(self::$carla, self::$bulletin));
	}
}
