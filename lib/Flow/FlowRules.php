<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Flow;

use OCA\Ia\ResponseDefinitions;
use OCP\IDBConnection;

/**
 * The admin's Flow rules «Revisar con IA» (APS-Conecta/ia#11), read from Nextcloud Flow's own table:
 * «Acciones automáticas» lists them read-only, and «Estado» names a Run by its rule.
 *
 * @psalm-import-type IaFlowRule from ResponseDefinitions
 */
class FlowRules {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return list<IaFlowRule> by name */
	public function all(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('id', 'name', 'operation')->from('flow_operations')
			->where($query->expr()->eq('class', $query->createNamedParameter(ReviewOperation::class)))
			->orderBy('name');
		$result = $query->executeQuery();
		$rules = [];
		while ($row = $result->fetch()) {
			$rules[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'instructions' => (string)$row['operation']];
		}
		$result->closeCursor();
		return $rules;
	}

	/** The name of the rule $id, or null when it is gone. */
	public function name(int $id): ?string {
		foreach ($this->all() as $rule) {
			if ($rule['id'] === $id) {
				return $rule['name'];
			}
		}
		return null;
	}
}
