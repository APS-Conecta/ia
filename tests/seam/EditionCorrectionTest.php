<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Depends;

/**
 * Editors correct or withdraw an Edition (APS-Conecta/ia#14): any Editor of the Bulletin's group
 * corrects the Resumen and its Readers see who did; a withdrawal takes it at once from the Readers'
 * pages, «Para ti» and the widget, its notification's link saying so, and undoing it brings it back.
 * Anyone else is forbidden. Its Bulletin reads an empty folder, so its Edition is published without
 * the Model.
 */
final class EditionCorrectionTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	/** Seconds for an Edition without the Model, behind the other Runs in line. */
	private const EDITION = 900;

	private static string $ana;
	private static string $beto;
	private static string $carla;
	private static string $dario;
	private static string $name;
	private static int $bulletin;
	private static int $edition;

	public static function setUpBeforeClass(): void {
		self::offHoursNow();
		self::$ana = self::person();
		self::$beto = self::person();
		self::$carla = self::person();
		self::$dario = self::person();
		$editors = self::group(self::$ana, self::$beto);
		$readers = self::group(self::$carla);
		self::editorGroups($editors);
		$folder = self::folder(self::$ana, 'Vacía');
		// A Source the whole Editor group can open, and its Readers too.
		self::shareWithGroup(self::$ana, '/Vacía', $editors);
		self::shareWithGroup(self::$ana, '/Vacía', $readers);
		self::$name = 'Boletín corregible ' . bin2hex(random_bytes(3));
		$created = self::ocs(self::$ana, 'POST', self::API . '/bulletins', [
			'name' => self::$name,
			'editorGroup' => $editors,
			'sources' => [['type' => 'folder', 'id' => (string)$folder]],
			'readers' => [$readers],
			'schedule' => ['kind' => 'daily'],
		]);
		self::assertSame(201, $created['status'], json_encode($created['data']));
		self::$bulletin = $created['data']['bulletin']['id'];
		self::$edition = self::published(self::$carla, self::$bulletin, 1, self::EDITION)[0]['id'];
	}

	public function testAnyEditorCorrectsTheResumenAndReadersSeeWhoDid(): void {
		$answer = self::ocs(self::$beto, 'PUT', self::API . '/editions/' . self::$edition, ['resumen' => "  Resumen corregido por el equipo.\n\nSegundo párrafo.  "]);
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		$read = self::edition(self::$carla);
		self::assertSame("Resumen corregido por el equipo.\n\nSegundo párrafo.", $read['resumen']);
		self::assertSame(self::$beto, $read['correctedBy'], 'who corrected it, by name (an account named as its id)');
		self::assertSame(422, self::ocs(self::$ana, 'PUT', self::API . '/editions/' . self::$edition, ['resumen' => ' '])['status'], 'an empty Resumen');
	}

	public function testAnyoneElseIsForbidden(): void {
		foreach ([self::$carla, self::$dario] as $person) {
			self::assertSame(403, self::ocs($person, 'PUT', self::API . '/editions/' . self::$edition, ['resumen' => 'Otro.'])['status']);
			self::assertSame(403, self::ocs($person, 'POST', self::API . '/editions/' . self::$edition . '/withdrawal')['status']);
			self::assertSame(403, self::ocs($person, 'DELETE', self::API . '/editions/' . self::$edition . '/withdrawal')['status']);
		}
		self::assertSame(404, self::ocs(self::$ana, 'POST', self::API . '/editions/' . PHP_INT_MAX . '/withdrawal')['status']);
	}

	#[Depends('testAnyEditorCorrectsTheResumenAndReadersSeeWhoDid')]
	public function testAWithdrawnEditionLeavesTheReadersAtOnceAndItsLinkSaysSo(): void {
		self::assertContains(self::$edition, array_column(self::paraTi(self::$carla), 'id'));
		self::assertContains(self::$name, self::widget(self::$carla));
		$answer = self::ocs(self::$ana, 'POST', self::API . '/editions/' . self::$edition . '/withdrawal');
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		self::assertTrue($answer['data']['edition']['withdrawn']);

		self::assertSame([], self::editions(self::$carla, self::$bulletin), 'gone from the Bulletin\'s page');
		self::assertNotContains(self::$edition, array_column(self::paraTi(self::$carla), 'id'), 'and from «Para ti»');
		self::assertNotContains(self::$name, self::widget(self::$carla), 'and from the widget');
		$told = self::notified(self::$carla, 'Nueva edición: ' . self::$name);
		self::assertCount(1, $told);
		self::assertStringEndsWith('#boletines/' . self::$bulletin . '/' . self::$edition, $told[0]['link']);
		$opened = self::ocs(self::$carla, 'GET', self::API . '/editions/' . self::$edition);
		self::assertSame([410, 'Sus editores retiraron esta edición.'], [$opened['status'], $opened['data']['message']], 'what that link opens says so');

		$listed = self::editions(self::$beto, self::$bulletin);
		self::assertSame([[self::$edition, true]], array_map(static fn (array $edition): array => [$edition['id'], $edition['withdrawn']], $listed), 'its Editors still see it, withdrawn');
		self::assertTrue(self::edition(self::$beto)['withdrawn']);
	}

	#[Depends('testAWithdrawnEditionLeavesTheReadersAtOnceAndItsLinkSaysSo')]
	public function testUndoingTheWithdrawalBringsItBack(): void {
		$answer = self::ocs(self::$beto, 'DELETE', self::API . '/editions/' . self::$edition . '/withdrawal');
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		self::assertFalse($answer['data']['edition']['withdrawn']);
		self::assertSame([self::$edition], array_column(self::editions(self::$carla, self::$bulletin), 'id'));
		self::assertFalse(self::edition(self::$carla)['withdrawn']);
		self::assertSame("Resumen corregido por el equipo.\n\nSegundo párrafo.", self::edition(self::$carla)['resumen'], 'with its correction');
		self::assertContains(self::$edition, array_column(self::paraTi(self::$carla), 'id'));
		self::assertContains(self::$name, self::widget(self::$carla));
	}

	/** @return array<string, mixed> the Edition as $person reads it */
	private static function edition(string $person): array {
		$answer = self::ocs($person, 'GET', self::API . '/editions/' . self::$edition);
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		return $answer['data']['edition'];
	}

	/** @return list<array<string, mixed>> the Editions «Para ti» shows $person */
	private static function paraTi(string $person): array {
		return self::ocs($person, 'GET', self::API . '/para-ti')['data']['editions'];
	}
}
