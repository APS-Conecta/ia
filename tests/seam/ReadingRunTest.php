<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * Readings of the documents only an Automatic action watches (APS-Conecta/ia#42): the night's sweep
 * of the action folders lines them up, the run engine reads them in Off hours as an Editor group
 * member, and «Documentos» shows them with their Reading.
 */
final class ReadingRunTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	private const CARGOS = ['Enfermera', 'Médico', 'TENS'];
	/** How long a short document's Reading may take behind the other Off-hours Runs, in seconds. */
	private const READING = 600;

	private static string $ana;
	private static string $admin;
	private static string $expires;
	private static string $sentinel;

	public static function setUpBeforeClass(): void {
		// Off hours all day: the sweep and the Readings happen now.
		self::offHoursWindow('00:00', '23:59');
		self::$ana = self::person();
		$editors = self::group(self::$ana, self::person());
		self::$admin = self::admin();
		$cargos = array_map(static fn (string $name): string => self::namedGroup($name), self::CARGOS);
		self::occ('config:app:set', 'ia', 'cargos', '--type=array', '--value=' . json_encode($cargos));
		self::editorGroups($editors);

		// The documents are in the folder before its action: no automatic Review, only the sweep reads them.
		$expires = new \DateTimeImmutable('+20 days');
		self::$expires = $expires->format('Y-m-d');
		self::$sentinel = 'centinela-' . bin2hex(random_bytes(4));
		$folder = self::folder(self::$ana, 'Normas');
		self::upload(self::$ana, 'Normas/higiene.txt', "Norma de higiene de manos\nDirigida a: Enfermera y TENS.\n"
			. 'Lavarse las manos antes y después de cada atención (' . self::$sentinel . ").\n"
			. 'Vigencia: esta norma vence el ' . self::spanishDate($expires) . ".\n");
		self::upload(self::$ana, 'Normas/escaneado.pdf', (string)file_get_contents(__DIR__ . '/fixtures/escaneado.pdf'));
		self::upload(self::$ana, 'Normas/fotos.png', 'not a document');
		self::shareWithGroup(self::$ana, '/Normas', $editors);
		$created = self::ocs(self::$ana, 'POST', self::API . '/actions', ['name' => 'Revisar normas', 'editorGroup' => $editors, 'folder' => (string)$folder, 'instructions' => '']);
		self::assertSame(201, $created['status'], json_encode($created['data']));
	}

	public function testNothingIsReadBeforeTheNightsSweep(): void {
		self::tick();
		self::assertSame(['escaneado.pdf' => 'unread', 'higiene.txt' => 'unread'], self::statuses());
		self::assertSame(0, self::estado()['waiting']['reading']);
	}

	/** @depends testNothingIsReadBeforeTheNightsSweep */
	public function testTheNightsSweepReadsEachDocumentOnlyAnActionWatches(): void {
		self::sweep();
		self::assertSame(['escaneado.pdf' => 'unread', 'higiene.txt' => 'unread'], self::statuses(), 'the sweep only lines them up');
		self::assertSame(2, self::estado()['waiting']['reading']);

		$row = self::readUntil('higiene.txt', self::READING);
		self::assertSame([self::$expires, 'scan'], [$row['expires'], self::statuses()['escaneado.pdf']], 'a scan without the Model');
		self::assertSame([], array_diff($row['cargos'], self::CARGOS), 'it names only Cargos of the list');
		$document = self::ocs(self::$ana, 'GET', self::API . '/documents/' . $row['fileId'])['data']['document'];
		self::assertNotSame('', trim((string)$document['summary']));

		$estado = self::estado();
		self::assertSame(0, $estado['waiting']['reading']);
		self::assertSame(['reading', 'Revisar normas'], [$estado['lastDone']['kind'], $estado['lastDone']['name']]);
		self::assertSame([], self::logsHolding(self::$sentinel), 'the document text reached a log');
		self::assertSame([], self::logsHolding(mb_substr(trim((string)$document['summary']), 0, 60)), "the Model's Reading reached a log");
		self::assertSame([], self::tasks());
	}

	/** @depends testTheNightsSweepReadsEachDocumentOnlyAnActionWatches */
	public function testANewVersionWaitsForTheNextNight(): void {
		self::upload(self::$ana, 'Normas/higiene.txt', "Norma de higiene de manos, segunda versión\nDirigida a: Enfermera.\n"
			. "Lavarse las manos antes y después de cada atención, y tras retirarse los guantes.\n");
		self::sweep(false);
		self::assertSame('unread', self::statuses()['higiene.txt']);
		self::assertSame(0, self::estado()['waiting']['reading'], 'tonight was swept already');

		self::sweep();
		self::assertSame(1, self::estado()['waiting']['reading'], 'only the new version');
		$row = self::readUntil('higiene.txt', self::READING);
		self::assertNull($row['expires'], 'the new version says no date');
	}

	/** @return array<string, string> each document's Reading status in Ana's «Documentos», by name */
	private static function statuses(): array {
		$answer = self::ocs(self::$ana, 'GET', self::API . '/documents');
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		return array_column($answer['data']['documents'], 'status', 'name');
	}

	/**
	 * Ticks as the scheduler would until Ana's «Documentos» shows $name read, or fails after $seconds.
	 *
	 * @return array<string, mixed> its row
	 */
	private static function readUntil(string $name, int $seconds): array {
		$until = time() + $seconds;
		do {
			self::tick();
			$row = array_column(self::ocs(self::$ana, 'GET', self::API . '/documents')['data']['documents'], null, 'name')[$name];
			if ($row['status'] === 'read') {
				return $row;
			}
			sleep(5);
		} while (time() < $until);
		self::fail("$name was not read after $seconds s: " . $row['status']);
	}

	/** @return array<string, mixed> «Estado», as the admin sees it */
	private static function estado(): array {
		$answer = self::ocs(self::$admin, 'GET', self::API . '/admin/estado');
		self::assertSame(200, $answer['status']);
		return $answer['data'];
	}
}
