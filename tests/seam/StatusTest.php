<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Depends;

/**
 * «Estado» (APS-Conecta/ia#8), with the Model up: it answers and the setup check says so; a Run
 * with nobody to act as fails into «Estado» and nobody is told; «Reintentar» puts it back in line.
 * Its Bulletin reads an empty folder, so its Edition is published without the Model.
 */
final class StatusTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	private const STATUS = self::API . '/admin/status';
	private const DELEGATED = 'OCA\Ia\Settings\Admin';
	/** Seconds the Model may take for the probe on the CPU, behind other Runs. */
	private const PROBE = 600;
	/** Seconds for an Edition without the Model, behind the other Runs in line. */
	private const EDITION = 900;

	private static string $admin;
	private static string $ana;
	private static string $carla;
	private static string $name;
	private static int $bulletin;

	public static function setUpBeforeClass(): void {
		self::offHoursNow();
		self::$admin = self::admin();
		self::$ana = self::person();
		self::$carla = self::person();
		$editors = self::group(self::$ana);
		$readers = self::group(self::$carla);
		self::editorGroups($editors);
		$folder = self::folder(self::$ana, 'Vacía');
		self::shareWithGroup(self::$ana, '/Vacía', $readers);
		self::$name = 'Boletín sin nadie ' . bin2hex(random_bytes(3));
		$created = self::ocs(self::$ana, 'POST', self::API . '/bulletins', [
			'name' => self::$name,
			'editorGroup' => $editors,
			'sources' => [['type' => 'folder', 'id' => (string)$folder]],
			'readers' => [$readers],
			'schedule' => ['kind' => 'daily'],
		]);
		self::assertSame(201, $created['status'], json_encode($created['data']));
		self::$bulletin = $created['data']['bulletin']['id'];
		// Its only Editor leaves before any tick: no one of the Editor group can open its Source.
		self::occ('user:disable', self::$ana);
	}

	public function testTheModelAnswersAndTheSetupCheckSaysSo(): void {
		$until = time() + self::PROBE;
		do {
			self::tick();
			$model = self::estado(self::$admin)['model'];
			if ($model['state'] === 'up') {
				break;
			}
			sleep(10);
		} while (time() < $until);
		self::assertSame('up', $model['state'], 'the probe was answered');
		self::assertTrue($model['provider']);
		self::assertFalse($model['alerted']);
		self::assertNotNull($model['answered']);
		self::assertSame('success', self::setupCheck());
		self::assertSame([], self::probes(), 'a probe taken is gone from TaskProcessing');
	}

	public function testARunWithNobodyToActAsFailsIntoEstadoAndNobodyIsTold(): int {
		$until = time() + self::EDITION;
		do {
			self::tick();
			$failed = self::ours(self::estado(self::$admin)['failed']);
			if ($failed !== null) {
				break;
			}
			sleep(5);
		} while (time() < $until);
		self::assertNotNull($failed, 'its Run failed into «Estado»');
		self::assertSame('edition', $failed['kind']);
		self::assertSame('Nadie del grupo editor puede abrir todas las fuentes del boletín.', $failed['error']);
		self::assertNotNull($failed['date']);
		self::occ('user:enable', self::$ana);
		foreach ([self::$ana, self::$carla, self::$admin] as $person) {
			self::assertSame([], self::notified($person, ''), 'nobody is told');
		}
		return $failed['id'];
	}

	#[Depends('testARunWithNobodyToActAsFailsIntoEstadoAndNobodyIsTold')]
	public function testReintentarPutsItBackInLineAndItIsPublished(int $run): void {
		$answer = self::ocs(self::$admin, 'POST', self::API . "/admin/runs/$run/retry");
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		self::assertNull(self::ours($answer['data']['failed']), 'it left the failures');
		self::assertGreaterThanOrEqual(1, $answer['data']['waiting']['edition'], 'it is in line');
		self::assertCount(1, self::published(self::$carla, self::$bulletin, 1, self::EDITION));
		$status = self::estado(self::$admin);
		self::assertSame(self::$name, $status['lastDone']['name'] ?? null, 'the last Run that ended well');
		self::assertSame(422, self::ocs(self::$admin, 'POST', self::API . "/admin/runs/$run/retry")['status'], 'only a failed Run is retried');
		self::assertSame(404, self::ocs(self::$admin, 'POST', self::API . '/admin/runs/' . PHP_INT_MAX . '/retry')['status']);
	}

	public function testOnlyAdminsAndTheDelegatedGroupSeeEstadoAndItsMenuEntry(): void {
		$someone = self::person();
		self::assertSame(403, self::ocs($someone, 'GET', self::STATUS)['status']);
		self::assertSame(403, self::ocs($someone, 'POST', self::API . '/admin/runs/1/retry')['status']);
		self::assertFalse(self::ocs($someone, 'GET', self::API . '/me')['data']['status']);
		self::assertTrue(self::ocs(self::$admin, 'GET', self::API . '/me')['data']['status']);
		$head = self::person();
		$delegated = self::group($head);
		self::occ('admin-delegation:add', self::DELEGATED, $delegated);
		try {
			self::assertSame(200, self::ocs($head, 'GET', self::STATUS)['status']);
			self::assertTrue(self::ocs($head, 'GET', self::API . '/me')['data']['status']);
		} finally {
			self::occ('admin-delegation:remove', self::DELEGATED, $delegated);
		}
	}

	/** @return array<string, mixed> «Estado» as $person reads it */
	private static function estado(string $person): array {
		$answer = self::ocs($person, 'GET', self::STATUS);
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		return $answer['data'];
	}

	/**
	 * @param list<array<string, mixed>> $runs
	 * @return array<string, mixed>|null this class's Bulletin's Run among them
	 */
	private static function ours(array $runs): ?array {
		foreach ($runs as $run) {
			if ($run['kind'] === 'edition' && $run['name'] === self::$name) {
				return $run;
			}
		}
		return null;
	}
}
