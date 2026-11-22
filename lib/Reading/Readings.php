<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Reading;

use OCA\Ia\Db\Reading;
use OCA\Ia\Db\ReadingMapper;
use OCA\Ia\Model\ModelGateway;
use OCA\Ia\Refused;
use OCA\Ia\Run\Steps;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;

/**
 * Readings (CONTEXT.md; gestion-aio#162, Readings): what the Model takes, once per version, from a
 * document — a short summary, its expiry date and the Cargos it concerns.
 *
 * - **Checked against the document.** An expiry date is kept only if the text says it, in one of
 *   the ways Chilean documents write dates; a Cargo only if it is on the establishment's list,
 *   kept as its group's id.
 * - **A long document** is read part by part; the parts' summaries are merged in one more ask,
 *   and their dates and Cargos pooled. A Run keeps how far it got as its progress: the part it is
 *   at and the parts taken (text(), ask(), take()).
 *
 * @psalm-type IaReadingProgress = array{part: int, parts: list<array{summary: string, expires: ?string, cargos: list<string>}>}
 */
class Readings {
	private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
	private const FORMAT = "Responde con estas líneas, exactamente así:\n"
		. "RESUMEN: qué establece, en dos o tres oraciones\n"
		. 'VENCE: la fecha de vencimiento o de próxima revisión que dice el texto, como AAAA-MM-DD, o - si no la dice';
	private const CARGOS = "\nCARGOS: los cargos de esta lista a los que se dirige, escritos tal como aparecen y separados por punto y coma, o - si ninguno: %s";

	public function __construct(
		private ReadingMapper $readings,
		private TextReader $reader,
		private Settings $settings,
		private ITimeFactory $time,
	) {
	}

	/** The file's Reading when it is of version $etag, else null. */
	public function of(int $fileId, string $etag): ?Reading {
		$reading = $this->readings->ofFile($fileId);
		return $reading?->getEtag() === $etag ? $reading : null;
	}

	/**
	 * The file's text, to read; a scan, or a file «IA» can't read, gets its Reading at once, as its
	 * current version, and null comes back.
	 */
	public function text(File $file): ?Document {
		try {
			return $this->reader->read($file);
		} catch (Scanned) {
			$this->store($file->getId(), $file->getEtag(), Reading::SCAN);
		} catch (Refused) {
			$this->store($file->getId(), $file->getEtag(), Reading::FAILED);
		}
		return null;
	}

	/** The asks a Reading of the document takes: one per part, and the parts' merge when several. */
	public function asks(Document $document): int {
		return Steps::asks(count($this->parts($document)));
	}

	/**
	 * The next ask of the document's Reading, from $progress: its next part, or the parts' merge.
	 *
	 * @param IaReadingProgress $progress
	 */
	public function ask(Document $document, array $progress): string {
		$chunks = $this->parts($document);
		$part = $progress['part'];
		return $part < count($chunks)
			? $this->prompt($part + 1, count($chunks), $chunks[$part])
			: $this->mergePrompt(array_column($progress['parts'], 'summary'));
	}

	/**
	 * Takes the answer to ask() into $progress. Once the document is read whole, its Reading is
	 * stored as version $etag of the file $fileId.
	 *
	 * @param IaReadingProgress $progress
	 */
	public function take(string $answer, Document $document, int $fileId, string $etag, array &$progress): Taken {
		$parts = count($this->parts($document));
		if ($progress['part'] < $parts) {
			$part = $this->part($answer, $document);
			if ($part['summary'] === '') {
				return Taken::Empty;
			}
			$progress['parts'][] = $part;
			$progress['part']++;
			if ($parts > 1) {
				return Taken::Part;
			}
			$summary = $part['summary'];
		} else {
			$summary = $this->merged($answer);
			if ($summary === '') {
				return Taken::Empty;
			}
		}
		$found = array_values(array_filter(array_column($progress['parts'], 'expires')));
		$this->store($fileId, $etag, Reading::READ, $summary, $found[0] ?? null,
			array_values(array_unique(array_merge(...array_column($progress['parts'], 'cargos')))));
		return Taken::Read;
	}

	/** @return list<string> the document in parts that each fit a Reading's ask */
	private function parts(Document $document): array {
		return ModelGateway::chunks($document->text, $this->prompt(1, 2, ''));
	}

	/** The prompt for part $part of $parts of a document, whose text is $chunk. */
	private function prompt(int $part, int $parts, string $chunk): string {
		$what = $parts === 1 ? 'el documento de un centro de salud' : "la parte $part de $parts de un documento de un centro de salud";
		$cargos = $this->settings->cargos();
		return "Lee $what que está entre las marcas <<< y >>>. " . self::FORMAT . ($cargos === [] ? '' : sprintf(self::CARGOS, implode('; ', $cargos)))
			. "\n" . ModelGateway::CLOSE . "\n\n<<<\n$chunk\n>>>";
	}

	/** @param list<string> $summaries the parts' summaries, in order */
	private function mergePrompt(array $summaries): string {
		return "Estos son los resúmenes de las partes de un documento de un centro de salud, entre las marcas <<< y >>>. Escribe una línea, así:\n"
			. "RESUMEN: qué establece el documento entero, en dos o tres oraciones\n" . ModelGateway::CLOSE
			. "\n\n<<<\n" . implode("\n", $summaries) . "\n>>>";
	}

	/**
	 * One part's answer, checked against the document.
	 *
	 * @return array{summary: string, expires: ?string, cargos: list<string>}
	 */
	private function part(string $answer, Document $document): array {
		$fields = self::fields($answer);
		$expires = self::iso(trim($fields['VENCE'] ?? ''));
		return [
			'summary' => trim($fields['RESUMEN'] ?? ''),
			'expires' => $expires !== null && self::dateIn($expires, $document->text) ? $expires : null,
			'cargos' => $this->cargosIn($fields['CARGOS'] ?? ''),
		];
	}

	/** The merged summary of a document read part by part, from the answer to mergePrompt. */
	private function merged(string $answer): string {
		return trim(self::fields($answer)['RESUMEN'] ?? '');
	}

	/** @param list<string> $cargos */
	public function store(int $fileId, string $etag, string $status, ?string $summary = null, ?string $expires = null, array $cargos = []): Reading {
		$reading = $this->readings->ofFile($fileId) ?? new Reading();
		$reading->setFileId($fileId);
		$reading->setEtag($etag);
		$reading->setStatus($status);
		$reading->setSummary($summary);
		$reading->setExpires($expires);
		$reading->setCargos(json_encode(array_values($cargos), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$reading->setReadAt($this->time->getTime());
		return $reading->getId() === null ? $this->readings->insert($reading) : $this->readings->update($reading);
	}

	/** Forgets the Readings of deleted files. @param list<int> $fileIds */
	public function forget(array $fileIds): void {
		$this->readings->forget($fileIds);
	}

	/** @return list<int> the files with a Reading under the folder $folderId, at any depth */
	public function under(int $folderId): array {
		return $this->readings->under($folderId);
	}

	/**
	 * Whether $date (Y-m-d) is a real date that $text says: «2026-12-31», «31/12/2026», «31-12-2026»,
	 * «31.12.2026», «31 de diciembre de 2026» or «… del 2026».
	 */
	public static function dateIn(string $date, string $text): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
			return false;
		}
		[, $year, $month, $day] = $parts;
		$d = '0?' . (int)$day;
		$m = '0?' . (int)$month;
		$name = self::MONTHS[(int)$month - 1];
		$written = [
			"$year-$month-$day",
			$d . '[\/.-]' . $m . '[\/.-]' . $year,
			$d . '\s+de\s+' . $name . '\s+(?:de|del)\s+' . $year,
		];
		return preg_match('/(?<!\d)(?:' . implode('|', $written) . ')(?!\d)/iu', $text) === 1;
	}

	/** A date the Model wrote as AAAA-MM-DD or DD/MM/AAAA (or with - or .), as Y-m-d; null when it is none. */
	private static function iso(string $value): ?string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			return $value;
		}
		return preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $value, $parts) === 1
			? sprintf('%s-%02d-%02d', $parts[3], $parts[2], $parts[1])
			: null;
	}

	/** @return array<string, string> the fields of a line-formatted answer, all its lines together */
	private static function fields(string $answer): array {
		return array_merge(...(ModelGateway::lines($answer) ?: [[]]));
	}

	/**
	 * @return list<string> the ids of the establishment's Cargo groups that $value names: each part
	 *                      between commas or semicolons, or a piece of it between «y» («Abastecimiento
	 *                      y Finanzas» is one Cargo, «Enfermera y TENS» two)
	 */
	private function cargosIn(string $value): array {
		$named = [];
		foreach (preg_split('/\s*[,;]\s*/u', $value) ?: [] as $part) {
			array_push($named, ModelGateway::fold($part), ...array_map(ModelGateway::fold(...), preg_split('/\s+y\s+/u', $part) ?: []));
		}
		$found = array_filter($this->settings->cargos(), static fn (string $cargo): bool => in_array(ModelGateway::fold($cargo), $named, true));
		return array_map(strval(...), array_keys($found));
	}
}
