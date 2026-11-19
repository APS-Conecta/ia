<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Reading;

use OCA\Ia\Refused;
use OCP\Files\File;

/**
 * A file version as text, and its length in pages (gestion-aio#162, TextReader). TXT and MD
 * are read as they are; the other formats come with APS-Conecta/ia#4.
 */
class TextReader {
	/** A page of text, about 500 words: what "about 10 pages" counts in. */
	public const CHARS_PER_PAGE = 3000;

	private const FORMATS = ['txt', 'md'];

	/** The file's text, in UTF-8 with \n line ends. */
	public function read(File $file): string {
		if (!in_array(strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)), self::FORMATS, true)) {
			throw new Refused('Por ahora «IA» revisa archivos de texto TXT y MD.');
		}
		$text = (string)$file->getContent();
		if (str_starts_with($text, "\u{FEFF}")) {
			$text = substr($text, 3);
		}
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = (string)mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
		}
		return str_replace(["\r\n", "\r"], "\n", $text);
	}

	public static function pages(string $text): int {
		return max(1, (int)ceil(mb_strlen($text) / self::CHARS_PER_PAGE));
	}
}
