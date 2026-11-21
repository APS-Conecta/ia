<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * Late Editions (APS-Conecta/ia#13): an Edition that its Off hours couldn't finish waits, shows its
 * Editors step i of n, turns «atrasada» once its date is past, telling them once, and continues
 * the next night from its last finished step, as the one Edition: none opens meanwhile, nor the
 * same night it is published. A Source gone since it was set up is told to the Editors, once.
 *
 * Its date passes by moving the server's timezone from Pago Pago's (UTC−11) to Kiritimati's
 * (UTC+14), whose date is always later.
 */
final class LateEditionTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	/** Seconds the Model may take for one short step on the CPU, behind other Runs. */
	private const STEP = 600;

	private static string $ana;
	private static string $carla;
	private static int $bulletin;

	public static function setUpBeforeClass(): void {
		self::timezone('Pacific/Pago_Pago');
		self::offHoursNow();
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

	/** @return array{date: string, step: int} the Edition's date, and the step it waits at */
	public function testAnEditionItsOffHoursCannotFinishWaitsAndShowsItsStep(): array {
		self::tick();
		// Off hours end while its first step is asked: it stops after that step, or after the next
		// one when the Model answered the first before the move reached the worker.
		self::offHoursLater();
		$until = time() + self::STEP;
		do {
			$writing = self::writing();
			if ($writing !== null && $writing['step'] >= 2 && self::tasks() === []) {
				break;
			}
			sleep(5);
		} while (time() < $until);
		self::assertSame([3, false], [$writing['steps'] ?? null, $writing['late'] ?? null], 'two Readings and a Resumen');
		self::assertContains($writing['step'], [2, 3], 'it waits between steps');
		self::assertSame([], self::editions(self::$carla, self::$bulletin), 'nothing shows while it is written');
		self::assertCount(1, self::told(self::$ana, 'Una fuente de Boletín de normas ya no está'), 'its Editors are told a Source is gone');
		self::assertSame([], self::told(self::$carla, 'Una fuente'), 'its Readers are not');
		return ['date' => $writing['date'], 'step' => $writing['step']];
	}

	/**
	 * @depends testAnEditionItsOffHoursCannotFinishWaitsAndShowsItsStep
	 * @param array{date: string, step: int} $waiting
	 */
	public function testOnceItsDateIsPastItIsLateAndItsEditorsAreToldOnce(array $waiting): void {
		self::timezone('Pacific/Kiritimati');
		self::offHoursLater();
		self::tick();
		self::tick();
		$writing = self::writing();
		self::assertSame([$waiting['date'], $waiting['step'], true], [$writing['date'], $writing['step'], $writing['late']]);
		self::assertCount(1, self::told(self::$ana, 'Edición atrasada'), 'told once, however many ticks');
	}

	/**
	 * @depends testAnEditionItsOffHoursCannotFinishWaitsAndShowsItsStep
	 * @param array{date: string, step: int} $waiting
	 */
	public function testTheNextOffHoursFinishItAsTheOneEdition(array $waiting): void {
		$date = $waiting['date'];
		self::offHoursNow();
		$editions = self::published(self::$carla, self::$bulletin, 1, 3 * self::STEP);
		foreach (range(1, 3) as $tick) {
			sleep(5);
			self::tick();
		}
		$editions = self::editions(self::$carla, self::$bulletin);
		self::assertSame([['date' => $date, 'late' => true]], array_map(static fn (array $edition): array => ['date' => $edition['date'], 'late' => $edition['late']], $editions),
			'the late one, published; none opened for the new date, that night either');
		self::assertNull(self::writing());
		self::assertTrue(self::ocs(self::$carla, 'GET', self::API . '/editions/' . $editions[0]['id'])['data']['edition']['late']);
		self::assertCount(1, self::told(self::$ana, 'Una fuente de Boletín de normas ya no está'), 'the gone Source is told once');
	}

	/** @return array<string, mixed>|null the Edition being written, as the Bulletin's Editor sees it */
	private static function writing(): ?array {
		return self::ocs(self::$ana, 'GET', self::API . '/bulletins/' . self::$bulletin)['data']['bulletin']['writing'];
	}

	/** @return list<array<string, mixed>> $person's «IA» notifications whose subject starts with $subject */
	private static function told(string $person, string $subject): array {
		return array_values(array_filter(self::ocs($person, 'GET', '/apps/notifications/api/v2/notifications')['data'] ?? [],
			static fn (array $notification): bool => $notification['app'] === 'ia' && str_starts_with($notification['subject'], $subject)));
	}
}
