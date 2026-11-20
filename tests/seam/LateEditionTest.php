<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use RuntimeException;

/**
 * Late Editions (APS-Conecta/ia#13): an Edition that its Off hours couldn't finish waits, shows its
 * Editors step i of n, turns «atrasada» once its date is past, telling them once, and continues
 * the next night from its last finished step, as the one Edition: no second one opens meanwhile.
 * A Source gone since it was set up is told to the Editors.
 *
 * Its date passes by moving the server's timezone from Pago Pago's (UTC−11) to Kiritimati's
 * (UTC+14), whose date is always later.
 */
final class LateEditionTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	/** Seconds the Model may take for one short step on the CPU, behind other Runs. */
	private const STEP = 600;

	private static ?string $timezone = null;
	private static string $ana;
	private static string $carla;
	private static int $bulletin;

	public static function setUpBeforeClass(): void {
		try {
			self::$timezone = trim(self::occ('config:system:get', 'default_timezone'));
		} catch (RuntimeException) {
			self::$timezone = null;
		}
		self::occ('config:system:set', 'default_timezone', '--value=Pacific/Pago_Pago');
		self::$ana = self::person();
		self::$carla = self::person();
		$editors = self::group(self::$ana);
		$readers = self::group(self::$carla);
		self::editorGroups($editors);
		$folder = self::folder(self::$ana, 'Normas');
		self::upload(self::$ana, 'Normas/uno.txt', "Norma de higiene de manos: el lavado clínico dura 40 a 60 segundos.\n");
		self::upload(self::$ana, 'Normas/dos.txt', "Norma de aislamiento: la pechera se cambia entre pacientes.\n");
		$gone = self::upload(self::$ana, 'retirada.txt', "Norma retirada.\n");
		self::shareWithGroup(self::$ana, '/Normas', $readers);
		$created = self::ocs(self::$ana, 'POST', self::API . '/bulletins', [
			'name' => 'Boletín de normas',
			'editorGroup' => $editors,
			'sources' => [['type' => 'folder', 'id' => (string)$folder], ['type' => 'file', 'id' => (string)$gone]],
			'readers' => [$readers],
			'schedule' => ['kind' => 'daily'],
		]);
		self::assertSame(201, $created['status'], json_encode($created['data']));
		self::$bulletin = $created['data']['bulletin']['id'];
		self::remove(self::$ana, 'retirada.txt');
	}

	public static function tearDownAfterClass(): void {
		self::occ(...(self::$timezone === null ? ['config:system:delete', 'default_timezone'] : ['config:system:set', 'default_timezone', '--value=' . self::$timezone]));
		parent::tearDownAfterClass();
	}

	public function testAnEditionItsOffHoursCannotFinishWaitsAndShowsItsStep(): string {
		self::offHoursNow();
		self::tick();
		// Off hours end while its first step is asked: it stops after that step.
		self::offHoursLater();
		$until = time() + self::STEP;
		do {
			$writing = self::writing();
			if ($writing !== null && $writing['step'] >= 2 && self::tasks() === []) {
				break;
			}
			sleep(5);
		} while (time() < $until);
		self::assertSame(2, $writing['step'] ?? null, 'its first step done: two Readings and the Resumen to go');
		self::assertSame([3, false], [$writing['steps'], $writing['late']], 'two Readings and a Resumen');
		self::assertSame([], self::editions(), 'nothing shows while it is written');
		self::assertCount(1, self::told(self::$ana, 'Una fuente no se pudo leer'), 'its Editors are told a Source is gone');
		self::assertSame([], self::told(self::$carla, 'Una fuente no se pudo leer'), 'its Readers are not');
		return $writing['date'];
	}

	/** @depends testAnEditionItsOffHoursCannotFinishWaitsAndShowsItsStep */
	public function testOnceItsDateIsPastItIsLateAndItsEditorsAreToldOnce(string $date): void {
		self::occ('config:system:set', 'default_timezone', '--value=Pacific/Kiritimati');
		self::offHoursLater();
		self::tick();
		self::tick();
		$writing = self::writing();
		self::assertSame([$date, 2, true], [$writing['date'], $writing['step'], $writing['late']]);
		self::assertCount(1, self::told(self::$ana, 'Edición atrasada'), 'told once, however many ticks');
	}

	/** @depends testAnEditionItsOffHoursCannotFinishWaitsAndShowsItsStep */
	public function testTheNextOffHoursFinishItAsTheOneEdition(string $date): void {
		self::offHoursNow();
		$until = time() + 3 * self::STEP;
		do {
			self::tick();
			$editions = self::editions();
			if ($editions !== []) {
				break;
			}
			sleep(5);
		} while (time() < $until);
		self::assertSame([['date' => $date, 'late' => true]], array_map(static fn (array $edition): array => ['date' => $edition['date'], 'late' => $edition['late']], $editions),
			'the late one, published; no Edition opened for the new date meanwhile');
		self::assertTrue(self::ocs(self::$carla, 'GET', self::API . '/editions/' . $editions[0]['id'])['data']['edition']['late']);
	}

	/** @return array<string, mixed>|null the Edition being written, as the Bulletin's Editor sees it */
	private static function writing(): ?array {
		return self::ocs(self::$ana, 'GET', self::API . '/bulletins/' . self::$bulletin)['data']['bulletin']['writing'];
	}

	/** @return list<array<string, mixed>> the Editions Carla sees */
	private static function editions(): array {
		return self::ocs(self::$carla, 'GET', self::API . '/bulletins/' . self::$bulletin . '/editions')['data']['editions'];
	}

	/** @return list<array<string, mixed>> $person's «IA» notifications whose subject starts with $subject */
	private static function told(string $person, string $subject): array {
		return array_values(array_filter(self::ocs($person, 'GET', '/apps/notifications/api/v2/notifications')['data'] ?? [],
			static fn (array $notification): bool => $notification['app'] === 'ia' && str_starts_with($notification['subject'], $subject)));
	}
}
