<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * Automatic actions (APS-Conecta/ia#9): an Editor group puts a rule on a folder it can open; every
 * file added to it, changed in it, moved or copied into it is reviewed in Off hours, once, on its
 * latest version, with the rule's instructions, and whoever added or changed it is told. Anyone who
 * can open the file reads the report.
 */
final class ActionsTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	private const INSTRUCTIONS = 'Comprueba que tenga fecha de vigencia y responsables.';
	/** Seconds a short document's Review may take on the CPU, behind other Runs. */
	private const REVIEW = 900;

	private static string $ana;
	private static string $beto;
	private static string $carla;
	private static string $dario;
	private static string $editors;
	private static int $folder;
	private static int $dariosShare;

	public static function setUpBeforeClass(): void {
		self::offHoursLater();
		self::$ana = self::person();
		self::$beto = self::person();
		self::$carla = self::person();
		self::$dario = self::person();
		self::$editors = self::group(self::$ana, self::$beto);
		self::editorGroups(self::$editors);
		self::$folder = self::folder(self::$ana, 'Normas');
		self::share('/Normas', self::$carla, 1);
		self::$dariosShare = self::share('/Normas', self::$dario, 15);
	}

	public function testAFolderTheWholeEditorGroupCannotOpenIsRefusedNamingWhoAndOthersAreForbidden(): void {
		$refused = self::ocs(self::$ana, 'POST', self::API . '/actions', self::draft());
		self::assertSame(422, $refused['status']);
		self::assertStringContainsString(self::$beto, $refused['data']['message']);
		self::assertSame(403, self::ocs(self::$carla, 'POST', self::API . '/actions', self::draft())['status']);
		self::assertSame(403, self::ocs(self::$carla, 'GET', self::API . '/actions')['status']);
	}

	public function testAnEditorPutsAnActionOnAFolderAndAnotherChangesIt(): int {
		self::shareWithGroup(self::$ana, '/Normas', self::$editors);
		$created = self::ocs(self::$ana, 'POST', self::API . '/actions', self::draft());
		self::assertSame(201, $created['status'], json_encode($created['data']));
		$id = $created['data']['action']['id'];
		self::assertSame(['Normas', ''], [$created['data']['action']['folder']['name'], $created['data']['action']['instructions']]);
		$changed = self::ocs(self::$beto, 'PUT', self::API . "/actions/$id", ['name' => 'Revisar normas', 'folder' => (string)self::$folder, 'instructions' => self::INSTRUCTIONS]);
		self::assertSame(200, $changed['status'], json_encode($changed['data']));
		self::assertSame([['id' => $id, 'name' => 'Revisar normas', 'instructions' => self::INSTRUCTIONS]],
			array_map(static fn (array $action): array => array_intersect_key($action, ['id' => 0, 'name' => 0, 'instructions' => 0]), self::ocs(self::$ana, 'GET', self::API . '/actions')['data']['actions']));
		self::assertSame(403, self::ocs(self::$carla, 'PUT', self::API . "/actions/$id", ['name' => 'x', 'folder' => (string)self::$folder])['status']);
		return $id;
	}

	/** @depends testAnEditorPutsAnActionOnAFolderAndAnotherChangesIt */
	public function testAFileAddedIsReviewedOnceInOffHoursOnItsLatestVersion(): void {
		self::upload(self::$dario, 'Normas/norma.txt', "Norma de lavado de manos.\nEl lavado clínico dura 40 a 60 segundos.\n");
		self::upload(self::$dario, 'Normas/norma.txt', "Norma de lavado de manos.\nEl lavado clínico dura entre 40 y 60 segundos, antes y después de cada atención.\n");
		$latest = self::file(self::$dario, 'Normas/norma.txt')['etag'];
		self::tick();
		$review = self::automatic(self::$carla, 'norma.txt');
		self::assertSame(['waiting', 'action', self::INSTRUCTIONS], [$review['state'], $review['origin'], $review['instructions']], 'it waits for Off hours, however short');
		self::assertSame([], self::tasks());
		self::assertSame(404, self::ocs(self::person(), 'GET', self::API . "/reviews/{$review['id']}")['status'], 'only who can open the file reads it');

		self::offHoursNow();
		$done = self::reviewed($review['id']);
		self::assertSame($latest, $done['etag'], 'reviewed once, on the latest version');
		self::assertIsArray($done['findings']);
		self::assertCount(1, self::told(self::$dario, $review['id']), 'whoever added or changed it is told');
		self::assertSame([], self::told(self::$ana, $review['id']));
	}

	/** @depends testAnEditorPutsAnActionOnAFolderAndAnotherChangesIt */
	public function testWhenTheChangerCannotOpenItAnEditorReadsItAndTheChangerIsNotTold(): void {
		self::offHoursLater();
		self::upload(self::$dario, 'Normas/cambio.txt', "Norma de aislamiento de contacto.\nUsar guantes y pechera al atender.\n");
		self::assertSame(200, self::ocs(self::$ana, 'DELETE', '/apps/files_sharing/api/v1/shares/' . self::$dariosShare)['status']);
		self::offHoursNow();
		$review = self::automatic(self::$carla, 'cambio.txt');
		self::reviewed($review['id']);
		self::assertSame([], self::told(self::$dario, $review['id']));
	}

	/** @depends testAnEditorPutsAnActionOnAFolderAndAnotherChangesIt */
	public function testAFileMovedOrCopiedIntoTheFolderIsLinedUpAndOneOutsideIsNot(int $id): void {
		self::offHoursLater();
		self::upload(self::$ana, 'movida.txt', "Norma de residuos.\n");
		self::upload(self::$ana, 'copiada.txt', "Norma de esterilización.\n");
		self::upload(self::$ana, 'fuera.txt', "Norma de otra unidad.\n");
		self::transfer(self::$ana, 'MOVE', 'movida.txt', 'Normas/movida.txt');
		self::transfer(self::$ana, 'COPY', 'copiada.txt', 'Normas/copiada.txt');
		$names = array_column(self::ocs(self::$ana, 'GET', self::API . '/reviews')['data']['reviews'], 'name');
		self::assertContains('movida.txt', $names);
		self::assertContains('copiada.txt', $names);
		self::assertNotContains('fuera.txt', $names);

		self::assertSame(200, self::ocs(self::$beto, 'DELETE', self::API . "/actions/$id")['status']);
		self::upload(self::$ana, 'Normas/despues.txt', "Norma nueva.\n");
		$names = array_column(self::ocs(self::$ana, 'GET', self::API . '/reviews')['data']['reviews'], 'name');
		self::assertNotContains('despues.txt', $names, 'a deleted action asks for no new Review');
		self::assertContains('norma.txt', $names, 'its reports stay');
	}

	/**
	 * Ticks as the scheduler would until Carla sees the Review $id done, or fails after REVIEW seconds.
	 *
	 * @return array<string, mixed> the Review
	 */
	private static function reviewed(int $id): array {
		$until = time() + self::REVIEW;
		do {
			self::tick();
			$review = self::ocs(self::$carla, 'GET', self::API . "/reviews/$id")['data']['review'];
			if ($review['state'] === 'done') {
				return $review;
			}
			sleep(5);
		} while (time() < $until);
		self::fail("Review $id was not done after " . self::REVIEW . ' s: ' . json_encode(array_diff_key($review, ['findings' => 0])));
	}

	/** @return array<string, mixed> the automatic Review of $name that $person sees */
	private static function automatic(string $person, string $name): array {
		$found = array_values(array_filter(self::ocs($person, 'GET', self::API . '/reviews')['data']['reviews'],
			static fn (array $review): bool => $review['name'] === $name && $review['origin'] === 'action'));
		self::assertCount(1, $found, "one automatic Review of $name");
		return $found[0];
	}

	/** @return list<array<string, mixed>> $person's notifications of the Review $id */
	private static function told(string $person, int $id): array {
		return array_values(array_filter(self::ocs($person, 'GET', '/apps/notifications/api/v2/notifications')['data'] ?? [],
			static fn (array $notification): bool => $notification['app'] === 'ia' && $notification['object_id'] === (string)$id));
	}

	/** Ana's /Normas shared with $person, with these permissions; the share's id. */
	private static function share(string $path, string $person, int $permissions): int {
		$answer = self::ocs(self::$ana, 'POST', '/apps/files_sharing/api/v1/shares', ['path' => $path, 'shareType' => 0, 'shareWith' => $person, 'permissions' => $permissions]);
		self::assertSame(200, $answer['status']);
		return (int)$answer['data']['id'];
	}

	/** @return array<string, string> a new action as the sidebar sends it */
	private static function draft(): array {
		return ['name' => 'Revisar normas', 'editorGroup' => self::$editors, 'folder' => (string)self::$folder, 'instructions' => ''];
	}
}
