<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Reading;

use OCA\Ia\Refused;
use OCA\Ia\Vendor\Smalot\PdfParser\Config;
use OCA\Ia\Vendor\Smalot\PdfParser\Parser;
use OCP\Files\File;
use OCP\ITempManager;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * A file version as a Document: its text by page or section, and its page count (gestion-aio#162,
 * TextReader).
 *
 * - **TXT and MD** as they are; an MD heading starts a section.
 * - **PDF** through smalot/pdfparser, vendored with a prefix (scripts/vendor-pdfparser.sh). A PDF
 *   with next to no text on its pages is a scan, which «IA» doesn't read in v1 (no OCR).
 * - **DOCX and ODT** through PHP's zip and XML readers; a heading starts a section, and the page
 *   count is the one the file's own statistics give.
 * - Elsewhere a page is CHARS_PER_PAGE characters. A file that won't open is refused in Spanish.
 */
class TextReader {
	/** A page of text, about 500 words: what "about 10 pages" counts in when a file doesn't say. */
	public const CHARS_PER_PAGE = 3000;

	/** Letters a PDF page has on average below which it is a scanned image, not text. */
	private const SCAN_LETTERS = 40;

	private const FORMATS = 'Por ahora «IA» revisa archivos PDF, DOCX, ODT, TXT y MD.';
	private const UNREADABLE = 'No se pudo leer el archivo: puede estar dañado o protegido con contraseña.';
	private const SCANNED = 'Es un documento escaneado: «IA» todavía no lee escaneos. Sube una versión con texto (PDF de texto, DOCX u ODT).';

	public function __construct(
		private ITempManager $temp,
	) {
	}

	/** @throws Refused when «IA» can't read it */
	public function read(File $file): Document {
		$format = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		if (!in_array($format, ['txt', 'md', 'pdf', 'docx', 'odt'], true)) {
			throw new Refused(self::FORMATS);
		}
		try {
			$content = (string)$file->getContent();
			return match ($format) {
				'txt' => self::plain($content, []),
				'md' => self::markdown($content),
				'pdf' => self::pdf($content),
				'docx' => $this->zipped($content, 'word/document.xml', 'docProps/app.xml', self::docx(...), '~<Pages>(\d+)</Pages>~'),
				'odt' => $this->zipped($content, 'content.xml', 'meta.xml', self::odt(...), '~meta:page-count="(\d+)"~'),
			};
		} catch (Refused $e) {
			throw $e;
		} catch (Throwable) {
			throw new Refused(self::UNREADABLE);
		}
	}

	/** @param array<int, string> $marks */
	private static function plain(string $content, array $marks): Document {
		$text = self::utf8($content);
		return new Document($text, self::pagesOf($text), $marks);
	}

	private static function markdown(string $content): Document {
		$text = self::utf8($content);
		preg_match_all('/^#{1,6}[ \t]+(.+?)[ \t#]*$/m', $text, $headings, PREG_OFFSET_CAPTURE);
		$marks = [];
		foreach ($headings[1] as $i => [$title]) {
			$marks[$headings[0][$i][1]] = "Sección «{$title}»";
		}
		return new Document($text, self::pagesOf($text), $marks);
	}

	private static function pdf(string $content): Document {
		$config = new Config();
		$config->setRetainImageContent(false);
		$pages = (new Parser([], $config))->parseContent($content)->getPages();
		$text = '';
		$marks = [];
		$letters = 0;
		foreach (array_values($pages) as $i => $page) {
			$pageText = trim(self::utf8($page->getText()));
			$letters += (int)preg_match_all('/\pL/u', $pageText);
			$marks[strlen($text)] = 'Página ' . ($i + 1);
			$text .= $pageText . "\n\n";
		}
		if ($pages === [] || $letters < self::SCAN_LETTERS * count($pages)) {
			throw new Refused(self::SCANNED);
		}
		return new Document(rtrim($text), count($pages), $marks);
	}

	/**
	 * A DOCX or ODT: $body's XML read by $read into text and section marks, and the page count
	 * from $statistics when $pageCount finds one there.
	 *
	 * @param callable(string): array{0: string, 1: array<int, string>} $read
	 */
	private function zipped(string $content, string $body, string $statistics, callable $read, string $pageCount): Document {
		$path = $this->temp->getTemporaryFile();
		file_put_contents($path, $content);
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			throw new Refused(self::UNREADABLE);
		}
		$xml = $zip->getFromName($body);
		$stats = (string)$zip->getFromName($statistics);
		$zip->close();
		if ($xml === false) {
			throw new Refused(self::UNREADABLE);
		}
		[$text, $marks] = $read($xml);
		$pages = preg_match($pageCount, $stats, $count) === 1 && (int)$count[1] > 0 ? (int)$count[1] : self::pagesOf($text);
		return new Document($text, $pages, $marks);
	}

	/**
	 * word/document.xml's paragraphs; a paragraph styled as a heading, or with an outline level,
	 * starts a section.
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function docx(string $xml): array {
		$reader = self::reader($xml);
		$text = '';
		$marks = [];
		$paragraph = null;
		$heading = false;
		while ($reader->read()) {
			$element = $reader->nodeType === XMLReader::ELEMENT;
			$name = $reader->localName;
			if ($element && $name === 'p') {
				[$paragraph, $heading] = ['', false];
				if (!$reader->isEmptyElement) {
					continue;
				}
			}
			if ($paragraph !== null && $element) {
				$paragraph .= match ($name) {
					't' => $reader->readString(),
					'tab' => "\t",
					'br', 'cr' => "\n",
					default => '',
				};
				$heading = $heading || $name === 'outlineLvl'
					|| ($name === 'pStyle' && preg_match('/^(heading|t[ií]?tulo|title)\s*\d*$/i', (string)$reader->getAttributeNs('val', $reader->namespaceURI)) === 1);
			}
			if ($paragraph !== null && $name === 'p' && ($reader->nodeType === XMLReader::END_ELEMENT || $reader->isEmptyElement)) {
				[$text, $marks] = self::paragraph($text, $marks, $paragraph, $heading);
				$paragraph = null;
			}
		}
		return [rtrim($text), $marks];
	}

	/**
	 * content.xml's paragraphs and headings (text:h starts a section), their spaces, tabs and
	 * line breaks.
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function odt(string $xml): array {
		$reader = self::reader($xml);
		$text = '';
		$marks = [];
		$paragraph = null;
		$heading = false;
		$depth = 0;
		while ($reader->read()) {
			$name = $reader->localName;
			$block = $name === 'p' || $name === 'h';
			if ($reader->nodeType === XMLReader::ELEMENT && $block && !$reader->isEmptyElement) {
				if ($depth++ === 0) {
					[$paragraph, $heading] = ['', $name === 'h'];
				}
				continue;
			}
			if ($paragraph === null) {
				continue;
			}
			if ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::SIGNIFICANT_WHITESPACE) {
				$paragraph .= $reader->value;
			} elseif ($reader->nodeType === XMLReader::ELEMENT) {
				$paragraph .= match ($name) {
					's' => str_repeat(' ', max(1, (int)$reader->getAttributeNs('c', $reader->namespaceURI))),
					'tab' => "\t",
					'line-break' => "\n",
					default => '',
				};
			} elseif ($reader->nodeType === XMLReader::END_ELEMENT && $block && --$depth === 0) {
				[$text, $marks] = self::paragraph($text, $marks, $paragraph, $heading);
				$paragraph = null;
			}
		}
		return [rtrim($text), $marks];
	}

	/**
	 * $text with $paragraph added as its own line; a heading's line starts a section.
	 *
	 * @param array<int, string> $marks
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function paragraph(string $text, array $marks, string $paragraph, bool $heading): array {
		$paragraph = trim($paragraph);
		if ($heading && $paragraph !== '') {
			$marks[strlen($text)] = "Sección «{$paragraph}»";
		}
		return [$text . $paragraph . "\n", $marks];
	}

	private static function reader(string $xml): XMLReader {
		$reader = new XMLReader();
		if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
			throw new Refused(self::UNREADABLE);
		}
		return $reader;
	}

	/** $content in UTF-8 with \n line ends, without a byte order mark. */
	private static function utf8(string $content): string {
		if (str_starts_with($content, "\u{FEFF}")) {
			$content = substr($content, 3);
		}
		if (!mb_check_encoding($content, 'UTF-8')) {
			$content = (string)mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
		}
		return str_replace(["\r\n", "\r"], "\n", $content);
	}

	private static function pagesOf(string $text): int {
		return max(1, (int)ceil(mb_strlen($text) / self::CHARS_PER_PAGE));
	}
}
