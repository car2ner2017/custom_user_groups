<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010300Date20260923080000 extends SimpleMigrationStep {

	public function __construct(
		private ?IDBConnection $db = null,
	) {
	}

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

		// 1. Ensure hzs_user_groups exists
		if (!$schema->hasTable('hzs_user_groups')) {
			$table = $schema->createTable('hzs_user_groups');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('group_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('creator_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('member_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('owner_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['group_id'], 'hzs_group_id_idx');
			$table->addIndex(['creator_id'], 'hzs_creator_id_idx');
			$table->addIndex(['member_id'], 'hzs_member_id_idx');
			$table->addIndex(['owner_id'], 'hzs_owner_id_idx');
		}

		// 2. Ensure hzs_user_group_delegations exists
		if (!$schema->hasTable('hzs_user_group_delegations')) {
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
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['group_id', 'user_id'], 'hzs_del_grp_usr_idx');
			$table->addIndex(['group_id'], 'hzs_del_group_idx');
			$table->addIndex(['user_id'], 'hzs_del_user_idx');
		}

		// 3. Ensure hzs_user_group_requests exists
		if (!$schema->hasTable('hzs_user_group_requests')) {
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
			$table->setPrimaryKey(['id']);
			$table->addIndex(['group_id', 'status'], 'hzs_req_grp_stat_idx');
			$table->addIndex(['candidate_id'], 'hzs_req_cand_idx');
			$table->addIndex(['requester_id'], 'hzs_req_req_idx');
		}

		// 4. Ensure hzs_user_group_activity exists
		if (!$schema->hasTable('hzs_user_group_activity')) {
			$table = $schema->createTable('hzs_user_group_activity');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('group_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('action_type', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('actor_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('target_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$table->addColumn('details', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['group_id', 'created_at'], 'hzs_act_grp_date_idx');
			$table->addIndex(['actor_id'], 'hzs_act_act_idx');
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->db === null) {
			return;
		}

		// Helper to test if a table exists
		$tableExists = function(string $tableName): bool {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->select('*')->from($tableName)->setMaxResults(1);
				$res = $qb->executeQuery();
				$res->closeCursor();
				return true;
			} catch (\Throwable) {
				return false;
			}
		};

		// Migrate groups
		if ($tableExists('custom_user_groups') && $tableExists('hzs_user_groups')) {
			try {
				$this->db->executeStatement(
					'INSERT INTO `*PREFIX*hzs_user_groups` (`id`, `group_id`, `name`, `creator_id`, `member_id`, `created_at`, `owner_id`) ' .
					'SELECT `id`, `group_id`, `name`, `creator_id`, `member_id`, `created_at`, COALESCE(`owner_id`, `creator_id`) ' .
					'FROM `*PREFIX*custom_user_groups` ' .
					'WHERE `id` NOT IN (SELECT `id` FROM `*PREFIX*hzs_user_groups`)'
				);
				$this->db->executeStatement('DROP TABLE `*PREFIX*custom_user_groups`');
			} catch (\Throwable $e) {
				$output->warning('Migration warning (hzs_user_groups): ' . $e->getMessage());
			}
		}

		// Migrate delegations
		if ($tableExists('custom_user_group_delegations') && $tableExists('hzs_user_group_delegations')) {
			try {
				$this->db->executeStatement(
					'INSERT INTO `*PREFIX*hzs_user_group_delegations` (`id`, `group_id`, `user_id`, `level`, `created_at`) ' .
					'SELECT `id`, `group_id`, `user_id`, `level`, `created_at` ' .
					'FROM `*PREFIX*custom_user_group_delegations` ' .
					'WHERE `id` NOT IN (SELECT `id` FROM `*PREFIX*hzs_user_group_delegations`)'
				);
				$this->db->executeStatement('DROP TABLE `*PREFIX*custom_user_group_delegations`');
			} catch (\Throwable $e) {
				$output->warning('Migration warning (hzs_user_group_delegations): ' . $e->getMessage());
			}
		}

		// Migrate requests
		if ($tableExists('custom_user_group_requests') && $tableExists('hzs_user_group_requests')) {
			try {
				$this->db->executeStatement(
					'INSERT INTO `*PREFIX*hzs_user_group_requests` (`id`, `group_id`, `candidate_id`, `requester_id`, `status`, `created_at`, `updated_at`, `processed_by`) ' .
					'SELECT `id`, `group_id`, `candidate_id`, `requester_id`, `status`, `created_at`, `updated_at`, `processed_by` ' .
					'FROM `*PREFIX*custom_user_group_requests` ' .
					'WHERE `id` NOT IN (SELECT `id` FROM `*PREFIX*hzs_user_group_requests`)'
				);
				$this->db->executeStatement('DROP TABLE `*PREFIX*custom_user_group_requests`');
			} catch (\Throwable $e) {
				$output->warning('Migration warning (hzs_user_group_requests): ' . $e->getMessage());
			}
		}

		// Migrate activity
		if ($tableExists('custom_user_group_activity') && $tableExists('hzs_user_group_activity')) {
			try {
				$this->db->executeStatement(
					'INSERT INTO `*PREFIX*hzs_user_group_activity` (`id`, `group_id`, `action_type`, `actor_id`, `target_id`, `details`, `created_at`) ' .
					'SELECT `id`, `group_id`, `action_type`, `actor_id`, `target_id`, `details`, `created_at` ' .
					'FROM `*PREFIX*custom_user_group_activity` ' .
					'WHERE `id` NOT IN (SELECT `id` FROM `*PREFIX*hzs_user_group_activity`)'
				);
				$this->db->executeStatement('DROP TABLE `*PREFIX*custom_user_group_activity`');
			} catch (\Throwable $e) {
				$output->warning('Migration warning (hzs_user_group_activity): ' . $e->getMessage());
			}
		}
	}
}

