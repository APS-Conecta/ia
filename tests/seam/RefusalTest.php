<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * What «IA» can't review yet is turned down at once, with a reason in Spanish, and leaves no
 * Review behind (APS-Conecta/ia#2; other formats come with #4, longer documents with #6).
 */
final class RefusalTest extends SeamTestCase {
	public function testAFormatOtherThanTxtOrMdIsRefused(): void {
		$owner = self::person();
		$fileId = self::upload($owner, 'planilla.csv', "fecha,dosis\n2026-11-19,500 mg\n");
		$this->assertRefused($owner, $fileId);
	}

	public function testADocumentOverTheShortSizeIsRefused(): void {
		$owner = self::person();
		$fileId = self::upload($owner, 'manual-completo.txt', str_repeat("Paso del procedimiento que se repite en este manual largo.\n", 600));
		$this->assertRefused($owner, $fileId);
	}

	private function assertRefused(string $owner, int $fileId): void {
		$answer = self::ocs($owner, 'POST', '/apps/ia/api/v1/reviews', ['fileId' => $fileId]);
		self::assertSame(422, $answer['status']);
		self::assertNotSame('', trim($answer['data']['message']));
		self::assertSame([], self::ocs($owner, 'GET', '/apps/ia/api/v1/reviews')['data']['reviews']);
	}
}
