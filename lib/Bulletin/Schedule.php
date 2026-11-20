<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Bulletin;

use DateTimeImmutable;
use OCA\Ia\Refused;
use OCA\Ia\ResponseDefinitions;

/**
 * When a Bulletin's Editions are due: every day, every week on a day (1 Monday … 7 Sunday), every
 * month on a day (1 … 28, so every month has it), or «cuando cambien sus fuentes». An Edition is
 * written in the Off hours that end on its date.
 *
 * @psalm-import-type IaSchedule from ResponseDefinitions
 */
final class Schedule {
	private const KINDS = ['daily' => null, 'weekly' => 7, 'monthly' => 28, 'sources' => null];

	private function __construct(
		public readonly string $kind,
		public readonly ?int $day,
	) {
	}

	/**
	 * @param array<mixed> $input as the request sends it: {kind, day}
	 * @throws Refused when it isn't one
	 */
	public static function from(array $input): self {
		$kind = $input['kind'] ?? null;
		if (!is_string($kind) || !array_key_exists($kind, self::KINDS)) {
			throw new Refused('Elige cuándo sale el boletín: cada día, cada semana, cada mes o cuando cambien sus fuentes.');
		}
		$last = self::KINDS[$kind];
		if ($last === null) {
			return new self($kind, null);
		}
		$day = $input['day'] ?? null;
		if (!is_int($day) || $day < 1 || $day > $last) {
			throw new Refused($kind === 'weekly' ? 'Elige el día de la semana en que sale.' : 'Elige un día del mes entre el 1 y el 28.');
		}
		return new self($kind, $day);
	}

	/** As stored: «daily», «weekly:1», «monthly:15», «sources». */
	public static function parse(string $stored): self {
		[$kind, $day] = explode(':', $stored, 2) + [1 => null];
		return new self($kind, $day === null ? null : (int)$day);
	}

	public function stored(): string {
		return $this->day === null ? $this->kind : "{$this->kind}:{$this->day}";
	}

	/** @return IaSchedule */
	public function view(): array {
		return ['kind' => $this->kind, 'day' => $this->day];
	}

	/** Whether an Edition is due on $date: never for «cuando cambien sus fuentes» (APS-Conecta/ia#13). */
	public function matches(DateTimeImmutable $date): bool {
		return match ($this->kind) {
			'daily' => true,
			'weekly' => (int)$date->format('N') === $this->day,
			'monthly' => (int)$date->format('j') === $this->day,
			default => false,
		};
	}

	/** The latest date (Y-m-d) on or before $date an Edition was due; null when its Sources decide. */
	public function last(DateTimeImmutable $date): ?string {
		return match ($this->kind) {
			'daily' => $date->format('Y-m-d'),
			'weekly' => $date->modify('-' . (((int)$date->format('N') - $this->day + 7) % 7) . ' days')->format('Y-m-d'),
			'monthly' => ((int)$date->format('j') >= $this->day ? $date : $date->modify('first day of last month'))->format('Y-m-') . sprintf('%02d', $this->day),
			default => null,
		};
	}

	/** Where the period of an Edition for $date ends (exclusive): the next due date, or a week on when its Sources decide. */
	public function until(DateTimeImmutable $date): string {
		return $this->due($date) ?? $date->modify('+7 days')->format('Y-m-d');
	}

	/** The date (Y-m-d) the next Edition is due after $today, or null when its Sources decide. */
	public function due(DateTimeImmutable $today): ?string {
		$next = $today->modify('+1 day');
		return match ($this->kind) {
			'daily' => $next->format('Y-m-d'),
			'weekly' => $next->modify('+' . (($this->day - (int)$next->format('N') + 7) % 7) . ' days')->format('Y-m-d'),
			'monthly' => $this->monthly($next),
			default => null,
		};
	}

	/** The first date from $from on whose day of the month is the Schedule's. */
	private function monthly(DateTimeImmutable $from): string {
		$month = (int)$from->format('j') <= $this->day ? $from : $from->modify('first day of next month');
		return $month->setDate((int)$month->format('Y'), (int)$month->format('n'), (int)$this->day)->format('Y-m-d');
	}
}
