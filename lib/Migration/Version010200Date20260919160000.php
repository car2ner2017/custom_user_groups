<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010200Date20260919160000 extends SimpleMigrationStep {

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

		if ($schema->hasTable('custom_user_groups')) {
			$groupTable = $schema->getTable('custom_user_groups');
			if (!$groupTable->hasColumn('owner_id')) {
				$groupTable->addColumn('owner_id', Types::STRING, [
					'notnull' => false,
					'length' => 64,
					'default' => null,
				]);
				$groupTable->addIndex(['owner_id'], 'cug_owner_id_idx');
			}
		}

		if (!$schema->hasTable('custom_user_group_activity')) {
			$activityTable = $schema->createTable('custom_user_group_activity');
			$activityTable->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$activityTable->addColumn('group_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$activityTable->addColumn('action_type', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$activityTable->addColumn('actor_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$activityTable->addColumn('target_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$activityTable->addColumn('details', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
			$activityTable->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$activityTable->setPrimaryKey(['id']);
			$activityTable->addIndex(['group_id', 'created_at'], 'cug_act_grp_date_idx');
			$activityTable->addIndex(['actor_id'], 'cug_act_act_idx');
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->db !== null) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->update('custom_user_groups')
					->set('owner_id', $qb->getColumnName('creator_id'))
					->where(
						$qb->expr()->orX(
							$qb->expr()->isNull('owner_id'),
							$qb->expr()->eq('owner_id', $qb->createNamedParameter(''))
						)
					);
				$qb->executeStatement();
			} catch (\Throwable $e) {
				// Suppress error if table or column doesn't match
			}
		}
	}
}

