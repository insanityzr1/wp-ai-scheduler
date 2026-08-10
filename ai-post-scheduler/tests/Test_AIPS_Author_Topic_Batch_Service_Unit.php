<?php
/**
 * Database-free tests for author topic batch orchestration.
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Author_Topic_Batch_Service_Unit extends WP_UnitTestCase {

	private function dependencies($existing = null) {
		$authors = new class {
			public function get_by_id($id) {
				return in_array((int) $id, array(2, 3), true) ? (object) array('id' => (int) $id) : null;
			}
		};
		$jobs = new class($existing) {
			public $existing;
			public $created = array();
			public $statuses = array();
			public $status_value = 'processing';
			public $processed_value = 1;
			public function __construct($existing) { $this->existing = $existing; }
			public function find_active_by_request_key($type, $key) { return $this->existing; }
			public function create($type, $items, $options = array()) {
				$this->created[] = array($type, $items, $options);
				return 'batch-123';
			}
			public function get($id) {
				return (object) array('job_id' => $id, 'job_type' => 'author_topic_generation', 'status' => $this->status_value, 'total' => 2, 'processed' => $this->processed_value);
			}
			public function update_status($id, $status, $processed = null) { $this->statuses[] = array($id, $status); return true; }
		};
		$items = new class {
			public $created = array();
			public $canceled = array();
			public function create_batch_items($batch_id, $author_ids) { $this->created[] = array($batch_id, $author_ids); return true; }
			public function get_by_batch($batch_id) {
				return array(
					(object) array('author_id' => 2, 'status' => 'completed', 'error_message' => ''),
					(object) array('author_id' => 3, 'status' => 'running', 'error_message' => ''),
				);
			}
			public function cancel_pending($batch_id) { $this->canceled[] = $batch_id; return true; }
		};
		$queue = new class {
			public $calls = array();
			public function dispatch_generic($hook, $count, $when, $prefix, $correlation) {
				$this->calls[] = array($hook, $count, $prefix, $correlation);
				return array('scheduled_batches' => $count);
			}
		};

		return array($authors, $jobs, $items, $queue);
	}

	public function test_enqueue_deduplicates_ids_and_reports_missing_authors() {
		list($authors, $jobs, $items, $queue) = $this->dependencies();
		$service = new AIPS_Author_Topic_Batch_Service($authors, $jobs, $items, $queue);

		$result = $service->enqueue(array(3, 2, 3, 999, 0), 'request-1');

		$this->assertSame('batch-123', $result['batch_id']);
		$this->assertSame(array(2, 3), $result['accepted_author_ids']);
		$this->assertSame(array(999), $result['invalid_author_ids']);
		$this->assertSame(array(2, 3), $jobs->created[0][1]);
		$this->assertSame(array('batch-123', array(2, 3)), $items->created[0]);
		$this->assertCount(1, $queue->calls);
	}

	public function test_enqueue_returns_existing_active_batch_for_same_request_key() {
		$existing = (object) array('job_id' => 'existing-1', 'status' => 'processing', 'total' => 2, 'processed' => 0);
		list($authors, $jobs, $items, $queue) = $this->dependencies($existing);
		$service = new AIPS_Author_Topic_Batch_Service($authors, $jobs, $items, $queue);

		$result = $service->enqueue(array(2, 3), 'same-request');

		$this->assertSame('existing-1', $result['batch_id']);
		$this->assertTrue($result['existing']);
		$this->assertEmpty($jobs->created);
		$this->assertEmpty($queue->calls);
	}

	public function test_status_and_cancel_expose_per_author_state() {
		list($authors, $jobs, $items, $queue) = $this->dependencies();
		$service = new AIPS_Author_Topic_Batch_Service($authors, $jobs, $items, $queue);

		$status = $service->get_status('batch-123');
		$this->assertSame(50, $status['percent']);
		$this->assertCount(2, $status['authors']);

		$this->assertTrue($service->cancel('batch-123'));
		$this->assertSame(array('batch-123', 'canceled'), $jobs->statuses[0]);
		$this->assertSame(array('batch-123'), $items->canceled);
	}

	public function test_terminal_status_includes_fresh_counts_for_each_author(): void {
		list($authors, $jobs, $items, $queue) = $this->dependencies();
		$jobs->status_value = 'completed';
		$jobs->processed_value = 2;
		$status_repository = new class {
			public function get_for_authors($ids) {
				return array(
					2 => array('counts' => array('pending' => 3)),
					3 => array('counts' => array('pending' => 1)),
				);
			}
		};
		$service = new AIPS_Author_Topic_Batch_Service($authors, $jobs, $items, $queue, $status_repository);

		$status = $service->get_status('batch-123');

		$this->assertSame(3, $status['author_counts'][2]['pending']);
		$this->assertSame(1, $status['author_counts'][3]['pending']);
	}
}
