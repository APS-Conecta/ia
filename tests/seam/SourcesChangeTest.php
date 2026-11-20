<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * «cuando cambien sus fuentes» (APS-Conecta/ia#13): such a Bulletin gets an Edition only after one
 * of its Sources changed from the state its Editions are up to: a calendar's sync token (an event
 * in it), a folder's etag (a file landing in it). A later date comes by moving the server's
 * timezone from Pago Pago's (UTC−11) to Kiritimati's (UTC+14).
 */
final class SourcesChangeTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	private const WRITING = 900;

	private static string $ana;
	private static int $bulletin;

	public static function setUpBeforeClass(): void {
		self::timezone('Pacific/Pago_Pago');
		self::offHoursNow();
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

	public function testNothingComesUntilItsCalendarChangesThenOneEdition(): void {
		self::tick();
		self::tick();
		self::assertNull(self::writing(), 'no change, no Edition');
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//ia seam//ES\r\nBEGIN:VEVENT\r\nUID:cambio-" . bin2hex(random_bytes(4))
			. "\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20270105T130000Z\r\nDTEND:20270105T140000Z\r\nSUMMARY:Reunión de turnos\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		self::assertSame(201, self::dav(self::$ana, 'PUT', '/remote.php/dav/calendars/' . self::$ana . '/agenda/cambio.ics', $ics, 'text/calendar'));
		self::assertCount(1, self::published(self::$ana, self::$bulletin, 1, self::WRITING));
	}

	/** @depends testNothingComesUntilItsCalendarChangesThenOneEdition */
	public function testOnALaterDateNothingComesUntilAFileLandsInItsFolder(): void {
		self::timezone('Pacific/Kiritimati');
		self::offHoursNow();
		self::tick();
		self::tick();
		self::assertNull(self::writing(), 'its Editions are up to the calendar change');
		self::assertCount(1, self::editions(self::$ana, self::$bulletin));
		self::upload(self::$ana, 'Turnos/refuerzo.txt', "Refuerzo de turnos: los sábados atiende un segundo médico.\n");
		$editions = self::published(self::$ana, self::$bulletin, 2, self::WRITING);
		self::assertGreaterThan($editions[1]['date'], $editions[0]['date'], 'a new Edition, on the later date, once the folder changed');
	}

	/** @return array<string, mixed>|null the Edition being written, as the Bulletin's Editor sees it */
	private static function writing(): ?array {
		return self::ocs(self::$ana, 'GET', self::API . '/bulletins/' . self::$bulletin)['data']['bulletin']['writing'];
	}
}
