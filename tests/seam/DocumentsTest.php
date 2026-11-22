<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * «Documentos» (APS-Conecta/ia#15): an Editor's table of the documents their Editor group watches,
 * in its Bulletins' Sources and its Automatic actions' folders, each with its Reading and latest
 * Review, and a document's own view with the Bulletins it appears in.
 */
final class DocumentsTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	private const CARGOS = ['Enfermera', 'Médico', 'TENS'];
	/** How long the Edition may take to write, in seconds: every Reading, then the Resumen. */
	private const WRITING = 900;
	private const REVIEWING = 600;
	/** What Ana's Editor group watches and Ana can open, by name. */
	private const WATCHED = ['agresiones.txt', 'antiguo.txt', 'curaciones.txt', 'escaneado.pdf', 'norma.txt', 'privado.txt', 'turnos.xlsx'];

	private static string $ana;
	private static string $bea;
	private static string $dario;
	private static string $readers;
	private static string $expires;
	private static int $bulletin;
	private static int $loose;
	private static int $private;

	public static function setUpBeforeClass(): void {
		// Off hours all day: the Edition and the automatic Review are written now.
		self::offHoursWindow('00:00', '23:59');
		self::$ana = self::person();
		self::$bea = self::person();
		self::$dario = self::person();
		$editors = self::group(self::$ana, self::$bea);
		self::$readers = self::group(self::$dario);
		// Ana is a nurse; the Cargo list is the Cargo groups' ids.
		$cargos = array_map(static fn (string $name): string => self::namedGroup($name), self::CARGOS);
		self::occ('group:adduser', $cargos[0], self::$ana);
		self::occ('config:app:set', 'ia', 'cargos', '--type=array', '--value=' . json_encode($cargos));
		self::editorGroups($editors);

		$expires = new \DateTimeImmutable('+12 days');
		self::$expires = $expires->format('Y-m-d');
		$folder = self::folder(self::$ana, 'Protocolos');
		self::upload(self::$ana, 'Protocolos/curaciones.txt', "Protocolo de curación avanzada de heridas\n"
			. "Dirigido a: Enfermera.\n"
			. "Procedimiento: lavado por arrastre con suero fisiológico y elección del apósito según el exudado.\n"
			. 'Vigencia: este protocolo vence el ' . self::spanishDate($expires) . ".\n");
		self::upload(self::$ana, 'Protocolos/agresiones.txt', "Protocolo de manejo de agresiones al personal\n"
			. "Dirigido a: Médico.\nLa dirección coordina la denuncia y el apoyo al funcionario agredido.\n"
			. "Vigencia: este protocolo vence el 1 de marzo de 2031.\n");
		self::upload(self::$ana, 'Protocolos/antiguo.txt', "Protocolo de toma de muestras de sangre\n"
			. "Dirigido a: TENS.\nLa muestra se rotula junto al paciente y se envía al laboratorio antes de las 10:00.\n",
			time() - 30 * 24 * 3600);
		self::upload(self::$ana, 'Protocolos/escaneado.pdf', (string)file_get_contents(__DIR__ . '/fixtures/escaneado.pdf'));
		self::upload(self::$ana, 'Protocolos/fotos.png', 'not a document');
		$sheet = self::upload(self::$ana, 'turnos.xlsx', 'not a document «IA» reads');
		self::$private = self::upload(self::$ana, 'privado.txt', "Protocolo de entrega de turno de la dirección\n"
			. "Dirigido a: Médico.\nEl director de turno deja constancia de los pendientes en el libro de novedades.\n");
		self::$loose = self::upload(self::$ana, 'suelto.txt', "Un documento que ningún boletín ni acción vigila.\n");
		foreach (['/Protocolos', '/turnos.xlsx'] as $path) {
			self::shareWithGroup(self::$ana, $path, $editors);
			self::shareWithGroup(self::$ana, $path, self::$readers);
		}
		// The whole Editor group opens privado.txt when it becomes a Source; then Ana stops sharing it.
		$shared = self::ocs(self::$ana, 'POST', '/apps/files_sharing/api/v1/shares', ['path' => '/privado.txt', 'shareType' => 1, 'shareWith' => $editors, 'permissions' => 1]);
		self::assertSame(200, $shared['status']);
		self::$bulletin = self::bulletin(self::$ana, 'Boletín de protocolos', $editors, self::$readers, ['folder' => $folder, 'file' => $sheet, 'private' => self::$private]);
		self::assertSame(200, self::ocs(self::$ana, 'DELETE', '/apps/files_sharing/api/v1/shares/' . $shared['data']['id'])['status']);

		$normas = self::folder(self::$ana, 'Normas');
		self::shareWithGroup(self::$ana, '/Normas', $editors);
		$action = self::ocs(self::$ana, 'POST', self::API . '/actions', ['name' => 'Revisar normas', 'editorGroup' => $editors, 'folder' => (string)$normas, 'instructions' => '']);
		self::assertSame(201, $action['status'], json_encode($action['data']));
		self::upload(self::$ana, 'Normas/norma.txt', "Norma de higiene de manos\nDirigida a: todo el personal clínico.\n"
			. "Lavarse las manos antes y después de cada atención, y tras retirarse los guantes.\n");
	}

	public function testOnlyEditorsSeeItAndOnlyItsFilters(): void {
		self::assertSame(403, self::ocs(self::$dario, 'GET', self::API . '/documents')['status'], 'a Reader is no Editor');
		self::assertSame(403, self::ocs(self::$dario, 'GET', self::API . '/documents/' . self::$private)['status']);
		self::assertSame(422, self::ocs(self::$ana, 'GET', self::API . '/documents?filter=todos')['status']);
	}

	public function testItListsWhatTheGroupWatchesThatEachEditorCanOpenBeforeAnyReading(): void {
		$listed = self::documents(self::$ana);
		self::assertSame(self::WATCHED, self::names($listed['documents']),
			'the Sources and the action folder, a document Source whatever its format; no fotos.png, no suelto.txt');
		self::assertSame(['unread'], array_values(array_unique(array_column($listed['documents'], 'status'))), 'no Edition read them yet');
		$folders = array_column($listed['documents'], 'folder', 'name');
		self::assertSame(['/Protocolos', '/', '/Normas'], [$folders['curaciones.txt'], $folders['turnos.xlsx'], $folders['norma.txt']], 'where each is, in her Files');
		self::assertSame(array_values(array_diff(self::WATCHED, ['privado.txt'])), self::names(self::documents(self::$bea)['documents']),
			'Bea, an Editor of the same group, no longer opens privado.txt');
		self::assertSame(404, self::ocs(self::$bea, 'GET', self::API . '/documents/' . self::$private)['status']);
		self::assertSame(404, self::ocs(self::$ana, 'GET', self::API . '/documents/' . self::$loose)['status'], 'a document nothing watches');
	}

	/** @depends testItListsWhatTheGroupWatchesThatEachEditorCanOpenBeforeAnyReading */
	public function testEachRowShowsItsReadingAndItsLatestReview(): int {
		// Only those who open all its documents see the Edition: Ana, not Bea or the Reader (privado.txt).
		$edition = self::published(self::$ana, self::$bulletin, 1, self::WRITING)[0]['id'];
		$norma = array_values(array_filter(self::ocs(self::$ana, 'GET', self::API . '/reviews')['data']['reviews'],
			static fn (array $review): bool => $review['name'] === 'norma.txt'));
		self::assertCount(1, $norma, "the action's Review of norma.txt");
		$review = self::reviewed(self::$ana, $norma[0]['id'], self::REVIEWING);

		$rows = array_column(self::documents(self::$ana)['documents'], null, 'name');
		self::assertSame(['read', 'read', 'read', 'scan', 'unread', 'read', 'failed'], array_column(array_values($rows), 'status'),
			'norma.txt is in no Bulletin: no Edition reads it');
		self::assertSame([self::$expires, '2031-03-01', null], [$rows['curaciones.txt']['expires'], $rows['agresiones.txt']['expires'], $rows['antiguo.txt']['expires']]);
		foreach ($rows as $name => $row) {
			self::assertSame([], array_diff($row['cargos'], self::CARGOS), "$name names only Cargos of the list");
		}
		self::assertSame(['id' => $review['id'], 'findings' => count($review['findings'])], $rows['norma.txt']['review']);
		self::assertNull($rows['curaciones.txt']['review'], 'nobody reviewed it');
		return $edition;
	}

	/** @depends testEachRowShowsItsReadingAndItsLatestReview */
	public function testTheFiltersKeepWhatTheySay(): void {
		$all = self::documents(self::$ana);
		self::assertSame(['agresiones.txt', 'curaciones.txt', 'escaneado.pdf', 'norma.txt', 'privado.txt', 'turnos.xlsx'],
			self::names(self::documents(self::$ana, 'changed')['documents']), 'antiguo.txt last changed a month ago');
		self::assertSame(['antiguo.txt', 'curaciones.txt', 'privado.txt'], self::names(self::documents(self::$ana, 'expiring')['documents']),
			'within 30 days, or read without a date; never the unreadable');
		$forNurses = self::names(array_filter($all['documents'], static fn (array $row): bool => in_array('Enfermera', $row['cargos'], true)));
		self::assertSame($forNurses, self::names(self::documents(self::$ana, 'cargo')['documents']), "Ana's Cargo is Enfermera");
		$found = array_values(array_filter($all['documents'], static fn (array $row): bool => ($row['review']['findings'] ?? 0) > 0));
		self::assertSame(self::names($found), self::names(self::documents(self::$ana, 'findings')['documents']));
		self::assertSame(['all' => 7, 'changed' => 6, 'expiring' => 3, 'findings' => count($found), 'cargo' => count($forNurses)], $all['counts']);
		self::assertSame(6, self::documents(self::$bea)['counts']['all']);
	}

	/** @depends testEachRowShowsItsReadingAndItsLatestReview */
	public function testADocumentShowsItsReadingAndTheEditionsItAppearsIn(int $edition): void {
		$document = self::document(self::$ana, 'curaciones.txt');
		self::assertNotSame('', trim((string)$document['summary']));
		self::assertIsInt($document['readAt']);
		self::assertSame([['bulletin' => ['id' => self::$bulletin, 'name' => 'Boletín de protocolos'], 'edition' => ['id' => $edition, 'date' => self::edition(self::$ana, $edition)['date']]]],
			$document['appearsIn']);
		self::assertNull($document['latestReview'], 'nobody reviewed it');
		$norma = self::document(self::$ana, 'norma.txt');
		self::assertSame([null, null, []], [$norma['summary'], $norma['readAt'], $norma['appearsIn']]);
		$review = self::ocs(self::$ana, 'GET', self::API . '/reviews/' . $norma['review']['id'])['data']['review'];
		self::assertSame([$review['id'], 'action', $review['findings']], [$norma['latestReview']['id'], $norma['latestReview']['origin'], $norma['latestReview']['findings']],
			"the action's Review, whole");
		self::assertSame([], self::document(self::$bea, 'curaciones.txt')['appearsIn'], 'Bea edits the Bulletin but cannot see its Edition: she no longer opens privado.txt');
	}

	/** @return array{documents: list<array<string, mixed>>, counts: array<string, int>} */
	private static function documents(string $person, string $filter = 'all'): array {
		$answer = self::ocs($person, 'GET', self::API . "/documents?filter=$filter");
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		return $answer['data'];
	}

	/** @return array<string, mixed> the watched document $name, as $person opens it */
	private static function document(string $person, string $name): array {
		$row = array_column(self::documents($person)['documents'], null, 'name')[$name];
		$answer = self::ocs($person, 'GET', self::API . '/documents/' . $row['fileId']);
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		return $answer['data']['document'];
	}

	/** @param list<array<string, mixed>> $rows */
	private static function names(array $rows): array {
		return array_column($rows, 'name');
	}
}
