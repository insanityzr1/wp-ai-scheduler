<?php
/**
 * Static schema contracts for the 3.4.0 author-topic batch migration.
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Author_Topic_Batch_Schema_Unit extends WP_UnitTestCase {
	public function test_schema_contains_bounded_unique_request_key_and_item_table() {
		$schema = file_get_contents(dirname(__DIR__) . '/includes/class-aips-db-manager.php');

		$this->assertStringContainsString('request_key varchar(100) DEFAULT NULL', $schema);
		$this->assertStringContainsString('UNIQUE KEY job_request (job_type(64), request_key(100))', $schema);
		$this->assertStringContainsString('CREATE TABLE $table_author_topic_batch_items', $schema);
		$this->assertStringContainsString('UNIQUE KEY batch_author (batch_id, author_id)', $schema);
		$this->assertStringContainsString('claim_token varchar(64) DEFAULT NULL', $schema);
		$this->assertStringContainsString('KEY batch_status (batch_id, status, updated_at)', $schema);
		$this->assertStringContainsString('last_requested_count int unsigned NOT NULL DEFAULT 0', $schema);
		$this->assertStringContainsString('last_generated_count int unsigned NOT NULL DEFAULT 0', $schema);
	}

	public function test_cleanup_includes_child_rows_and_canceled_batches() {
		$store = file_get_contents(dirname(__DIR__) . '/includes/class-aips-bulk-batch-job-store.php');
		$items = file_get_contents(dirname(__DIR__) . '/includes/class-aips-author-topic-batch-items-repository.php');

		$this->assertStringContainsString('delete_for_batches', $store);
		$this->assertStringContainsString('DELETE FROM {$this->table_name}', $items);
		$this->assertStringContainsString("'completed','failed','canceled'", $store);
	}
}
