<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Group;

/**
 * The Model stops mid-task (APS-Conecta/ia#6), first phase: a Review is under way when
 * `make test-seam` stops Ollama and starts it again; OllamaStopAfterTest takes over.
 */
#[Group('ollama-stop-a')]
final class OllamaStopBeforeTest extends SeamTestCase {
	public function testAReviewIsUnderWayWhenTheModelStops(): void {
		$owner = self::person();
		$fileId = self::upload($owner, 'procedimientos-largo.md', (string)file_get_contents(__DIR__ . '/fixtures/procedimientos-largo.md'));
		$created = self::ocs($owner, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => $fileId]);
		self::assertSame(201, $created['status']);
		$until = time() + 300;
		while (array_column(self::tasks(), 'status') !== ['STATUS_RUNNING'] && time() < $until) {
			sleep(2);
		}
		self::assertSame(['STATUS_RUNNING'], array_column(self::tasks(), 'status'), 'the task never ran');
		self::handOver(['people' => [$owner], 'id' => $created['data']['review']['id']]);
	}
}
