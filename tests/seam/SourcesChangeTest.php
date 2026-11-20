<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use RuntimeException;

/**
 * «cuando cambien sus fuentes» (APS-Conecta/ia#13): such a Bulletin gets its first Edition in its
 * first Off hours, then one only after a Source changed: here, an event in its calendar Source
 * renews the calendar's sync token. A later date comes by moving the server's timezone from Pago
 * Pago's (UTC−11) to Kiritimati's (UTC+14).
 */
final class SourcesChangeTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	private const WRITING = 900;

	private static ?string $timezone = null;
	private static string $ana;
	private static int $bulletin;

	public static function setUpBeforeClass(): void {
		try {
			self::$timezone = trim(self::occ('config:system:get', 'default_timezone'));
		} catch (RuntimeException) {
			self::$timezone = null;
		}
		self::occ('config:system:set', 'default_timezone', '--value=Pacific/Pago_Pago');
		self::$ana = self::person();
		$editors = self::group(self::$ana);
		self::editorGroups($editors);
		$folder = self::folder(self::$ana, 'Turnos');
		self::upload(self::$ana, 'Turnos/turnos.txt', "Turnos de urgencia: el SAPU atiende de 17:00 a 08:00.\n");
		self::occ('dav:create-calendar', self::$ana, 'agenda');
		$calendar = array_values(array_filter(self::ocs(self::$ana, 'GET', self::API . '/calendars')['data']['calendars'], static fn (array $calendar): bool => $calendar['name'] === 'agenda'))[0];
		$created = self::ocs(self::$ana, 'POST', self::API . '/bulletins', [
			'name' => 'Boletín de turnos',
			'editorGroup' => $editors,
			'sources' => [['type' => 'folder', 'id' => (string)$folder], ['type' => 'calendar', 'id' => $calendar['id']]],
			'readers' => [$editors],
			'schedule' => ['kind' => 'sources'],
		]);
		self::assertSame(201, $created['status'], json_encode($created['data']));
		self::$bulletin = $created['data']['bulletin']['id'];
	}

	public static function tearDownAfterClass(): void {
		self::occ(...(self::$timezone === null ? ['config:system:delete', 'default_timezone'] : ['config:system:set', 'default_timezone', '--value=' . self::$timezone]));
		parent::tearDownAfterClass();
	}

	public function testItsFirstEditionComesInItsFirstOffHours(): void {
		self::offHoursNow();
		self::assertCount(1, self::published(1));
	}

	/** @depends testItsFirstEditionComesInItsFirstOffHours */
	public function testOnALaterDateNothingComesUntilASourceChanges(): void {
		self::occ('config:system:set', 'default_timezone', '--value=Pacific/Kiritimati');
		self::offHoursNow();
		self::tick();
		self::tick();
		self::assertNull(self::ocs(self::$ana, 'GET', self::API . '/bulletins/' . self::$bulletin)['data']['bulletin']['writing'], 'no change, no Edition');
		self::assertCount(1, self::editions());

		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//ia seam//ES\r\nBEGIN:VEVENT\r\nUID:cambio-" . bin2hex(random_bytes(4))
			. "\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20270105T130000Z\r\nDTEND:20270105T140000Z\r\nSUMMARY:Reunión de turnos\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		self::assertSame(201, self::dav(self::$ana, 'PUT', '/remote.php/dav/calendars/' . self::$ana . '/agenda/cambio.ics', $ics, 'text/calendar'));
		$editions = self::published(2);
		self::assertGreaterThan($editions[1]['date'], $editions[0]['date'], 'a new Edition, on the later date, once the calendar changed');
	}

	/** @return list<array<string, mixed>> the Bulletin's Editions its Editor sees */
	private static function editions(): array {
		return self::ocs(self::$ana, 'GET', self::API . '/bulletins/' . self::$bulletin . '/editions')['data']['editions'];
	}

	/**
	 * Ticks as the scheduler would until there are $count Editions, or fails after WRITING seconds.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function published(int $count): array {
		$until = time() + self::WRITING;
		do {
			self::tick();
			$editions = self::editions();
			if (count($editions) >= $count) {
				return $editions;
			}
			sleep(5);
		} while (time() < $until);
		self::fail("There were not $count Editions after " . self::WRITING . ' s');
	}
}
