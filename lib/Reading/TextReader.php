<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Reading;

use OCA\Ia\Refused;
use OCA\Ia\Vendor\Smalot\PdfParser\Config;
use OCA\Ia\Vendor\Smalot\PdfParser\Parser;
use OCP\Files\File;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * A file version as a Document: its text by page or section, and its page count (gestion-aio#162,
 * TextReader).
 *
 * - **TXT and MD** as they are; an MD heading starts a section.
 * - **PDF** through smalot/pdfparser, vendored with a prefix (scripts/vendor-pdfparser.sh). A PDF
 *   whose pages are mostly without text is a scan, which «IA» doesn't read in v1 (no OCR).
 * - **DOCX and ODT** through PHP's zip and XML readers; a heading starts a section; text boxes are
 *   read once, deleted changes and comments not at all. The page count is the larger of the
 *   file's own statistics and CHARS_PER_PAGE characters a page.
 * - A file that won't open, or holds no text, is refused in Spanish; why is logged by its class
 *   and the file's id only, never its text.
 */
class TextReader {
	/** A page of text, about 500 words: what "about 10 pages" counts in when a file doesn't say. */
	private const CHARS_PER_PAGE = 3000;

	/** Letters below which a PDF page is a scanned image, not text; a PDF is a scan when most pages are. */
	private const SCAN_LETTERS = 40;

	private const READS = ['txt', 'md', 'pdf', 'docx', 'odt'];
	private const FORMATS = 'Por ahora «IA» revisa archivos PDF, DOCX, ODT, TXT y MD.';
	private const UNREADABLE = 'No se pudo leer el archivo: puede estar dañado o protegido con contraseña.';
	private const SCANNED = 'Es un documento escaneado: «IA» todavía no lee escaneos. Sube una versión con texto (PDF de texto, DOCX u ODT).';
	private const NO_TEXT = 'El archivo no tiene texto que «IA» pueda leer: puede estar vacío, ser un escaneo o estar protegido con contraseña.';
	/** A paragraph style that makes a DOCX heading: Word's own ids, in English or Spanish. */
	private const HEADING = '/^(heading|t[ií]?tulo|title)\s*\d*$/iu';

	public function __construct(
		private ITempManager $temp,
		private LoggerInterface $logger,
	) {
	}

	/** Whether «IA» reads files of $name's format. */
	public static function reads(string $name): bool {
		return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::READS, true);
	}

	/** @throws Refused when «IA» can't read it */
	public function read(File $file): Document {
		$format = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		if (!self::reads($file->getName())) {
			throw new Refused(self::FORMATS);
		}
		$content = (string)$file->getContent();
		try {
			$document = match ($format) {
				'txt' => self::plain($content, []),
				'md' => self::markdown($content),
				'pdf' => self::pdf($content),
				'docx' => $this->zipped($content, 'word/document.xml', 'docProps/app.xml', self::docx(...), '~<Pages>(\d+)</Pages>~'),
				'odt' => $this->zipped($content, 'content.xml', 'meta.xml', self::odt(...), '~meta:page-count="(\d+)"~'),
			};
		} catch (Refused $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->warning('Could not read the file {id}: {class}', ['id' => $file->getId(), 'class' => $e::class]);
			throw new Refused(self::UNREADABLE);
		}
		if (preg_match('/\pL/u', $document->text) !== 1) {
			throw new Refused(self::NO_TEXT);
		}
		return $document;
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
		if ($pages === []) {
			throw new Refused(self::UNREADABLE);
		}
		$text = '';
		$marks = [];
		$images = 0;
		foreach (array_values($pages) as $i => $page) {
			$pageText = trim(self::utf8($page->getText()));
			$images += (int)preg_match_all('/\pL/u', $pageText) < self::SCAN_LETTERS ? 1 : 0;
			$marks[strlen($text)] = 'Página ' . ($i + 1);
			$text .= $pageText . "\n\n";
		}
		if ($images * 2 > count($pages)) {
			throw new Scanned(self::SCANNED);
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
		try {
			file_put_contents($path, $content);
			$zip = new ZipArchive();
			if ($zip->open($path, ZipArchive::RDONLY) !== true) {
				throw new Refused(self::UNREADABLE);
			}
			$xml = $zip->getFromName($body);
			$stats = (string)$zip->getFromName($statistics);
			$zip->close();
		} finally {
			unlink($path);
		}
		if ($xml === false) {
			throw new Refused(self::UNREADABLE);
		}
		$errors = libxml_use_internal_errors(true);
		try {
			[$text, $marks] = $read($xml);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($errors);
		}
		$stated = preg_match($pageCount, $stats, $count) === 1 ? (int)$count[1] : 0;
		return new Document($text, max($stated, self::pagesOf($text)), $marks);
	}

	/**
	 * word/document.xml's paragraphs. A paragraph styled as a heading, or with an outline level
	 * above body text, starts a section; a paragraph inside another (a text box) joins it, and
	 * mc:Fallback, which repeats a text box for older readers, is skipped.
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function docx(string $xml): array {
		$reader = self::reader($xml);
		$text = '';
		$marks = [];
		$paragraph = '';
		$heading = false;
		$depth = 0;
		$skip = 0;
		while ($reader->read()) {
			$name = $reader->localName;
			$element = $reader->nodeType === XMLReader::ELEMENT;
			$end = $reader->nodeType === XMLReader::END_ELEMENT;
			if ($name === 'Fallback' && (($element && !$reader->isEmptyElement) || $end)) {
				$skip += $element ? 1 : -1;
				continue;
			}
			if ($skip > 0) {
				continue;
			}
			if ($name === 'p' && (($element && !$reader->isEmptyElement) || $end)) {
				if ($element && $depth++ === 0) {
					[$paragraph, $heading] = ['', false];
				} elseif ($end && --$depth === 0) {
					[$text, $marks] = self::paragraph($text, $marks, $paragraph, $heading);
				} elseif ($end) {
					$paragraph .= "\n";
				}
				continue;
			}
			if ($depth === 0 || !$element) {
				continue;
			}
			$paragraph .= match ($name) {
				't' => $reader->readString(),
				'tab' => "\t",
				'br', 'cr' => "\n",
				default => '',
			};
			$value = (string)$reader->getAttributeNs('val', $reader->namespaceURI);
			$heading = $heading || ($depth === 1 && (($name === 'pStyle' && preg_match(self::HEADING, $value) === 1) || ($name === 'outlineLvl' && (int)$value < 9)));
		}
		return [rtrim($text), $marks];
	}

	/**
	 * content.xml's paragraphs and headings (text:h starts a section), their spaces, tabs and
	 * line breaks; tracked deletions and comments are skipped.
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function odt(string $xml): array {
		$reader = self::reader($xml);
		$text = '';
		$marks = [];
		$paragraph = '';
		$heading = false;
		$depth = 0;
		$skip = 0;
		while ($reader->read()) {
			$name = $reader->localName;
			$element = $reader->nodeType === XMLReader::ELEMENT;
			$end = $reader->nodeType === XMLReader::END_ELEMENT;
			if (($name === 'tracked-changes' || $name === 'annotation') && (($element && !$reader->isEmptyElement) || $end)) {
				$skip += $element ? 1 : -1;
				continue;
			}
			if ($skip > 0) {
				continue;
			}
			if (($name === 'p' || $name === 'h') && (($element && !$reader->isEmptyElement) || $end)) {
				if ($element && $depth++ === 0) {
					[$paragraph, $heading] = ['', $name === 'h'];
				} elseif ($end && --$depth === 0) {
					[$text, $marks] = self::paragraph($text, $marks, $paragraph, $heading);
				} elseif ($end) {
					$paragraph .= "\n";
				}
				continue;
			}
			if ($depth === 0) {
				continue;
			}
			if ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::SIGNIFICANT_WHITESPACE) {
				$paragraph .= $reader->value;
			} elseif ($element) {
				$paragraph .= match ($name) {
					's' => str_repeat(' ', max(1, (int)$reader->getAttributeNs('c', $reader->namespaceURI))),
					'tab' => "\t",
					'line-break' => "\n",
					default => '',
				};
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
