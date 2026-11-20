<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Action> */
class ActionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ia_actions', Action::class);
	}

	public function byId(int $id): ?Action {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($query);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Action> every Automatic action, by name: few per establishment */
	public function all(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())->orderBy('name')->addOrderBy('id');
		return $this->findEntities($query);
	}
}
