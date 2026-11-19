<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Run> */
class RunMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private ITimeFactory $time,
	) {
		parent::__construct($db, 'ia_runs', Run::class);
	}

	/**
	 * Stores the Run's changes, stamped with now, only if no one saved it since it was read: the web
	 * request, the engine's tick and the TaskProcessing worker all move Runs. False when it was
	 * stale and nothing was stored.
	 */
	public function save(Run $run): bool {
		$run->setUpdatedAt($this->time->getTime());
		$version = (int)$run->getVersion();
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName());
		foreach (array_keys($run->getUpdatedFields()) as $property) {
			if ($property !== 'id' && $property !== 'version') {
				$query->set($run->propertyToColumn($property), $query->createNamedParameter($run->{'get' . ucfirst($property)}(), $this->getParameterTypeForProperty($run, $property)));
			}
		}
		$query->set('version', $query->createNamedParameter($version + 1, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('id', $query->createNamedParameter($run->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('version', $query->createNamedParameter($version, IQueryBuilder::PARAM_INT)));
		if ($query->executeStatement() !== 1) {
			return false;
		}
		$run->setVersion($version + 1);
		$run->resetUpdatedFields();
		return true;
	}

	/** @return list<Run> the Runs in $state, oldest first */
	public function inState(string $state): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('state', $query->createNamedParameter($state)))
			->orderBy('id', 'ASC');
		return $this->findEntities($query);
	}

	public function byId(int $id): ?Run {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($query);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function of(string $kind, string $target): ?Run {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('kind', $query->createNamedParameter($kind)))
			->andWhere($query->expr()->eq('target', $query->createNamedParameter($target)));
		try {
			return $this->findEntity($query);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
