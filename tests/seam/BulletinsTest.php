<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * Bulletin set-up (APS-Conecta/ia#7): an Editor group's members set up its Bulletins; Sources must
 * open for the whole Editor group; the preview counts the Readers and names who is left out.
 */
final class BulletinsTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1/bulletins';
	private const WEEKLY = ['kind' => 'weekly', 'day' => 1];

	private static string $ana;
	private static string $beto;
	private static string $editors;
	private static int $folder;

	public static function setUpBeforeClass(): void {
		self::$ana = self::person();
		self::$beto = self::person();
		self::$editors = self::group(self::$ana, self::$beto);
		self::editorGroups(self::$editors);
		self::$folder = self::folder(self::$ana, 'Protocolos');
	}

	public function testASourceTheWholeEditorGroupCannotOpenIsRefusedNamingWho(): void {
		$answer = self::ocs(self::$ana, 'POST', self::API, self::draft(self::$editors, self::$folder));
		self::assertSame(422, $answer['status']);
		self::assertStringContainsString('Protocolos', $answer['data']['message']);
		self::assertStringContainsString(self::$beto, $answer['data']['message'], 'it names the member who can\'t open it');
	}

	public function testAnEditorSetsUpABulletinAndSeesWhenItIsDue(): int {
		self::shareWithGroup(self::$ana, '/Protocolos', self::$editors);
		$created = self::ocs(self::$ana, 'POST', self::API, self::draft(self::$editors, self::$folder));
		self::assertSame(201, $created['status'], json_encode($created['data']));
		$bulletin = $created['data']['bulletin'];
		self::assertSame(['editor', self::$editors], [$bulletin['role'], $bulletin['editorGroup']['id']]);
		self::assertSame([['type' => 'folder', 'id' => (string)self::$folder, 'name' => 'Protocolos']], $bulletin['sources']);
		self::assertSame(self::WEEKLY, $bulletin['schedule']);
		self::assertSame('1', (new \DateTimeImmutable($bulletin['due']))->format('N'), 'a weekly Bulletin on Mondays is due on a Monday');
		return $bulletin['id'];
	}

	/** @depends testAnEditorSetsUpABulletinAndSeesWhenItIsDue */
	public function testAnyMemberOfTheEditorGroupChangesItAndAnyoneElseIsForbidden(int $id): void {
		self::assertSame([['id' => $id, 'name' => 'Boletín clínico', 'role' => 'editor']], self::ocs(self::$beto, 'GET', self::API)['data']['bulletins']);
		$changed = self::ocs(self::$beto, 'PUT', self::API . "/$id", ['name' => 'Boletín clínico semanal'] + self::draft(self::$editors, self::$folder, ['kind' => 'sources']));
		self::assertSame(200, $changed['status'], json_encode($changed['data']));
		self::assertSame(['kind' => 'sources', 'day' => null], $changed['data']['bulletin']['schedule']);
		self::assertNull($changed['data']['bulletin']['due'], '«cuando cambien sus fuentes» has no date');

		$someone = self::person();
		self::assertSame([], self::ocs($someone, 'GET', self::API)['data']['bulletins']);
		self::assertSame(403, self::ocs($someone, 'GET', self::API . "/$id")['status']);
		self::assertSame(403, self::ocs($someone, 'PUT', self::API . "/$id", self::draft(self::$editors, self::$folder))['status']);
		self::assertSame(403, self::ocs($someone, 'DELETE', self::API . "/$id")['status']);
		self::assertSame(403, self::ocs($someone, 'POST', self::API, self::draft(self::$editors, self::$folder))['status']);
	}

	/** @depends testAnEditorSetsUpABulletinAndSeesWhenItIsDue */
	public function testAReaderSeesTheBulletinTheyRead(int $id): void {
		$reader = self::person();
		$readers = self::group($reader);
		self::ocs(self::$ana, 'PUT', self::API . "/$id", self::draft(self::$editors, self::$folder, self::WEEKLY, [$readers]));
		self::assertSame([['id' => $id, 'name' => 'Boletín clínico', 'role' => 'reader']], self::ocs($reader, 'GET', self::API)['data']['bulletins']);
		$read = self::ocs($reader, 'GET', self::API . "/$id")['data']['bulletin'];
		self::assertSame(['reader', [], []], [$read['role'], $read['sources'], $read['readers']], 'a Reader sees neither Sources nor Reader groups');
	}

	public function testThePreviewCountsTheReadersAndNamesThoseLeftOut(): void {
		$outsider = self::person();
		$readers = self::group(self::$beto, $outsider);
		$preview = self::ocs(self::$ana, 'POST', self::API . '/preview', ['sources' => [['type' => 'folder', 'id' => (string)self::$folder]], 'readers' => [$readers]]);
		self::assertSame(200, $preview['status'], json_encode($preview['data']));
		self::assertSame(1, $preview['data']['readers'], 'Beto opens the folder through the Editor group');
		self::assertSame([$outsider], array_column($preview['data']['leftOut'], 'id'));
		self::assertSame(['Protocolos'], $preview['data']['leftOut'][0]['sources'], 'each one left out is told which Source they can\'t open');
		self::assertSame(403, self::ocs(self::person(), 'POST', self::API . '/preview', ['sources' => [], 'readers' => [$readers]])['status']);
	}

	public function testADocumentIsASourceAndAnUnknownReaderGroupIsRefused(): void {
		$document = self::upload(self::$ana, 'circular.txt', "Circular 14: cambio de horario de la sala de procedimientos.\n");
		self::shareWithGroup(self::$ana, '/circular.txt', self::$editors);
		$created = self::ocs(self::$ana, 'POST', self::API, ['sources' => [['type' => 'file', 'id' => (string)$document]]] + self::draft(self::$editors, self::$folder));
		self::assertSame(201, $created['status'], json_encode($created['data']));
		self::assertSame([['type' => 'file', 'id' => (string)$document, 'name' => 'circular.txt']], $created['data']['bulletin']['sources']);
		$unknown = self::ocs(self::$ana, 'POST', self::API, self::draft(self::$editors, self::$folder, self::WEEKLY, ['no-such-group-' . bin2hex(random_bytes(4))]));
		self::assertSame(422, $unknown['status']);
	}

	public function testASubscriptionIsNoCalendarSource(): void {
		$body = '<?xml version="1.0"?><d:mkcol xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:set><d:prop>'
			. '<d:resourcetype><d:collection/><cs:subscribed/></d:resourcetype><d:displayname>suscripcion-feriados</d:displayname>'
			. '<cs:source><d:href>https://example.com/feriados.ics</d:href></cs:source></d:prop></d:set></d:mkcol>';
		self::assertSame(201, self::dav(self::$ana, 'MKCOL', '/remote.php/dav/calendars/' . self::$ana . '/feriados', $body));
		$names = array_column(self::ocs(self::$ana, 'GET', '/apps/ia/api/v1/calendars')['data']['calendars'], 'name');
		self::assertNotContains('suscripcion-feriados', $names, 'a subscription\'s key names a row of another table');
	}

	public function testACalendarIsASourceOnlyWhenTheWholeEditorGroupHasIt(): void {
		self::occ('dav:create-calendar', self::$ana, 'turnos');
		$calendars = self::ocs(self::$ana, 'GET', '/apps/ia/api/v1/calendars')['data']['calendars'];
		$turnos = array_values(array_filter($calendars, static fn (array $calendar): bool => $calendar['name'] === 'turnos'));
		self::assertCount(1, $turnos);
		$calendar = ['type' => 'calendar', 'id' => $turnos[0]['id']];

		$refused = self::ocs(self::$ana, 'POST', self::API, ['sources' => [$calendar]] + self::draft(self::$editors, self::$folder));
		self::assertSame(422, $refused['status']);
		self::assertStringContainsString(self::$beto, $refused['data']['message']);

		$alone = self::group(self::$ana);
		self::editorGroups(self::$editors, $alone);
		$created = self::ocs(self::$ana, 'POST', self::API, ['sources' => [$calendar]] + self::draft($alone, self::$folder));
		self::assertSame(201, $created['status'], json_encode($created['data']));
		self::assertSame('turnos', $created['data']['bulletin']['sources'][0]['name']);
	}

	public function testEachScheduleIsAcceptedAndAnythingElseIsRefused(): void {
		self::shareWithGroup(self::$ana, '/Protocolos', self::$editors);
		$timezone = self::ocs(self::admin(), 'GET', '/apps/ia/api/v1/admin/settings')['data']['timezone'];
		$today = new \DateTimeImmutable('today', new \DateTimeZone($timezone));
		$due = [];
		foreach (['daily' => ['kind' => 'daily'], 'weekly' => ['kind' => 'weekly', 'day' => 7], 'monthly 28' => ['kind' => 'monthly', 'day' => 28], 'monthly 1' => ['kind' => 'monthly', 'day' => 1], 'sources' => ['kind' => 'sources']] as $label => $schedule) {
			$created = self::ocs(self::$ana, 'POST', self::API, self::draft(self::$editors, self::$folder, $schedule));
			self::assertSame(201, $created['status'], json_encode($schedule));
			$due[$label] = $created['data']['bulletin']['due'];
		}
		self::assertSame($today->modify('+1 day')->format('Y-m-d'), $due['daily']);
		foreach (['monthly 28' => '28', 'monthly 1' => '01'] as $label => $day) {
			self::assertSame($day, substr($due[$label], -2));
			self::assertGreaterThan($today->format('Y-m-d'), $due[$label], 'a monthly Edition is due after today, the next month when its day has passed');
		}
		self::assertNull($due['sources']);
		foreach ([['kind' => 'hourly'], ['kind' => 'weekly', 'day' => 8], ['kind' => 'monthly', 'day' => 31], ['kind' => 'weekly']] as $schedule) {
			self::assertSame(422, self::ocs(self::$ana, 'POST', self::API, self::draft(self::$editors, self::$folder, $schedule))['status'], json_encode($schedule));
		}
	}

	public function testDeletedIsGoneAndARemovedEditorGroupStopsEditingAtOnce(): void {
		self::shareWithGroup(self::$ana, '/Protocolos', self::$editors);
		$id = self::ocs(self::$ana, 'POST', self::API, self::draft(self::$editors, self::$folder))['data']['bulletin']['id'];
		self::editorGroups();
		self::assertSame(403, self::ocs(self::$ana, 'PUT', self::API . "/$id", self::draft(self::$editors, self::$folder))['status']);
		self::editorGroups(self::$editors);
		self::assertSame(200, self::ocs(self::$ana, 'DELETE', self::API . "/$id")['status']);
		self::assertSame(404, self::ocs(self::$ana, 'GET', self::API . "/$id")['status']);
	}

	/**
	 * A Bulletin as the sidebar sends it.
	 *
	 * @param array<string, mixed> $schedule
	 * @param list<string> $readers
	 * @return array<string, mixed>
	 */
	private static function draft(string $editors, int $folder, array $schedule = self::WEEKLY, ?array $readers = null): array {
		return [
			'name' => 'Boletín clínico',
			'editorGroup' => $editors,
			'sources' => [['type' => 'folder', 'id' => (string)$folder]],
			'readers' => $readers ?? [$editors],
			'schedule' => $schedule,
		];
	}
}
