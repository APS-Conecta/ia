<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Flow;

use OCA\Ia\ResponseDefinitions;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The admin's Flow rules «Revisar con IA» (APS-Conecta/ia#11), read from Nextcloud Flow's own table,
 * as Flow has no API to read them: «Acciones automáticas» lists them read-only, and «Estado» and the
 * engine find one by id. A rule keeps its name and instructions in its operation string (Flow's own
 * name field isn't saved by Flow's settings).
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
		$rules = $this->rules(null);
		usort($rules, static fn (array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);
		return $rules;
	}

	/** @return IaFlowRule|null the rule $id, or null when it is gone */
	public function one(int $id): ?array {
		return $this->rules($id)[0] ?? null;
	}

	/**
	 * A rule's operation string: its name and instructions, as «Revisar con IA»'s settings store them
	 * (JSON); any other string is instructions alone.
	 *
	 * @return array{name: string, instructions: string}
	 */
	public static function parse(string $operation): array {
		$fields = json_decode($operation, true);
		return is_array($fields)
			? ['name' => trim((string)($fields['name'] ?? '')), 'instructions' => trim((string)($fields['instructions'] ?? ''))]
			: ['name' => '', 'instructions' => trim($operation)];
	}

	/** @return list<IaFlowRule> */
	private function rules(?int $id): array {
		$query = $this->db->getQueryBuilder();
		$query->select('id', 'operation')->from('flow_operations')
			->where($query->expr()->eq('class', $query->createNamedParameter(ReviewOperation::class)));
		if ($id !== null) {
			$query->andWhere($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		}
		$result = $query->executeQuery();
		$rules = [];
		while ($row = $result->fetch()) {
			$parsed = self::parse((string)$row['operation']);
			$rules[] = ['id' => (int)$row['id'], 'name' => $parsed['name'] !== '' ? $parsed['name'] : 'Regla de Flujo n.º ' . $row['id'], 'instructions' => $parsed['instructions']];
		}
		$result->closeCursor();
		return $rules;
	}
}
