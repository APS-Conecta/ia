<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Depends;

/**
 * The Flow rule «Revisar con IA» (APS-Conecta/ia#11), made by an admin through Flow's own API, its
 * name and instructions in its operation: a file event it matches puts a review in line (origin
 * flow, with the rule's instructions) that runs in Off hours like an Automatic action, acting as
 * whoever set the event off; Editors see the rule, read-only. A copied folder lines up its files, a
 * tag assigned lines up the file, an Automatic action's folder keeps its own Review, a deleted rule's
 * Review fails, and a file written with no session (occ files:put) fails into «Estado» and tells
 * nobody.
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
	private static string $editors;
	private static string $name;
	private static int $rule;
	/** @var list<int> every rule this class made */
	private static array $rules = [];

	public static function setUpBeforeClass(): void {
		self::offHoursNow();
		self::$admin = self::admin();
		self::$ana = self::person();
		self::$carla = self::person();
		self::$editors = self::group(self::$ana);
		self::editorGroups(self::$editors);
		self::$name = 'Protocolos de flujo ' . bin2hex(random_bytes(3));
		self::$rule = self::rule(self::$name, '/^flujo-/', '\OCP\Files::postCreate');
		self::rule('Copias de flujo', '/^flujo-/', '\OCP\Files::postCopy');
	}

	public static function tearDownAfterClass(): void {
		foreach (self::$rules as $id) {
			self::ocs(self::$admin, 'DELETE', self::FLOW . '/' . $id);
		}
		parent::tearDownAfterClass();
	}

	public function testEditorsSeeTheFlowRuleReadOnly(): void {
		$listed = self::ocs(self::$ana, 'GET', self::API . '/actions')['data']['flowRules'];
		self::assertContains(['id' => self::$rule, 'name' => self::$name, 'instructions' => self::INSTRUCTIONS], $listed);
	}

	public function testAMatchingFileIsReviewedInOffHoursAsWhoeverAddedIt(): void {
		self::upload(self::$carla, 'flujo-curacion.txt', self::document());
		self::upload(self::$carla, 'otra-curacion.txt', self::document());
		$review = self::flowReview(self::$carla, 'flujo-curacion.txt');
		self::assertSame('waiting', $review['state'], 'in line for Off hours');
		self::assertSame([], self::reviewsOf(self::$carla, 'otra-curacion.txt'), 'only the file the rule matches');
		$done = self::reviewed(self::$carla, $review['id'], self::REVIEW);
		self::assertSame(self::INSTRUCTIONS, $done['instructions']);
		self::assertCount(1, self::notified(self::$carla, 'Revisión lista'), 'whoever added it is told');
	}

	public function testACopiedFolderPutsItsFilesInLine(): void {
		self::folder(self::$carla, 'carpeta');
		self::upload(self::$carla, 'carpeta/copiado.txt', self::document());
		self::transfer(self::$carla, 'COPY', 'carpeta', 'flujo-carpeta');
		self::assertSame('waiting', self::flowReview(self::$carla, 'copiado.txt')['state']);
	}

	public function testATagAssignedPutsTheFileInLine(): void {
		self::rule('Etiquetas de flujo', '/^etiqueta-/', 'OCP\SystemTag\ISystemTagObjectMapper::assignTags');
		$fileId = self::upload(self::$carla, 'etiqueta-curacion.txt', self::document());
		self::assertSame([], self::reviewsOf(self::$carla, 'etiqueta-curacion.txt'), 'not before the tag');
		self::occ('tag:files:add', (string)$fileId, 'revisar-' . bin2hex(random_bytes(3)), 'public');
		self::assertSame('waiting', self::flowReview(self::$carla, 'etiqueta-curacion.txt')['state']);
	}

	public function testAnAutomaticActionsFolderKeepsItsOwnReview(): void {
		$folder = self::folder(self::$ana, 'flujo-normas');
		$created = self::ocs(self::$ana, 'POST', self::API . '/actions', ['name' => 'Normas', 'editorGroup' => self::$editors, 'folder' => (string)$folder, 'instructions' => '']);
		self::assertSame(201, $created['status'], json_encode($created['data']));
		self::upload(self::$ana, 'flujo-normas/flujo-norma.txt', self::document());
		self::assertSame(['action'], array_column(self::reviewsOf(self::$ana, 'flujo-norma.txt'), 'origin'), 'the action\'s, not the rule\'s');
	}

	public function testADeletedRulesReviewFails(): void {
		$gone = self::rule('Para borrar', '/^borrar-/', '\OCP\Files::postCreate');
		self::upload(self::$carla, 'borrar-curacion.txt', self::document());
		$id = self::flowReview(self::$carla, 'borrar-curacion.txt')['id'];
		self::assertSame(200, self::ocs(self::$admin, 'DELETE', self::FLOW . '/' . $gone)['status']);
		$review = self::waitUntil(self::$carla, $id, static function (array $review): bool {
			self::tick();
			return $review['state'] === 'failed';
		}, self::REVIEW, 'failed');
		self::assertSame('Se eliminó la regla de Flujo.', $review['error']);
	}

	#[Depends('testAMatchingFileIsReviewedInOffHoursAsWhoeverAddedIt')]
	public function testWithNoSessionItFailsIntoEstadoAndNobodyIsTold(): void {
		$local = tempnam(sys_get_temp_dir(), 'ia');
		file_put_contents($local, self::document());
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

	/** Makes a Flow rule «Revisar con IA» for files whose name matches $pattern on $event; answers its id. */
	private static function rule(string $name, string $pattern, string $event): int {
		$created = self::ocs(self::$admin, 'POST', self::FLOW, [
			'class' => 'OCA\Ia\Flow\ReviewOperation',
			'name' => '',
			'checks' => [['class' => 'OCA\WorkflowEngine\Check\FileName', 'operator' => 'matches', 'value' => $pattern]],
			'operation' => json_encode(['name' => $name, 'instructions' => self::INSTRUCTIONS]),
			'entity' => 'OCA\WorkflowEngine\Entity\File',
			'events' => [$event],
		]);
		self::assertSame(200, $created['status'], json_encode($created['data']));
		return self::$rules[] = (int)$created['data']['id'];
	}

	/** @return list<array<string, mixed>> $person's Reviews of a file named $name */
	private static function reviewsOf(string $person, string $name): array {
		return array_values(array_filter(self::ocs($person, 'GET', self::API . '/reviews')['data']['reviews'],
			static fn (array $review): bool => $review['name'] === $name));
	}

	/** @return array<string, mixed> the one Flow rule Review of $name that $person sees */
	private static function flowReview(string $person, string $name): array {
		$found = array_values(array_filter(self::reviewsOf($person, $name), static fn (array $review): bool => $review['origin'] === 'flow'));
		self::assertCount(1, $found, "one Flow rule Review of $name");
		return $found[0];
	}

	private static function document(): string {
		return (string)file_get_contents(__DIR__ . '/fixtures/curacion-corto.txt');
	}
}
