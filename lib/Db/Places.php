<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Where files are, read from core's file cache: a file's storage and its path there. Every share
 * and Team folder of a file sees the same row, so whether a file is under a folder is answered the
 * same for everyone. Read-only.
 */
class Places {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param list<int> $fileIds
	 * @return array<int, array{storage: int, path: string}> the files' places, by file id; a file not in the cache is left out
	 */
	public function of(array $fileIds): array {
		$places = [];
		foreach (array_chunk($fileIds, 1000) as $chunk) {
			$query = $this->db->getQueryBuilder();
			$query->select('fileid', 'storage', 'path')->from('filecache')
				->where($query->expr()->in('fileid', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $query->executeQuery();
			while ($row = $result->fetch()) {
				$places[(int)$row['fileid']] = ['storage' => (int)$row['storage'], 'path' => (string)$row['path']];
			}
			$result->closeCursor();
		}
		return $places;
	}

	/**
	 * Whether the place $file is under the folder at $folder, at any depth.
	 *
	 * @param array{storage: int, path: string} $file
	 * @param array{storage: int, path: string} $folder
	 */
	public static function within(array $file, array $folder): bool {
		return $file['storage'] === $folder['storage'] && ($folder['path'] === '' || str_starts_with($file['path'], $folder['path'] . '/'));
	}
}
