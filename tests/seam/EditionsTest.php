<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use RuntimeException;

/**
 * The first Edition (APS-Conecta/ia#10): on its date, in Off hours, a Bulletin's Run takes the
 * Readings it lacks, writes the Resumen with the real Model and publishes the Edition whole; its
 * Readers are told and see it only while they can open every file it read that is still there.
 * Each Reader's Personal list, «Para ti» and the Dashboard widget read it (APS-Conecta/ia#12).
 *
 * An Edition is for the date its Off hours end, in the server's timezone: the first one is written
 * at Pago Pago's time (UTC−11) and the next at Kiritimati's (UTC+14), whose date is always later.
 */
final class EditionsTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	/** The server's timezone for the first Edition (UTC−11); the next is written at Kiritimati's. */
	private const TIMEZONE = 'Pacific/Pago_Pago';
	private const CARGOS = ['Enfermera', 'Médico', 'TENS'];
	private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
	/** Seconds an Edition may take: four Readings and the Resumen on the CPU, behind other Bulletins' Runs. */
	private const WRITING = 900;

	private static ?string $timezone = null;
	private static string $ana;
	private static string $carla;
	private static string $dario;
	private static int $bulletin;
	/** A Bulletin whose only Source is a document «IA» doesn't read. */
	private static int $sheets;
	private static string $expires;
	private static string $sentinel;

	public static function setUpBeforeClass(): void {
		try {
			self::$timezone = trim(self::occ('config:system:get', 'default_timezone'));
		} catch (RuntimeException) {
			self::$timezone = null;
		}
		self::occ('config:system:set', 'default_timezone', '--value=' . self::TIMEZONE);
		self::$ana = self::person();
		$editors = self::group(self::$ana, self::person());
		self::$carla = self::person();
		self::$dario = self::person();
		// The Cargo list is the Cargo groups' ids; Carla is a nurse.
		$cargos = array_map(static fn (string $name): string => self::namedGroup($name), self::CARGOS);
		self::occ('group:adduser', $cargos[0], self::$carla);
		self::occ('config:app:set', 'ia', 'cargos', '--type=array', '--value=' . json_encode($cargos));
		$readers = self::group(self::$carla, self::$dario);
		self::editorGroups($editors);

		$expires = new \DateTimeImmutable('+12 days');
		self::$expires = $expires->format('Y-m-d');
		self::$sentinel = 'centinela-' . bin2hex(random_bytes(4));
		$folder = self::folder(self::$ana, 'Protocolos');
		self::upload(self::$ana, 'Protocolos/curaciones.txt', "Protocolo de curación avanzada de heridas\n"
			. "Dirigido a: Enfermera y Kinesiólogo.\n"
			. 'Objetivo: estandarizar la curación avanzada de heridas en el CESFAM (' . self::$sentinel . ").\n"
			. "Procedimiento: lavado por arrastre con suero fisiológico, valoración de la herida y elección del apósito según el exudado.\n"
			. 'Vigencia: este protocolo vence el ' . $expires->format('j') . ' de ' . self::MONTHS[(int)$expires->format('n') - 1] . ' de ' . $expires->format('Y') . ".\n");
		self::upload(self::$ana, 'Protocolos/visitas.md', self::visits(''));
		self::upload(self::$ana, 'Protocolos/escaneado.pdf', (string)file_get_contents(__DIR__ . '/fixtures/escaneado.pdf'));
		self::upload(self::$ana, 'Protocolos/fotos.png', 'not a document');
		$sheet = self::upload(self::$ana, 'turnos.xlsx', 'not a document «IA» reads');
		$reserved = self::upload(self::$ana, 'reservado.txt', "Protocolo reservado de manejo de agresiones al personal\n"
			. "Dirigido a: Médico.\nLa dirección coordina la denuncia y el apoyo al funcionario agredido.\n");
		foreach ([$editors, $readers] as $group) {
			self::shareWithGroup(self::$ana, '/Protocolos', $group);
			self::shareWithGroup(self::$ana, '/turnos.xlsx', $group);
		}
		self::shareWithGroup(self::$ana, '/reservado.txt', $editors);
		$shared = self::ocs(self::$ana, 'POST', '/apps/files_sharing/api/v1/shares', ['path' => '/reservado.txt', 'shareType' => 0, 'shareWith' => self::$carla, 'permissions' => 1]);
		self::assertSame(200, $shared['status']);

		self::$bulletin = self::bulletin('Boletín de protocolos', $editors, $readers, ['folder' => $folder, 'file' => $reserved, 'sheet' => $sheet]);
		self::$sheets = self::bulletin('Boletín de turnos', $editors, $readers, ['sheet' => $sheet]);
	}

	public static function tearDownAfterClass(): void {
		self::occ(...(self::$timezone === null ? ['config:system:delete', 'default_timezone'] : ['config:system:set', 'default_timezone', '--value=' . self::$timezone]));
		self::occ('config:app:delete', 'ia', 'cargos');
		parent::tearDownAfterClass();
	}

	public function testNothingShowsWhileTheEditionIsWritten(): void {
		self::offHoursNow();
		self::tick();
		self::assertSame([], self::editions(self::$ana));
		self::assertSame([], self::editions(self::$carla));
	}

	/** @depends testNothingShowsWhileTheEditionIsWritten */
	public function testItIsPublishedWholeAndItsReadersAreTold(): int {
		$id = self::published(1)[0]['id'];
		$told = array_filter(self::ocs(self::$carla, 'GET', '/apps/notifications/api/v2/notifications')['data'],
			static fn (array $notification): bool => $notification['app'] === 'ia' && $notification['object_id'] === (string)$id);
		self::assertCount(1, $told, 'a Reader is told');
		self::assertSame('Nueva edición: Boletín de protocolos', array_values($told)[0]['subject']);
		return $id;
	}

	/** @depends testItIsPublishedWholeAndItsReadersAreTold */
	public function testItListsEveryDocumentOfItsSourcesWithItsReading(int $id): void {
		$edition = self::edition(self::$carla, $id);
		self::assertNotSame('', trim($edition['resumen']));
		self::assertSame(['curaciones.txt', 'escaneado.pdf', 'reservado.txt', 'turnos.xlsx', 'visitas.md'], self::names($edition['changed']), 'the first Edition lists every document as new');
		foreach ($edition['changed'] as $document) {
			self::assertSame($document['status'] === 'read', $document['summary'] !== null && trim($document['summary']) !== '', $document['name']);
			self::assertSame([], array_diff($document['cargos'], self::CARGOS), "{$document['name']} names only Cargos of the list");
		}
		self::assertSame([['curaciones.txt', self::$expires]], array_map(static fn (array $document): array => [$document['name'], $document['expires']], $edition['expiring']));
		self::assertSame(['reservado.txt', 'visitas.md'], self::names($edition['undated']), 'a date the text does not say is never kept');
		self::assertSame([['escaneado.pdf', 'scan'], ['turnos.xlsx', 'failed']], array_map(static fn (array $document): array => [$document['name'], $document['status']], $edition['unreadable']),
			'a scan, and a document Source in a format «IA» does not read; fotos.png inside the folder is no document');
		self::assertSame([], self::logsHolding(self::$sentinel), 'the document text reached a log');
		self::assertSame([], self::logsHolding(mb_substr(trim($edition['resumen']), 0, 60)), "the Model's Resumen reached a log");
		self::assertSame([], self::tasks());
	}

	/** @depends testItIsPublishedWholeAndItsReadersAreTold */
	public function testAnEditionWithNothingToReadHasNoResumen(): void {
		$edition = self::edition(self::$carla, self::published(1, self::$sheets)[0]['id']);
		self::assertSame('', $edition['resumen']);
		self::assertSame([['turnos.xlsx', 'failed']], array_map(static fn (array $document): array => [$document['name'], $document['status']], $edition['unreadable']));
	}

	/** @depends testItIsPublishedWholeAndItsReadersAreTold */
	public function testAReaderSeesTheirOwnMeetingsAndTheDocumentsForTheirCargo(int $id): void {
		$date = self::edition(self::$carla, $id)['date'];
		self::occ('dav:create-calendar', self::$carla, 'reuniones');
		$day = new \DateTimeImmutable($date, new \DateTimeZone(self::TIMEZONE));
		self::event('sector', "DTSTART:{$this->utc($day->modify('10:00'))}\r\nDTEND:{$this->utc($day->modify('11:00'))}", 'Reunión de sector');
		self::event('later', "DTSTART:{$this->utc($day->modify('+3 days 10:00'))}\r\nDTEND:{$this->utc($day->modify('+3 days 11:00'))}", 'Reunión de otra semana');
		self::event('all-day', 'DTSTART;VALUE=DATE:' . $day->format('Ymd') . "\r\nDTEND;VALUE=DATE:" . $day->modify('+1 day')->format('Ymd'), 'Aniversario del centro');
		self::event('cancelled', "DTSTART:{$this->utc($day->modify('15:00'))}\r\nDTEND:{$this->utc($day->modify('16:00'))}\r\nSTATUS:CANCELLED", 'Reunión suspendida');

		$edition = self::edition(self::$carla, $id);
		self::assertSame(['Reunión de sector'], array_column($edition['personal']['meetings'], 'title'), "her timed events in the Edition's period only");
		$forNurses = array_filter([...$edition['expiring'], ...$edition['undated']], static fn (array $document): bool => in_array(self::CARGOS[0], $document['cargos'], true));
		self::assertSame(self::names(array_values($forNurses)), self::names($edition['personal']['documents']), 'the documents whose Reading names her Cargo group');
		self::assertSame(['meetings' => [], 'documents' => []], self::edition(self::$ana, $id)['personal'], 'someone with no meetings and no Cargo has an empty list');
	}

	/** @depends testItIsPublishedWholeAndItsReadersAreTold */
	public function testParaTiMergesWhatIsAheadInTheLatestEditionsOfTheBulletinsSheReads(int $id): void {
		// A meeting still ahead within the Edition's period, whatever the time of day the test runs.
		$timezone = new \DateTimeZone(self::TIMEZONE);
		$date = self::edition(self::$carla, $id)['date'];
		$start = max(new \DateTimeImmutable('+10 minutes', $timezone), new \DateTimeImmutable("$date 00:01", $timezone));
		self::event('ahead', "DTSTART:{$this->utc($start)}\r\nDTEND:{$this->utc($start->modify('+20 minutes'))}", 'Reunión próxima');
		// One under way, written with a DURATION, that reaches into the Edition's period.
		$began = new \DateTimeImmutable('-5 minutes', $timezone);
		$ends = max(new \DateTimeImmutable('now', $timezone), new \DateTimeImmutable("$date 00:00", $timezone))->modify('+30 minutes');
		self::event('under-way', "DTSTART:{$this->utc($began)}\r\nDURATION:PT" . ($ends->getTimestamp() - $began->getTimestamp()) . 'S', 'Reunión en curso');

		$edition = self::edition(self::$carla, $id);
		$paraTi = self::ocs(self::$carla, 'GET', self::API . '/para-ti')['data'];
		self::assertSame(['Boletín de protocolos', 'Boletín de turnos'], array_column($paraTi['bulletins'], 'name'));
		self::assertContains($id, array_column($paraTi['editions'], 'id'));
		self::assertCount(2, $paraTi['editions'], 'the latest Edition of each');
		$ahead = array_filter($edition['personal']['meetings'], static fn (array $meeting): bool => ($meeting['end'] ?? $meeting['start']) >= time());
		self::assertContains('Reunión próxima', array_column($paraTi['meetings'], 'title'));
		self::assertContains('Reunión en curso', array_column($paraTi['meetings'], 'title'), 'one in progress stays');
		self::assertSame(array_column(array_values($ahead), 'title'), array_column($paraTi['meetings'], 'title'), 'only the meetings not yet ended (#28)');
		$new = array_filter($edition['personal']['documents'], static fn (array $document): bool => $document['new']);
		self::assertSame(self::names(array_values($new)), self::names($paraTi['documents']), 'only the documents new or changed in their Edition (#28)');
		self::assertSame(['curaciones.txt'], array_column($paraTi['expiring'], 'name'));
		self::assertSame(['bulletins' => [], 'editions' => [], 'meetings' => [], 'documents' => [], 'expiring' => []],
			self::ocs(self::$ana, 'GET', self::API . '/para-ti')['data'], 'editing a Bulletin is not reading it');
	}

	/** @depends testItIsPublishedWholeAndItsReadersAreTold */
	public function testTheDashboardWidgetShowsTheLatestEditionsEachCanSee(): void {
		self::assertSame(['Boletín de protocolos', 'Boletín de turnos'], self::widget(self::$carla));
		self::assertSame(['Boletín de turnos'], self::widget(self::$dario), 'not the one with a file he cannot open');
	}

	/** @depends testItIsPublishedWholeAndItsReadersAreTold */
	public function testAReaderWhoCannotOpenAFileItReadDoesNotSeeIt(int $id): void {
		self::assertSame([], self::editions(self::$dario));
		self::assertSame(404, self::ocs(self::$dario, 'GET', self::API . "/editions/$id")['status']);
		$told = array_filter(self::ocs(self::$dario, 'GET', '/apps/notifications/api/v2/notifications')['data'] ?? [],
			static fn (array $notification): bool => $notification['app'] === 'ia' && $notification['object_id'] === (string)$id);
		self::assertSame([], $told, 'nor is told of it');
		self::assertSame(403, self::ocs(self::person(), 'GET', self::API . '/bulletins/' . self::$bulletin . '/editions')['status']);
	}

	/** @depends testItIsPublishedWholeAndItsReadersAreTold */
	public function testOnceThatFileIsDeletedTheyDoAndItsReadingIsGone(int $id): void {
		self::remove(self::$ana, 'reservado.txt');
		$edition = self::edition(self::$dario, $id);
		$reserved = array_values(array_filter($edition['changed'], static fn (array $document): bool => $document['name'] === 'reservado.txt'))[0];
		self::assertNull($reserved['summary'], 'deleting the file deletes its Reading');
	}

	/** @depends testItIsPublishedWholeAndItsReadersAreTold */
	public function testTheNextEditionListsWhatChangedAndKeepsTheReadingsOfTheRest(int $first): void {
		$before = self::edition(self::$carla, $first);
		self::upload(self::$ana, 'Protocolos/visitas.md', self::visits("\nSe agrega la evaluación del riesgo de caídas en cada visita.\n"));
		self::occ('config:system:set', 'default_timezone', '--value=Pacific/Kiritimati');
		self::offHoursNow();
		self::tick();
		$editions = self::published(2);
		self::assertSame($first, $editions[1]['id']);
		self::assertGreaterThan($editions[1]['date'], $editions[0]['date']);

		$next = self::edition(self::$carla, $editions[0]['id']);
		self::assertSame(['visitas.md'], self::names($next['changed']), 'only the changed document; the deleted one is not a Source any more');
		$readAt = static fn (array $edition, string $name): ?int => array_column(array_merge($edition['undated'], $edition['expiring']), 'readAt', 'name')[$name] ?? null;
		self::assertSame($readAt($before, 'curaciones.txt'), $readAt($next, 'curaciones.txt'), 'an unchanged version keeps its Reading');
		self::assertGreaterThan($readAt($before, 'visitas.md'), $readAt($next, 'visitas.md'));
		self::assertNull($readAt(self::edition(self::$carla, $first), 'visitas.md'), 'a newer version replaces the Reading');

		$forHer = self::names($next['personal']['documents']);
		$paraTi = self::names(self::ocs(self::$carla, 'GET', self::API . '/para-ti')['data']['documents']);
		self::assertSame(array_values(array_intersect($forHer, ['visitas.md'])), $paraTi, '«Para ti» keeps, of her documents, only the one that changed (#28)');
		self::assertNotContains('curaciones.txt', $paraTi);
	}

	/**
	 * A daily Bulletin of $editors for $readers, from the folder and document Sources $sources, and its id.
	 *
	 * @param array<string, int> $sources by a name: «folder» for the folder, anything else for a document
	 */
	private static function bulletin(string $name, string $editors, string $readers, array $sources): int {
		$created = self::ocs(self::$ana, 'POST', self::API . '/bulletins', [
			'name' => $name,
			'editorGroup' => $editors,
			'sources' => array_map(static fn (string $key, int $id): array => ['type' => $key === 'folder' ? 'folder' : 'file', 'id' => (string)$id], array_keys($sources), $sources),
			'readers' => [$readers],
			'schedule' => ['kind' => 'daily'],
		]);
		self::assertSame(201, $created['status'], json_encode($created['data']));
		return $created['data']['bulletin']['id'];
	}

	/** @return list<array{id: int, date: string}> the Editions of the Bulletin (the protocols' by default) $person sees */
	private static function editions(string $person, ?int $bulletin = null): array {
		$answer = self::ocs($person, 'GET', self::API . '/bulletins/' . ($bulletin ?? self::$bulletin) . '/editions');
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		return $answer['data']['editions'];
	}

	/**
	 * Ticks as the scheduler would until Carla sees $count Editions of the Bulletin (the protocols'
	 * by default), or fails after WRITING seconds.
	 *
	 * @return list<array{id: int, date: string}>
	 */
	private static function published(int $count, ?int $bulletin = null): array {
		$until = time() + self::WRITING;
		do {
			$editions = self::editions(self::$carla, $bulletin);
			if (count($editions) === $count) {
				return $editions;
			}
			sleep(5);
			self::tick();
		} while (time() < $until);
		self::fail("Carla did not see $count Editions after " . self::WRITING . ' s');
	}

	/** A time as iCalendar writes it in UTC. */
	private function utc(\DateTimeImmutable $time): string {
		return $time->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
	}

	/** Puts an event in Carla's «reuniones» calendar: $times holds its DTSTART and DTEND lines. */
	private static function event(string $uid, string $times, string $title): void {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//ia seam//ES\r\nBEGIN:VEVENT\r\nUID:$uid-" . bin2hex(random_bytes(4))
			. "\r\nDTSTAMP:20260101T000000Z\r\n$times\r\nSUMMARY:$title\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		self::assertSame(201, self::dav(self::$carla, 'PUT', '/remote.php/dav/calendars/' . self::$carla . "/reuniones/$uid.ics", $ics, 'text/calendar'));
	}

	/** @return list<string> the titles the «Boletines» Dashboard widget shows $person, sorted */
	private static function widget(string $person): array {
		$answer = self::ocs($person, 'GET', '/apps/dashboard/api/v2/widget-items?widgets%5B%5D=ia-boletines');
		self::assertSame(200, $answer['status']);
		$titles = array_column($answer['data']['ia-boletines']['items'], 'title');
		sort($titles);
		return $titles;
	}

	/** @return array<string, mixed> */
	private static function edition(string $person, int $id): array {
		$answer = self::ocs($person, 'GET', self::API . "/editions/$id");
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		return $answer['data']['edition'];
	}

	/**
	 * @param list<array{name: string}> $documents
	 * @return list<string> sorted
	 */
	private static function names(array $documents): array {
		$names = array_column($documents, 'name');
		sort($names);
		return $names;
	}

	private static function visits(string $more): string {
		return "# Protocolo de visita domiciliaria integral\n\nDirigido a: TENS y Médico.\n\n"
			. "Cada visita registra los signos vitales, la adherencia al tratamiento y la red de apoyo del paciente.\n$more";
	}
}
