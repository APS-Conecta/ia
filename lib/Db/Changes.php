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
		$files = [];
		$calendars = [];
		foreach ($sources as $source) {
			if ($source['type'] === 'calendar') {
				$calendars[] = (int)$source['id'];
			} else {
				$files[] = (int)$source['id'];
			}
		}
		$etags = $this->column('filecache', 'fileid', 'etag', $files);
		$tokens = $this->column('calendars', 'id', 'synctoken', $calendars);
		$state = [];
		foreach ($files as $id) {
			$state["file:$id"] = $etags[$id] ?? null;
		}
		foreach ($calendars as $id) {
			$state["calendar:$id"] = $tokens[$id] ?? null;
		}
		ksort($state);
		return json_encode($state, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, string> $value of the rows of $table whose $key is one of $ids, by $key
	 */
	private function column(string $table, string $key, string $value, array $ids): array {
		if ($ids === []) {
			return [];
		}
		$query = $this->db->getQueryBuilder();
		$query->select($key, $value)->from($table)
			->where($query->expr()->in($key, $query->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $query->executeQuery();
		$found = [];
		while ($row = $result->fetch()) {
			$found[(int)$row[$key]] = (string)$row[$value];
		}
		$result->closeCursor();
		return $found;
	}
}
