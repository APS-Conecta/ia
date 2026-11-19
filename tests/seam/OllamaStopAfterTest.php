<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\Group;

/**
 * The Model stops mid-task (APS-Conecta/ia#6), second phase, with Ollama back: the failed step is
 * asked again at the next tick, and the Run finishes with a complete report.
 */
#[Group('ollama-stop-b')]
final class OllamaStopAfterTest extends SeamTestCase {
	private const WAIT = 1500;

	public function testTheRunFinishesOnceTheModelIsBack(): void {
		['people' => [$owner], 'id' => $id] = self::takeOver();
		$interrupted = self::waitFor($owner, $id, ['waiting', 'done', 'failed'], 300);
		self::assertSame('waiting', $interrupted['state'], 'the stopped task puts the Run back in line');

		self::tick();
		$review = self::waitFor($owner, $id, ['done', 'failed'], self::WAIT);
		self::assertSame('done', $review['state'], 'the Review failed: ' . ($review['error'] ?? ''));
		self::assertSame($review['steps'], $review['step']);
		self::assertIsArray($review['findings']);
		self::assertSame([], self::tasks());
	}
}
