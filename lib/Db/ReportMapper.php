<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Report> */
class ReportMapper extends QBMapper {
	use ForgetsRows;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ia_reports', Report::class);
	}

	public function byId(int $id): ?Report {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($query);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Deletes the reports asked for before $cutoff (an automatic one: lined up, when its file last
	 * arrived or changed). @return list<int> their ids
	 */
	public function forgetBefore(int $cutoff): array {
		return $this->forgetWhere(static fn (IQueryBuilder $query): string => $query->expr()->lt('created_at', $query->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
	}

	/** The person's own manual report of the file $fileId, or null. */
	public function manualOf(string $userId, int $fileId): ?Report {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('requester', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->eq('file_id', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('origin', $query->createNamedParameter(Report::MANUAL)));
		try {
			return $this->findEntity($query);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** The file's automatic report, or null. */
	public function automaticOf(int $fileId): ?Report {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('automatic_file', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($query);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Report> every automatic report, newest first */
	public function automatic(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->isNotNull('automatic_file'))
			->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC');
		return $this->findEntities($query);
	}

	/** @return list<Report> the person's manual reports, newest first */
	public function requestedBy(string $userId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('requester', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->eq('origin', $query->createNamedParameter(Report::MANUAL)))
			->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC');
		return $this->findEntities($query);
	}
}
