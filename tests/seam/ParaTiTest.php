<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/** «Para ti», everyone's start page (CONTEXT.md). */
class ParaTiTest extends SeamTestCase {
	public function testAPersonWhoReadsNoBulletinGetsTheEmptyState(): void {
		$staff = self::person();

		$answer = self::ocs($staff, 'GET', '/apps/ia/api/v1/para-ti');

		self::assertSame(200, $answer['status']);
		self::assertSame(['bulletins' => [], 'editions' => [], 'meetings' => [], 'documents' => [], 'expiring' => []], $answer['data']);
	}

	public function testNobodyReadsItWithoutSigningIn(): void {
		self::assertSame(401, self::ocs(null, 'GET', '/apps/ia/api/v1/para-ti')['status']);
	}
}
