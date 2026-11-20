<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Bulletin;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Ia\Access\Access;
use OCA\Ia\ResponseDefinitions;
use OCA\Ia\Settings\Settings;

/**
 * A Reader's Personal list (CONTEXT.md; gestion-aio#162, Bulletins): their own part of an Edition,
 * from their calendars and Cargo groups, without the Model. It is computed when they open it and
 * never stored.
 *
 * @psalm-import-type IaMeeting from ResponseDefinitions
 */
class PersonalList {
	public function __construct(
		private Access $access,
		private Settings $settings,
	) {
	}

	/** @return list<IaMeeting> the timed events in $userId's calendars from $from to $to, each occurrence once, by start */
	public function meetings(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array {
		$meetings = [];
		foreach ($this->access->calendarsOf($userId) as $calendar) {
			$name = Access::calendarName($calendar);
			foreach ($calendar->search('', [], ['timerange' => ['start' => $from, 'end' => $to]]) as $object) {
				foreach ($object['type'] === 'VEVENT' ? $object['objects'] : [] as $event) {
					[$start, $parameters] = $event['DTSTART'] ?? [null, []];
					if (!$start instanceof DateTimeInterface || self::allDay($parameters)) {
						continue;
					}
					$end = $event['DTEND'][0] ?? null;
					$meetings[$object['uid'] . '@' . $start->getTimestamp()] = [
						'title' => (string)($event['SUMMARY'][0] ?? ''),
						'start' => $start->getTimestamp(),
						'end' => $end instanceof DateTimeInterface ? $end->getTimestamp() : null,
						'calendar' => $name,
					];
				}
			}
		}
		usort($meetings, static fn (array $a, array $b): int => [$a['start'], $a['title']] <=> [$b['start'], $b['title']]);
		return $meetings;
	}

	/** @return list<string> the ids of the Cargo groups $userId is in */
	public function cargosOf(string $userId): array {
		return array_values(array_intersect(array_keys($this->settings->cargos()), $this->access->groupsOf($userId)));
	}

	/** @param array<array-key, mixed> $parameters a DTSTART's: an all-day event's is a DATE */
	private static function allDay(array $parameters): bool {
		return isset($parameters['VALUE']) && strtoupper((string)$parameters['VALUE']) === 'DATE';
	}
}
