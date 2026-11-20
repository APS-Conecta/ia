<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Reading> */
class ReadingMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private Places $places,
	) {
		parent::__construct($db, 'ia_readings', Reading::class);
	}

	/** The file's Reading, whatever version it is of, or null. */
	public function ofFile(int $fileId): ?Reading {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('file_id', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($query);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Forgets the Readings of these files: they are gone. @param list<int> $fileIds */
	public function forget(array $fileIds): void {
		foreach (array_chunk($fileIds, 1000) as $chunk) {
			$query = $this->db->getQueryBuilder();
			$query->delete($this->getTableName())
				->where($query->expr()->in('file_id', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	/**
	 * @return list<int> the files with a Reading under the folder $folderId, at any depth: found by
	 *                   the folder's own row, as a share or a Team folder shows it at another path
	 */
	public function under(int $folderId): array {
		$folder = $this->places->of([$folderId])[$folderId] ?? null;
		if ($folder === null) {
			return [];
		}
		['storage' => $storageId, 'path' => $path] = $folder;
		$query = $this->db->getQueryBuilder();
		$query->select('r.file_id')->from($this->getTableName(), 'r')
			->innerJoin('r', 'filecache', 'f', $query->expr()->eq('f.fileid', 'r.file_id'))
			->where($query->expr()->eq('f.storage', $query->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->like('f.path', $query->createNamedParameter($path === '' ? '%' : $this->db->escapeLikeParameter($path) . '/%')));
		$result = $query->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}
}
