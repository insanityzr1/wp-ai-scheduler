<?php
/**
 * Worker-interruption recovery tests for author-topic batches.
 *
 * Removing the stale-running predicate or the redispatch call must fail these tests.
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Author_Topic_Batch_Recovery_Unit extends WP_UnitTestCase {
	public function test_repository_atomically_leases_expired_queued_and_running_rows() {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $prepared_query = '';
			public $prepared_args = array();
			public function prepare($query, ...$args) {
				$this->prepared_query = $query;
				$this->prepared_args = $args;
				return 'prepared-recovery-query';
			}
			public function query($query) { return 2; }
		};
		$GLOBALS['wpdb'] = $wpdb;
		$repository = new AIPS_Author_Topic_Batch_Items_Repository();

		$recovered = $repository->recover_stale_items('batch-7', 300);

		$this->assertSame(2, $recovered);
		$this->assertStringContainsString("status = 'queued'", $wpdb->prepared_query);
		$this->assertStringContainsString("status IN ('queued','running')", $wpdb->prepared_query);
		$this->assertStringContainsString('updated_at < %d', $wpdb->prepared_query);
		$this->assertSame('batch-7', $wpdb->prepared_args[3]);
		$this->assertGreaterThan(0, $wpdb->prepared_args[4]);
	}

	public function test_status_redispatches_batch_when_stale_items_are_recovered() {
		$jobs = new class {
			public function reconcile_processed($id, $processed, $status = '') { return true; }
			public function get($id) {
				return (object) array(
					'job_id' => $id,
					'job_type' => 'author_topic_generation',
					'status' => 'processing',
					'total' => 2,
					'processed' => 0,
					'options' => array('correlation_id' => 'batch-correlation'),
				);
			}
		};
		$items = new class {
			public $recovery_calls = array();
			public function recover_stale_items($batch_id, $lease) { $this->recovery_calls[] = array($batch_id, $lease); return 1; }
			public function get_by_batch($batch_id) {
				return array(
					(object) array('author_id' => 2, 'status' => 'queued'),
					(object) array('author_id' => 3, 'status' => 'completed'),
				);
			}
		};
		$queue = new class {
			public $calls = array();
			public function dispatch_generic($hook, $count, $when, $prefix, $correlation) {
				$this->calls[] = array($hook, $count, $prefix, $correlation);
				return array('scheduled_batches' => 1);
			}
		};
		$service = new AIPS_Author_Topic_Batch_Service(new stdClass(), $jobs, $items, $queue);

		$status = $service->get_status('batch-7');

		$this->assertSame(1, $status['recovered']);
		$this->assertCount(1, $items->recovery_calls);
		$this->assertCount(1, $queue->calls);
		$this->assertSame(array('batch-7'), $queue->calls[0][2]);
		$this->assertSame('batch-correlation', $queue->calls[0][3]);
	}
}
