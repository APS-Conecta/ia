<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Whether a Bulletin's Sources changed (gestion-aio#162, Bulletins): a folder's or document's etag,
 * which a change anywhere under a folder renews, and a calendar's sync token, read from core's
 * file cache and calendars by id. Read-only.
 */
class Changes {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * The Sources' state, as one string that changes when any of them does; a gone one counts as such.
	 *
	 * @param list<array{type: string, id: string}> $sources
	 */
	public function of(array $sources): string {
		$files = array_map(static fn (array $source): int => (int)$source['id'], array_filter($sources, static fn (array $source): bool => $source['type'] !== 'calendar'));
		$calendars = array_map(static fn (array $source): int => (int)$source['id'], array_filter($sources, static fn (array $source): bool => $source['type'] === 'calendar'));
		$state = [];
		foreach ([['filecache', 'fileid', 'etag', 'file', $files], ['calendars', 'id', 'synctoken', 'calendar', $calendars]] as [$table, $key, $column, $type, $ids]) {
			$found = [];
			if ($ids !== []) {
				$query = $this->db->getQueryBuilder();
				$query->select($key, $column)->from($table)
					->where($query->expr()->in($key, $query->createNamedParameter(array_values($ids), IQueryBuilder::PARAM_INT_ARRAY)));
				$result = $query->executeQuery();
				while ($row = $result->fetch()) {
					$found[(int)$row[$key]] = (string)$row[$column];
				}
				$result->closeCursor();
			}
			foreach ($ids as $id) {
				$state["$type:$id"] = $found[$id] ?? null;
			}
		}
		ksort($state);
		return json_encode($state, JSON_THROW_ON_ERROR);
	}
}
