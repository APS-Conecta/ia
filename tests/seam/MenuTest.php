<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/** «IA» in the suite menu, for everyone. */
class MenuTest extends SeamTestCase {
	public function testTheMenuOffersIaToEveryone(): void {
		$staff = self::person();

		$entries = self::ocs($staff, 'GET', '/core/navigation/apps')['data'];

		self::assertContains('ia', array_column($entries, 'id'));
	}
}
