<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010100Date20260919100000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('hzs_user_group_delegations') && !$schema->hasTable('custom_user_group_delegations')) {
			$table = $schema->createTable('hzs_user_group_delegations');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('group_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('level', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id'], 'hzs_del_pk');
			$table->addUniqueIndex(['group_id', 'user_id'], 'hzs_del_grp_usr_idx');
			$table->addIndex(['group_id'], 'hzs_del_group_idx');
			$table->addIndex(['user_id'], 'hzs_del_user_idx');
		}

		if (!$schema->hasTable('hzs_user_group_requests') && !$schema->hasTable('custom_user_group_requests')) {
			$table = $schema->createTable('hzs_user_group_requests');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('group_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('candidate_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('requester_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'pending',
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('processed_by', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$table->setPrimaryKey(['id'], 'hzs_req_pk');
			$table->addIndex(['group_id', 'status'], 'hzs_req_grp_stat_idx');
			$table->addIndex(['candidate_id'], 'hzs_req_cand_idx');
			$table->addIndex(['requester_id'], 'hzs_req_req_idx');
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}

