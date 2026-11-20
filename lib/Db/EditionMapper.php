<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Edition> */
class EditionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ia_editions', Edition::class);
	}

	public function byId(int $id): ?Edition {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($query);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Edition> the Bulletin's Editions, newest date first */
	public function ofBulletin(int $bulletinId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('bulletin_id', $query->createNamedParameter($bulletinId, IQueryBuilder::PARAM_INT)))
			->orderBy('date', 'DESC');
		return $this->findEntities($query);
	}

	/** Deletes the Bulletin's Editions. @return list<int> their ids */
	public function forget(int $bulletinId): array {
		$ids = array_map(static fn (Edition $edition): int => $edition->getId(), $this->ofBulletin($bulletinId));
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('bulletin_id', $query->createNamedParameter($bulletinId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return $ids;
	}

	public function exists(int $bulletinId, string $date): bool {
		$query = $this->db->getQueryBuilder();
		$query->select('id')->from($this->getTableName())
			->where($query->expr()->eq('bulletin_id', $query->createNamedParameter($bulletinId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('date', $query->createNamedParameter($date)));
		$result = $query->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		return $found;
	}
}
