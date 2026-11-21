<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Depends;

/**
 * The Flow rule «Revisar con IA» (APS-Conecta/ia#11), made by an admin through Flow's own API: a
 * file event it matches puts a review in line (origin flow, with the rule's instructions) that runs
 * in Off hours like an Automatic action, acting as whoever set the event off; Editors see the rule,
 * read-only. A file written with no session (occ files:put) fails into «Estado» and tells nobody.
 */
final class FlowRuleTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';
	private const FLOW = '/apps/workflowengine/api/v1/workflows/global';
	private const INSTRUCTIONS = 'Revisa además que nombre el cargo responsable.';
	/** Seconds the Model may take for a short Review on the CPU, behind other Runs. */
	private const REVIEW = 900;

	private static string $admin;
	private static string $ana;
	private static string $carla;
	private static string $name;
	private static int $rule;

	public static function setUpBeforeClass(): void {
		self::offHoursNow();
		self::$admin = self::admin();
		self::$ana = self::person();
		self::$carla = self::person();
		self::editorGroups(self::group(self::$ana));
		self::$name = 'Protocolos de flujo ' . bin2hex(random_bytes(3));
		$created = self::ocs(self::$admin, 'POST', self::FLOW, [
			'class' => 'OCA\Ia\Flow\ReviewOperation',
			'name' => self::$name,
			'checks' => [['class' => 'OCA\WorkflowEngine\Check\FileName', 'operator' => 'matches', 'value' => '/^flujo-/']],
			'operation' => self::INSTRUCTIONS,
			'entity' => 'OCA\WorkflowEngine\Entity\File',
			'events' => ['\OCP\Files::postCreate'],
		]);
		self::assertSame(200, $created['status'], json_encode($created['data']));
		self::$rule = (int)$created['data']['id'];
	}

	public static function tearDownAfterClass(): void {
		self::ocs(self::$admin, 'DELETE', self::FLOW . '/' . self::$rule);
		parent::tearDownAfterClass();
	}

	public function testEditorsSeeTheFlowRuleReadOnly(): void {
		$listed = self::ocs(self::$ana, 'GET', self::API . '/actions')['data']['flowRules'];
		self::assertContains(['id' => self::$rule, 'name' => self::$name, 'instructions' => self::INSTRUCTIONS], $listed);
	}

	public function testAMatchingFileIsReviewedInOffHoursAsWhoeverAddedIt(): void {
		self::upload(self::$carla, 'flujo-curacion.txt', (string)file_get_contents(__DIR__ . '/fixtures/curacion-corto.txt'));
		self::upload(self::$carla, 'otra-curacion.txt', (string)file_get_contents(__DIR__ . '/fixtures/curacion-corto.txt'));
		$reviews = self::ocs(self::$carla, 'GET', self::API . '/reviews')['data']['reviews'];
		self::assertSame(['flujo-curacion.txt'], array_column($reviews, 'name'), 'only the file the rule matches');
		self::assertSame(['flow', 'waiting'], [$reviews[0]['origin'], $reviews[0]['state']], 'in line for Off hours');
		$review = self::reviewed($reviews[0]['id']);
		self::assertSame(self::INSTRUCTIONS, $review['instructions']);
		self::assertCount(1, self::notified(self::$carla, 'Revisión lista'), 'whoever added it is told');
	}

	#[Depends('testAMatchingFileIsReviewedInOffHoursAsWhoeverAddedIt')]
	public function testWithNoSessionItFailsIntoEstadoAndNobodyIsTold(): void {
		$local = tempnam(sys_get_temp_dir(), 'ia');
		file_put_contents($local, (string)file_get_contents(__DIR__ . '/fixtures/curacion-corto.txt'));
		self::occ('files:put', $local, '/' . self::$carla . '/files/flujo-sin-sesion.txt');
		unlink($local);
		$until = time() + self::REVIEW;
		do {
			self::tick();
			$failed = array_values(array_filter(self::ocs(self::$admin, 'GET', self::API . '/admin/estado')['data']['failed'],
				static fn (array $run): bool => $run['kind'] === 'flow' && $run['name'] === self::$name));
			if ($failed !== []) {
				break;
			}
			sleep(5);
		} while (time() < $until);
		self::assertSame('La regla de Flujo se activó sin una sesión: no hay a nombre de quién leer el archivo.', $failed[0]['error'] ?? null);
		self::assertCount(1, self::notified(self::$carla, 'Revisión lista'), 'nobody else is told: only the first');
		self::assertSame([], self::notified(self::$admin, ''));
	}

	/** @return array<string, mixed> the Review $id once done, ticking as the scheduler would */
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
		self::fail("Review $id was not done after " . self::REVIEW . ' s: ' . $review['state']);
	}
}
