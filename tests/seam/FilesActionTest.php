<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * «Revisar con IA» in Files (APS-Conecta/ia#5): Files loads, and the server serves, the script that
 * adds the action to a file's menu. Which files it shows for and its toast are that script's; the
 * Review it starts is the one «IA»'s OCS API starts, with its «Revisión lista» (ShortReviewTest).
 */
final class FilesActionTest extends SeamTestCase {
	public function testFilesLoadsTheScriptThatAddsRevisarConIa(): void {
		$person = self::person();
		[$status, $html] = self::page($person, '/index.php/apps/files/');
		self::assertSame(200, $status);
		self::assertSame(1, preg_match('~src="([^"?]*/ia/js/ia-files\.js)[^"]*"~', $html, $script), 'Files adds «IA»\'s script');
		[$status, $js] = self::page($person, $script[1]);
		self::assertSame(200, $status);
		self::assertStringContainsString('Revisar con IA', $js);
	}
}
