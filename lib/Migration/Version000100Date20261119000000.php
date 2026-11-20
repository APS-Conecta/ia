<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The Runs, the Reviews' reports and the Bulletins (gestion-aio#162, Schema). Later tickets add the other
 * tables and the columns their work needs.
 */
class Version000100Date20261119000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ia_runs')) {
			$runs = $schema->createTable('ia_runs');
			$runs->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$runs->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$runs->addColumn('target', Types::STRING, ['notnull' => true, 'length' => 64]);
			$runs->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
			$runs->addColumn('step', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$runs->addColumn('steps', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$runs->addColumn('task_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$runs->addColumn('off_hours', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$runs->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$runs->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$runs->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
			$runs->addColumn('results', Types::TEXT, ['notnull' => false]);
			$runs->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$runs->addColumn('version', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$runs->setPrimaryKey(['id']);
			$runs->addUniqueIndex(['kind', 'target'], 'ia_runs_kind_target');
		}

		if (!$schema->hasTable('ia_reports')) {
			$reports = $schema->createTable('ia_reports');
			$reports->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$reports->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$reports->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 64]);
			$reports->addColumn('origin', Types::STRING, ['notnull' => true, 'length' => 16]);
			$reports->addColumn('requester', Types::STRING, ['notnull' => false, 'length' => 64]);
			$reports->addColumn('findings', Types::TEXT, ['notnull' => false]);
			$reports->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$reports->setPrimaryKey(['id']);
			$reports->addUniqueIndex(['requester', 'file_id', 'origin'], 'ia_reports_manual');
			$reports->addIndex(['file_id'], 'ia_reports_file');
		}

		if (!$schema->hasTable('ia_bulletins')) {
			$bulletins = $schema->createTable('ia_bulletins');
			$bulletins->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$bulletins->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 200]);
			$bulletins->addColumn('editor_group', Types::STRING, ['notnull' => true, 'length' => 64]);
			$bulletins->addColumn('sources', Types::TEXT, ['notnull' => true]);
			$bulletins->addColumn('readers', Types::TEXT, ['notnull' => true]);
			$bulletins->addColumn('schedule', Types::STRING, ['notnull' => true, 'length' => 32]);
			$bulletins->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$bulletins->setPrimaryKey(['id']);
			$bulletins->addIndex(['editor_group'], 'ia_bulletins_editors');
		}

		return $schema;
	}
}
