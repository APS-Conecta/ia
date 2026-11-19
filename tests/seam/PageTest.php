<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/** «IA» in the suite menu, opening on its own page. */
class PageTest extends SeamTestCase {
	public function testTheMenuOffersIaToEveryone(): void {
		$staff = self::person('personal');

		$entries = self::ocs($staff, 'GET', '/core/navigation/apps')['data'];

		self::assertContains('ia', array_column($entries, 'id'));
	}

	public function testThePageServesTheAppAndItsBundle(): void {
		$staff = self::person('personal');

		$page = self::page($staff, '/index.php/apps/ia/');

		self::assertSame(200, $page['status']);
		self::assertStringContainsString('<div id="ia">', $page['body']);
		self::assertSame(1, preg_match('#src="([^"?]*/ia/js/ia-main\.js)#', $page['body'], $bundle), 'the page loads the bundle');
		self::assertSame(200, self::page($staff, $bundle[1])['status'], 'the bundle the page loads is served');
	}
}
