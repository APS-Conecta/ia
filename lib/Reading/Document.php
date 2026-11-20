<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Reading;

/**
 * A file version as TextReader read it: its text, its length in pages, and where each part of
 * the text stands: a page of a PDF, a section of a DOCX, ODT or MD (its heading), otherwise the
 * line.
 */
final class Document {
	/**
	 * @param array<int, string> $marks the byte offset where each page or section starts, and its name
	 */
	public function __construct(
		public readonly string $text,
		public readonly int $pages,
		private readonly array $marks = [],
	) {
	}

	/** Where the text at byte $offset stands, as a person finds it: «Página 2», «Sección «Alcance»», «Línea 14». */
	public function where(int $offset): string {
		$name = null;
		foreach ($this->marks as $start => $mark) {
			if ($start > $offset) {
				break;
			}
			$name = $mark;
		}
		return $name ?? 'Línea ' . (substr_count($this->text, "\n", 0, $offset) + 1);
	}
}
