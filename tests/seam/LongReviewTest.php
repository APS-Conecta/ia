<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * A short document longer than one chunk (APS-Conecta/ia#2): a pass per chunk, then the pass over
 * their summaries, still one well-shaped report.
 */
final class LongReviewTest extends SeamTestCase {
	/** Seconds three passes may take on a CPU runner, inside the seam job's 45 minutes. */
	private const WAIT = 1500;
	private const NAME = 'procedimientos-largo.md';

	public function testADocumentLongerThanOneChunkGetsOneWellShapedReport(): void {
		$owner = self::person();
		$text = (string)file_get_contents(__DIR__ . '/fixtures/' . self::NAME);
		$fileId = self::upload($owner, self::NAME, $text);

		$created = self::ocs($owner, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => $fileId]);
		self::assertSame(201, $created['status']);
		self::assertSame('now', $created['data']['start']);
		self::assertGreaterThan(1, $created['data']['review']['steps'], 'the fixture fits one chunk: make it longer');
		$review = self::waitFor($owner, $created['data']['review']['id'], ['done', 'failed'], self::WAIT);

		self::assertSame('done', $review['state'], 'the Review failed: ' . ($review['error'] ?? ''));
		self::assertSame($review['steps'], $review['step']);
		self::assertIsArray($review['findings']);
		foreach ($review['findings'] as $finding) {
			self::assertContains($finding['kind'], ['error', 'missing', 'unclear']);
			self::assertNotSame('', trim($finding['where']));
			self::assertNotSame('', trim($finding['text']));
			self::assertNotSame('', trim($finding['fix']));
			if ($finding['quote'] !== null) {
				self::assertStringContainsString($finding['quote'], $text);
				self::assertMatchesRegularExpression('/^Sección «.+»$/', $finding['where'], 'an MD finding names its section');
			}
		}
		self::assertSame([], self::tasks());
	}
}
