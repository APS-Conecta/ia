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
 * The Runs, the Reviews' reports, the Automatic actions, the Bulletins, their Editions and the
 * Readings (gestion-aio#162, Schema). Later tickets add the other
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
			$runs->addColumn('rule', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
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
			$reports->addColumn('rule', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$reports->addColumn('instructions', Types::TEXT, ['notnull' => false]);
			$reports->addColumn('automatic_file', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$reports->addColumn('findings', Types::TEXT, ['notnull' => false]);
			$reports->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$reports->setPrimaryKey(['id']);
			$reports->addUniqueIndex(['requester', 'file_id', 'origin'], 'ia_reports_manual');
			$reports->addIndex(['file_id'], 'ia_reports_file');
			$reports->addUniqueIndex(['automatic_file'], 'ia_reports_automatic');
		}

		if (!$schema->hasTable('ia_actions')) {
			$actions = $schema->createTable('ia_actions');
			$actions->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$actions->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 200]);
			$actions->addColumn('folder', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$actions->addColumn('editor_group', Types::STRING, ['notnull' => true, 'length' => 64]);
			$actions->addColumn('instructions', Types::TEXT, ['notnull' => false]);
			$actions->setPrimaryKey(['id']);
			$actions->addIndex(['editor_group'], 'ia_actions_editors');
		}

		if (!$schema->hasTable('ia_bulletins')) {
			$bulletins = $schema->createTable('ia_bulletins');
			$bulletins->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$bulletins->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 200]);
			$bulletins->addColumn('editor_group', Types::STRING, ['notnull' => true, 'length' => 64]);
			$bulletins->addColumn('sources', Types::TEXT, ['notnull' => true]);
			$bulletins->addColumn('readers', Types::TEXT, ['notnull' => true]);
			$bulletins->addColumn('schedule', Types::STRING, ['notnull' => true, 'length' => 32]);
			$bulletins->setPrimaryKey(['id']);
			$bulletins->addIndex(['editor_group'], 'ia_bulletins_editors');
		}

		if (!$schema->hasTable('ia_readings')) {
			$readings = $schema->createTable('ia_readings');
			$readings->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$readings->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$readings->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 64]);
			$readings->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 8]);
			$readings->addColumn('summary', Types::TEXT, ['notnull' => false]);
			$readings->addColumn('expires', Types::STRING, ['notnull' => false, 'length' => 10]);
			$readings->addColumn('cargos', Types::TEXT, ['notnull' => false]);
			$readings->addColumn('read_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$readings->setPrimaryKey(['id']);
			$readings->addUniqueIndex(['file_id'], 'ia_readings_file');
		}

		if (!$schema->hasTable('ia_editions')) {
			$editions = $schema->createTable('ia_editions');
			$editions->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$editions->addColumn('bulletin_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$editions->addColumn('date', Types::STRING, ['notnull' => true, 'length' => 10]);
			$editions->addColumn('until', Types::STRING, ['notnull' => true, 'length' => 10]);
			$editions->addColumn('late', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$editions->addColumn('fingerprint', Types::TEXT, ['notnull' => false]);
			$editions->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$editions->addColumn('resumen', Types::TEXT, ['notnull' => false]);
			$editions->addColumn('files', Types::TEXT, ['notnull' => false]);
			$editions->addColumn('published_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$editions->setPrimaryKey(['id']);
			$editions->addUniqueIndex(['bulletin_id', 'date'], 'ia_editions_date');
		}

		return $schema;
	}
}
