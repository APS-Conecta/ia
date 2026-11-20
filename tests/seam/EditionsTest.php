<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use RuntimeException;

/**
 * The first Edition (APS-Conecta/ia#10): on its date, in Off hours, a Bulletin's Run takes the
 * Readings it lacks, writes the Resumen with the real Model and publishes the Edition whole; its
 * Readers are told and see it only while they can open every file it read that is still there.
 *
 * An Edition is for the date its Off hours end, in the server's timezone: the first one is written
 * at Pago Pago's time (UTC−11) and the next at Kiritimati's (UTC+14), whose date is always later.
 */
final class EditionsTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	private const CARGOS = ['Enfermera', 'Médico', 'TENS'];
	private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
	/** Seconds an Edition may take: four Readings and the Resumen on the CPU, behind other Bulletins' Runs. */
	private const WRITING = 900;

	private static ?string $timezone = null;
	private static string $ana;
	private static string $carla;
	private static string $dario;
	private static int $bulletin;
	private static string $expires;
	private static string $sentinel;

	public static function setUpBeforeClass(): void {
		try {
			self::$timezone = trim(self::occ('config:system:get', 'default_timezone'));
		} catch (RuntimeException) {
			self::$timezone = null;
		}
		self::occ('config:system:set', 'default_timezone', '--value=Pacific/Pago_Pago');
		self::occ('config:app:set', 'ia', 'cargos', '--type=array', '--value=' . json_encode(self::CARGOS, JSON_UNESCAPED_UNICODE));

		self::$ana = self::person();
		$editors = self::group(self::$ana, self::person());
		self::$carla = self::person();
		self::$dario = self::person();
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
		$reserved = self::upload(self::$ana, 'reservado.txt', "Protocolo reservado de manejo de agresiones al personal\n"
			. "Dirigido a: Médico.\nLa dirección coordina la denuncia y el apoyo al funcionario agredido.\n");
		foreach ([$editors, $readers] as $group) {
			self::shareWithGroup(self::$ana, '/Protocolos', $group);
		}
		self::shareWithGroup(self::$ana, '/reservado.txt', $editors);
		$shared = self::ocs(self::$ana, 'POST', '/apps/files_sharing/api/v1/shares', ['path' => '/reservado.txt', 'shareType' => 0, 'shareWith' => self::$carla, 'permissions' => 1]);
		self::assertSame(200, $shared['status']);

		$created = self::ocs(self::$ana, 'POST', self::API . '/bulletins', [
			'name' => 'Boletín de protocolos',
			'editorGroup' => $editors,
			'sources' => [['type' => 'folder', 'id' => (string)$folder], ['type' => 'file', 'id' => (string)$reserved]],
			'readers' => [$readers],
			'schedule' => ['kind' => 'daily'],
		]);
		self::assertSame(201, $created['status'], json_encode($created['data']));
		self::$bulletin = $created['data']['bulletin']['id'];
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
		self::assertSame(['curaciones.txt', 'escaneado.pdf', 'reservado.txt', 'visitas.md'], self::names($edition['changed']), 'the first Edition lists every document as new');
		foreach ($edition['changed'] as $document) {
			self::assertSame($document['status'] === 'read', $document['summary'] !== null && trim($document['summary']) !== '', $document['name']);
			self::assertSame([], array_diff($document['cargos'], self::CARGOS), "{$document['name']} names only Cargos of the list");
		}
		self::assertSame([['curaciones.txt', self::$expires]], array_map(static fn (array $document): array => [$document['name'], $document['expires']], $edition['expiring']));
		self::assertSame(['reservado.txt', 'visitas.md'], self::names($edition['undated']), 'a date the text does not say is never kept');
		self::assertSame([['escaneado.pdf', 'scan']], array_map(static fn (array $document): array => [$document['name'], $document['status']], $edition['unreadable']));
		self::assertSame([], self::logsHolding(self::$sentinel), 'the document text reached a log');
		self::assertSame([], self::logsHolding(mb_substr(trim($edition['resumen']), 0, 60)), "the Model's Resumen reached a log");
		self::assertSame([], self::tasks());
	}

	/** @depends testItIsPublishedWholeAndItsReadersAreTold */
	public function testAReaderWhoCannotOpenAFileItReadDoesNotSeeIt(int $id): void {
		self::assertSame([], self::editions(self::$dario));
		self::assertSame(404, self::ocs(self::$dario, 'GET', self::API . "/editions/$id")['status']);
		$told = array_filter(self::ocs(self::$dario, 'GET', '/apps/notifications/api/v2/notifications')['data'] ?? [],
			static fn (array $notification): bool => $notification['app'] === 'ia');
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
	}

	/** @return list<array{id: int, date: string}> the Bulletin's Editions $person sees */
	private static function editions(string $person): array {
		$answer = self::ocs($person, 'GET', self::API . '/bulletins/' . self::$bulletin . '/editions');
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		return $answer['data']['editions'];
	}

	/**
	 * Ticks as the scheduler would until Carla sees $count Editions, or fails after WRITING seconds.
	 *
	 * @return list<array{id: int, date: string}>
	 */
	private static function published(int $count): array {
		$until = time() + self::WRITING;
		do {
			$editions = self::editions(self::$carla);
			if (count($editions) === $count) {
				return $editions;
			}
			sleep(5);
			self::tick();
		} while (time() < $until);
		self::fail("Carla did not see $count Editions after " . self::WRITING . ' s');
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
