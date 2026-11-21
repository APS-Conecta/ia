<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Depends;

/**
 * Retention (APS-Conecta/ia#16), through «IA»'s daily job run days on (libfaketime): a report goes
 * after 90 days and an Edition after 12 months, each with its notification; a task of «IA» that
 * ended and nothing waits for goes, while the probe Health waits for and another app's task stay.
 * The tasks are seeded through core's TaskProcessing API: the leftover with the probe's custom id,
 * which no Run waits for and Health doesn't track.
 */
final class RetentionTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	/** Seconds the Model may take for a short Review on the CPU, behind other Runs. */
	private const STEP = 600;
	/** Seconds for an Edition without the Model, behind the other Runs in line. */
	private const EDITION = 900;

	private static string $ana;
	private static string $carla;
	private static string $name;
	private static int $review;
	private static int $bulletin;
	private static int $edition;
	private static int $tracked;
	private static int $leftover;
	private static int $other;

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
		self::$name = 'Boletín que se borra ' . bin2hex(random_bytes(3));
		$bulletin = self::ocs(self::$ana, 'POST', self::API . '/bulletins', [
			'name' => self::$name,
			'editorGroup' => $editors,
			'sources' => [['type' => 'folder', 'id' => (string)$folder]],
			'readers' => [$readers],
			'schedule' => ['kind' => 'daily'],
		]);
		self::assertSame(201, $bulletin['status'], json_encode($bulletin['data']));
		self::$bulletin = $bulletin['data']['bulletin']['id'];
		self::$edition = self::published(self::$carla, self::$bulletin, 1, self::EDITION)[0]['id'];

		// The probe Health waits for: a moved tick sends a new one (or first takes one already answered
		// and sends the next half an hour on), and the worker answers it on that clock.
		$before = array_column(self::probes(), 'id');
		for ($minutes = 31; ($new = array_diff(array_column(self::probes(), 'id'), $before)) === [] && $minutes <= 93; $minutes += 31) {
			self::tick($minutes);
			self::work($minutes);
		}
		self::assertCount(1, $new, 'Health sent a probe');
		self::$tracked = (int)reset($new);
		self::$leftover = self::seed('ia', 'health');
		self::$other = self::seed('otra', 'x');
		$until = time() + self::STEP;
		while (array_intersect(array_column([...self::probes(), ...self::otherTasks()], 'status'), ['STATUS_SCHEDULED', 'STATUS_RUNNING']) !== [] && time() < $until) {
			sleep(5);
		}
		$ended = array_intersect_key(array_column([...self::probes(), ...self::otherTasks()], 'status', 'id'), array_flip([self::$tracked, self::$leftover, self::$other]));
		ksort($ended);
		$expected = [self::$tracked => 'STATUS_SUCCESSFUL', self::$leftover => 'STATUS_SUCCESSFUL', self::$other => 'STATUS_SUCCESSFUL'];
		ksort($expected);
		self::assertSame($expected, $ended, 'all three ended');
	}

	public function testAt89DaysOnlyTheLeftoverTaskGoes(): void {
		self::assertCount(1, self::notified(self::$ana, 'Revisión lista'));
		self::retention(89);
		self::assertContains(self::$tracked, array_column(self::probes(), 'id'), 'the probe Health waits for stays');
		self::assertNotContains(self::$leftover, array_column(self::probes(), 'id'), 'the leftover goes');
		self::assertContains(self::$other, array_column(self::otherTasks(), 'id'), 'and another app\'s task');
		self::assertSame(200, self::ocs(self::$ana, 'GET', self::API . '/reviews/' . self::$review)['status'], 'a report of 89 days stays');
		self::assertSame(self::$edition, self::edition(self::$carla, self::$edition)['id'], 'and an Edition');
	}

	#[Depends('testAt89DaysOnlyTheLeftoverTaskGoes')]
	public function testAReportGoesAfter90DaysWithItsNotification(): void {
		self::retention(91);
		self::assertSame(404, self::ocs(self::$ana, 'GET', self::API . '/reviews/' . self::$review)['status']);
		self::assertSame([], self::ocs(self::$ana, 'GET', self::API . '/reviews')['data']['reviews']);
		self::assertSame([], self::notified(self::$ana, 'Revisión lista'));
		self::assertSame(self::$edition, self::edition(self::$carla, self::$edition)['id'], 'an Edition stays');
	}

	#[Depends('testAReportGoesAfter90DaysWithItsNotification')]
	public function testAnEditionGoesAfter12MonthsWithItsNotification(): void {
		self::assertCount(1, self::notified(self::$carla, 'Nueva edición: ' . self::$name));
		self::retention(360);
		self::assertSame(self::$edition, self::edition(self::$carla, self::$edition)['id'], 'an Edition of 360 days stays');
		// A year and a few days: an Edition written in Off hours that end after midnight is for the next day.
		self::retention(370);
		self::assertSame(404, self::ocs(self::$carla, 'GET', self::API . '/editions/' . self::$edition)['status']);
		self::assertSame([], self::editions(self::$carla, self::$bulletin));
		self::assertSame([], self::notified(self::$carla, 'Nueva edición: ' . self::$name));
	}

	/** Schedules a one-word task of $appId through core's TaskProcessing API; answers its id. */
	private static function seed(string $appId, string $customId): int {
		$scheduled = self::ocs(self::$ana, 'POST', '/taskprocessing/schedule', [
			'input' => ['input' => 'Responde solo con la palabra OK.'],
			'type' => 'core:text2text',
			'appId' => $appId,
			'customId' => $customId,
		]);
		self::assertSame(200, $scheduled['status'], json_encode($scheduled['data']));
		return $scheduled['data']['task']['id'];
	}

	/** @return list<array<string, mixed>> the other app's tasks */
	private static function otherTasks(): array {
		return json_decode(self::occ('taskprocessing:task:list', '--appId=otra', '--output=json'), true);
	}
}
