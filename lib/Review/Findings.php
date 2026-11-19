<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Review;

use OCA\Ia\ResponseDefinitions;

/**
 * A Review's findings from the Model's lines, validated against the document's text
 * (gestion-aio#162, Reviews): a finding that quotes what the text does not say is dropped, and a
 * quote kept is the text's own words, with the line it starts on as where it is.
 *
 * @psalm-import-type IaFinding from ResponseDefinitions
 */
final class Findings {
	public const ERROR = 'error';
	public const UNCLEAR = 'unclear';
	public const MISSING = 'missing';

	/** The kinds as the prompts name them, upper-cased without accents. */
	private const KINDS = ['ERROR' => self::ERROR, 'POCO CLARO' => self::UNCLEAR, 'FALTA' => self::MISSING];
	private const NO_QUOTE = ['', '-', '—', 'NINGUNA', 'N/A'];
	private const WHOLE = 'Todo el documento';

	/**
	 * @param list<array<string, string>> $lines the Model's lines, as ModelGateway::lines reads them
	 * @param list<string> $kinds the kinds this step asked for
	 * @return list<IaFinding>
	 */
	public static function from(array $lines, string $text, array $kinds): array {
		$findings = [];
		foreach ($lines as $fields) {
			$kind = self::KINDS[self::fold($fields['TIPO'] ?? '')] ?? null;
			$problem = trim($fields['PROBLEMA'] ?? '');
			$fix = trim($fields['ARREGLO'] ?? '');
			if (!in_array($kind, $kinds, true) || $problem === '' || $fix === '') {
				continue;
			}
			$quote = trim($fields['CITA'] ?? '', " \t\"'«»“”‘’");
			if (in_array(self::fold($quote), self::NO_QUOTE, true)) {
				if ($kind !== self::MISSING) {
					continue;
				}
				$finding = ['kind' => $kind, 'where' => trim($fields['DONDE'] ?? '') ?: self::WHOLE, 'quote' => null];
			} else {
				$found = self::find($quote, $text);
				if ($found === null) {
					continue;
				}
				[$quote, $offset] = $found;
				$finding = ['kind' => $kind, 'where' => 'Línea ' . (substr_count($text, "\n", 0, $offset) + 1), 'quote' => $quote];
			}
			$findings[] = $finding + ['text' => $problem, 'fix' => $fix];
		}
		return array_values(array_unique($findings, SORT_REGULAR));
	}

	/**
	 * Where $quote's words stand in $text, whatever the spacing and case between them: the text's
	 * own span and its byte offset, or null.
	 *
	 * @return array{0: string, 1: int}|null
	 */
	private static function find(string $quote, string $text): ?array {
		$words = preg_split('/\s+/u', rtrim($quote, '.,;:…'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		if ($words === []) {
			return null;
		}
		$pattern = '/' . implode('\s+', array_map(static fn (string $word): string => preg_quote($word, '/'), $words)) . '/iu';
		return preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) === 1 ? [$match[0][0], $match[0][1]] : null;
	}

	private static function fold(string $value): string {
		$value = strtr(mb_strtoupper(trim($value, " \t.*")), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
		return (string)preg_replace('/\s+/u', ' ', $value);
	}
}
