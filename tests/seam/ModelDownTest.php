<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Group;

/**
 * A Review while the Model is down (APS-Conecta/ia#2, #6): `make test-seam` stops Ollama around
 * this group. Each failed task puts the Run back in line for the next tick; the third fails it with
 * its last error, and it stays failed. The document's text reaches no log.
 */
#[Group('model-down')]
final class ModelDownTest extends SeamTestCase {
	/** Seconds the connector may take to give up on a stopped Ollama. */
	private const WAIT = 300;

	private static string $owner;
	private static string $sentinel;
	/** @var list<string> the Review's state after each failed attempt */
	private static array $states = [];
	/** @var array<string, mixed> */
	private static array $review;

	public static function setUpBeforeClass(): void {
		self::$owner = self::person();
		self::$sentinel = 'IA-SENTINEL-' . bin2hex(random_bytes(6));
		$text = (string)file_get_contents(__DIR__ . '/fixtures/curacion-corto.txt') . "\nCódigo de control del documento: " . self::$sentinel . "\n";
		$fileId = self::upload(self::$owner, 'curacion.txt', $text);
		$created = self::ocs(self::$owner, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => $fileId]);
		self::assertSame(201, $created['status']);
		$id = $created['data']['review']['id'];
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			self::$review = self::waitFor(self::$owner, $id, ['waiting', 'failed'], self::WAIT);
			self::$states[] = self::$review['state'];
			self::tick();
		}
		self::$review = self::ocs(self::$owner, 'GET', "/apps/ia/api/v1/reviews/$id")['data']['review'];
	}

	public function testThreeFailedTasksFailTheReviewWithItsErrorAndItStaysFailed(): void {
		self::assertSame(['waiting', 'waiting', 'failed'], self::$states);
		self::assertSame('failed', self::$review['state'], 'a tick after the third failure asks nothing again');
		self::assertNotSame('', trim((string)self::$review['error']));
		self::assertNull(self::$review['findings']);
		$listed = self::ocs(self::$owner, 'GET', '/apps/ia/api/v1/reviews')['data']['reviews'];
		self::assertSame(['failed'], array_column($listed, 'state'));
	}

	public function testNoLogHoldsTheDocument(): void {
		self::assertSame([], self::logsHolding(self::$sentinel));
	}

	public function testTaskProcessingKeepsNoTaskOfTheApp(): void {
		self::assertSame([], self::tasks());
	}
}
