<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every v1 format (APS-Conecta/ia#4), read with Assistant installed beside «IA»: a PDF with text, a
 * DOCX and an ODT each give a report that says where each finding is; a scan, a protected or a
 * damaged file is turned down plainly and asks the Model nothing; a PDF's own page count decides
 * whether it waits for Off hours.
 */
final class FormatsTest extends SeamTestCase {
	private const WAIT = 900;

	public static function setUpBeforeClass(): void {
		$apps = json_decode(self::occ('app:list', '--output=json'), true);
		self::assertArrayHasKey('assistant', $apps['enabled'], 'Assistant, with its own pdfparser, is on the instance');
	}

	/** @return iterable<string, array{0: string, 1: string}> each format's fixture, and how its findings say where they are */
	public static function formats(): iterable {
		yield 'PDF' => ['protocolo.pdf', '/^Página [12]$/'];
		yield 'DOCX' => ['protocolo.docx', '/^Sección «.+»$/'];
		yield 'ODT' => ['protocolo.odt', '/^Sección «.+»$/'];
	}

	#[DataProvider('formats')]
	public function testAFormatGivesAReportThatSaysWhereEachFindingIs(string $name, string $where): void {
		$owner = self::person();
		$created = self::ocs($owner, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => self::upload($owner, $name, self::fixture($name))]);
		self::assertSame(201, $created['status'], json_encode($created['data']));
		self::assertSame('now', $created['data']['start']);
		$review = self::waitFor($owner, $created['data']['review']['id'], ['done', 'failed'], self::WAIT);
		self::assertSame('done', $review['state'], 'the Review failed: ' . ($review['error'] ?? ''));
		$quoted = array_filter($review['findings'], static fn (array $finding): bool => $finding['quote'] !== null);
		self::assertNotEmpty($quoted, 'a protocol with a contradiction got no quoted finding');
		foreach ($quoted as $finding) {
			self::assertMatchesRegularExpression($where, $finding['where']);
		}
	}

	/** @return iterable<string, array{0: string, 1: string}> */
	public static function unreadable(): iterable {
		yield 'scan' => ['escaneado.pdf', 'escaneado'];
		yield 'password PDF' => ['protegido.pdf', 'protegido con contraseña'];
		yield 'password ODT' => ['protegido.odt', 'protegido con contraseña'];
		yield 'damaged' => ['danado.pdf', 'dañado'];
	}

	#[DataProvider('unreadable')]
	public function testAFileIaCannotReadIsTurnedDownPlainlyAndAsksTheModelNothing(string $name, string $says): void {
		$owner = self::person();
		$answer = self::ocs($owner, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => self::upload($owner, $name, self::fixture($name))]);
		self::assertSame(422, $answer['status']);
		self::assertStringContainsString($says, $answer['data']['message']);
		self::assertSame([], self::ocs($owner, 'GET', '/apps/ia/api/v1/reviews')['data']['reviews']);
		self::assertSame([], self::tasks());
	}

	/** @return iterable<string, array{0: string}> twelve short pages, by each format's own count */
	public static function twelvePages(): iterable {
		yield 'PDF' => ['protocolo-12-paginas.pdf'];
		yield 'DOCX' => ['protocolo-12-paginas.docx'];
		yield 'ODT' => ['protocolo-12-paginas.odt'];
	}

	#[DataProvider('twelvePages')]
	public function testAFileOverTenPagesWaitsForOffHoursAndFailsPlainlyIfItBreaksMeanwhile(string $name): void {
		self::offHoursLater();
		$owner = self::person();
		$created = self::ocs($owner, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => self::upload($owner, $name, self::fixture($name))]);
		self::assertSame(201, $created['status'], json_encode($created['data']));
		self::assertSame('offHours', $created['data']['start'], 'twelve short pages are still twelve pages');

		self::upload($owner, $name, self::fixture('danado.pdf'));
		self::offHoursNow();
		self::tick();
		self::offHoursLater();
		$review = self::ocs($owner, 'GET', '/apps/ia/api/v1/reviews/' . $created['data']['review']['id'])['data']['review'];
		self::assertSame('failed', $review['state'], 'a Run whose file broke while it waited fails at its turn');
		self::assertStringContainsString('dañado', (string)$review['error']);
		self::assertSame([], self::tasks());
	}

	private static function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/fixtures/' . $name);
	}
}
