<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/** For a mapper: deletes the rows a condition picks, and answers their ids for what goes with them. */
trait ForgetsRows {
	/**
	 * @param callable(IQueryBuilder): string $where the condition, built on the query it is given
	 * @return list<int> the ids of the rows deleted
	 */
	private function forgetWhere(callable $where): array {
		$query = $this->db->getQueryBuilder();
		$query->select('id')->from($this->getTableName())->where($where($query));
		$result = $query->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		foreach (array_chunk($ids, 1000) as $chunk) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete($this->getTableName())
				->where($delete->expr()->in('id', $delete->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		return $ids;
	}
}
