<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * A manual Review of a short document (APS-Conecta/ia#2), as its requester and as someone else
 * see it. One Review, asked for once, carries the class: the Model's wording varies, so only
 * what doesn't depend on it is asserted.
 */
final class ShortReviewTest extends SeamTestCase {
	/** Seconds one Review of a short document may take on a CPU runner. */
	private const WAIT = 900;
	private const NAME = 'curacion-corto.txt';
	private const KINDS = ['error', 'missing', 'unclear'];

	private static string $owner;
	private static string $text;
	private static string $sentinel;
	/** @var array{fileId: int, etag: string} */
	private static array $before;
	/** @var array{status: int, data: mixed} */
	private static array $created;
	/** @var array<string, mixed> */
	private static array $review;

	public static function setUpBeforeClass(): void {
		self::$owner = self::person();
		self::$sentinel = 'IA-SENTINEL-' . bin2hex(random_bytes(6));
		self::$text = (string)file_get_contents(__DIR__ . '/fixtures/' . self::NAME) . "\nCódigo de control del documento: " . self::$sentinel . "\n";
		self::upload(self::$owner, self::NAME, self::$text);
		self::$before = self::file(self::$owner, self::NAME);
		self::$created = self::ocs(self::$owner, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => self::$before['fileId']]);
		self::$review = self::$created['status'] === 201
			? self::waitFor(self::$owner, self::$created['data']['review']['id'], ['done', 'failed'], self::WAIT)
			: [];
	}

	public function testAShortDocumentIsReviewedNow(): void {
		self::assertSame(201, self::$created['status']);
		self::assertSame('now', self::$created['data']['start']);
		self::assertSame(self::$before['fileId'], self::$created['data']['review']['fileId']);
		self::assertSame(self::$before['etag'], self::$created['data']['review']['etag']);
		self::assertContains(self::$created['data']['review']['state'], ['working', 'done']);
	}

	public function testTheReportListsFindingsWhoseQuotesAreInTheDocument(): void {
		self::assertSame('done', self::$review['state'], 'the Review failed: ' . (self::$review['error'] ?? ''));
		self::assertNotEmpty(self::$review['findings'], 'a document with a contradiction got no finding');
		foreach (self::$review['findings'] as $finding) {
			self::assertContains($finding['kind'], self::KINDS);
			self::assertNotSame('', trim($finding['where']));
			self::assertNotSame('', trim($finding['text']));
			self::assertNotSame('', trim($finding['fix']));
			if ($finding['kind'] !== 'missing') {
				self::assertIsString($finding['quote'], 'an Error or a Poco claro cites the document');
			}
			if ($finding['quote'] !== null) {
				self::assertStringContainsString($finding['quote'], self::$text, 'a finding quotes what the document does not say');
			}
		}
	}

	public function testTheDocumentIsNeverChanged(): void {
		self::assertSame(self::$before['etag'], self::file(self::$owner, self::NAME)['etag']);
	}

	public function testRevisionesListsTheReviewAsReady(): void {
		$listed = self::ocs(self::$owner, 'GET', '/apps/ia/api/v1/reviews');
		self::assertSame(200, $listed['status']);
		$mine = array_values(array_filter($listed['data']['reviews'], static fn (array $item): bool => $item['id'] === self::$review['id']));
		self::assertCount(1, $mine);
		self::assertSame(self::NAME, $mine[0]['name']);
		self::assertSame('done', $mine[0]['state']);
	}

	public function testTheRequesterIsToldTheReviewIsReady(): void {
		$answer = self::ocs(self::$owner, 'GET', '/apps/notifications/api/v2/notifications');
		self::assertSame(200, $answer['status']);
		$ready = array_filter($answer['data'], static fn (array $notification): bool => $notification['app'] === 'ia'
			&& $notification['object_id'] === (string)self::$review['id'] && str_starts_with($notification['subject'], 'Revisión lista'));
		self::assertCount(1, $ready);
	}

	public function testTaskProcessingKeepsNoTaskOfTheApp(): void {
		self::assertSame([], json_decode(self::occ('taskprocessing:task:list', '--appId=ia', '--output=json'), true));
	}

	public function testNoLogHoldsTheDocumentOrTheModelsAnswer(): void {
		self::assertSame([], self::logsHolding(self::$sentinel), 'the document text reached a log');
		foreach (self::$review['findings'] as $finding) {
			self::assertSame([], self::logsHolding($finding['text']), "the Model's answer reached a log");
		}
	}

	public function testSomeoneWhoCannotOpenTheDocumentGetsNotFound(): void {
		$other = self::person();
		self::assertSame(404, self::ocs($other, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => self::$before['fileId']])['status']);
		self::assertSame(404, self::ocs($other, 'GET', '/apps/ia/api/v1/reviews/' . self::$review['id'])['status']);
		self::assertSame(404, self::ocs($other, 'POST', '/apps/ia/api/v1/reviews/' . self::$review['id'] . '/again')['status']);
		self::assertSame([], self::ocs($other, 'GET', '/apps/ia/api/v1/reviews')['data']['reviews']);
	}

	public function testReviewAgainReviewsTheCurrentVersion(): void {
		$text = str_replace('cada 3 a 5 días', 'cada 2 a 3 días', self::$text);
		self::upload(self::$owner, self::NAME, $text);
		$now = self::file(self::$owner, self::NAME);
		self::assertNotSame(self::$before['etag'], $now['etag']);

		$again = self::ocs(self::$owner, 'POST', '/apps/ia/api/v1/reviews/' . self::$review['id'] . '/again');
		self::assertSame(200, $again['status']);
		self::assertSame('now', $again['data']['start']);
		$review = self::waitFor(self::$owner, self::$review['id'], ['done', 'failed'], self::WAIT);

		self::assertSame('done', $review['state'], 'the Review failed: ' . ($review['error'] ?? ''));
		self::assertSame($now['etag'], $review['etag']);
		foreach ($review['findings'] as $finding) {
			if ($finding['quote'] !== null) {
				self::assertStringContainsString($finding['quote'], $text);
			}
		}
		self::assertSame([], json_decode(self::occ('taskprocessing:task:list', '--appId=ia', '--output=json'), true));
	}

	public function testTheReportIsGoneOnceTheRequesterCannotOpenTheDocument(): void {
		self::remove(self::$owner, self::NAME);
		self::assertSame(404, self::ocs(self::$owner, 'GET', '/apps/ia/api/v1/reviews/' . self::$review['id'])['status']);
		self::assertSame([], self::ocs(self::$owner, 'GET', '/apps/ia/api/v1/reviews')['data']['reviews']);
	}
}
