<?php
/**
 * Database-free notification semantic tests for author topic generation.
 *
 * @package AI_Post_Scheduler
 */

if (!function_exists('admin_url')) {
	function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
}
if (!function_exists('add_query_arg')) {
	function add_query_arg($args, $url) { return $url . (false === strpos($url, '?') ? '?' : '&') . http_build_query($args); }
}

class Test_AIPS_Author_Topic_Notification_Unit extends WP_UnitTestCase {

	public function test_partial_topic_sender_is_actionable_and_deduplicated(): void {
		$sent = array();
		$sender = new AIPS_Notification_Senders(
			function($type, $options) use (&$sent) { $sent = array($type, $options); },
			function() { return array(); }
		);

		$sender->author_topics_partially_generated('Jane', 2, 5, 42, 3);

		$this->assertSame('partial_generation_completed', $sent[0]);
		$this->assertStringContainsString('2 of 5', $sent[1]['message']);
		$this->assertStringContainsString('author_id=42', $sent[1]['url']);
		$this->assertSame('author_topics_partial_42', $sent[1]['dedupe_key']);
	}

	public function test_already_running_retry_uses_claim_contention_notification(): void {
		$state = new class {
			public $row = null;
			public function get($flow, $author_id) { return $this->row; }
			public function record_failure($flow, $author_id, $outcome, $code = '', $message = '') { return 1; }
			public function set_next_retry($flow, $author_id, $when, $attempt) { $this->row = (object) array('retry_attempts' => $attempt); }
			public function set_next_claim_recheck($flow, $author_id, $when, $attempt) { $this->row = (object) array('claim_recheck_attempts' => $attempt); }
			public function clear_retry($flow, $author_id) {}
		};
		$jobs = new class {
			public function schedule_simple($hook, $fire_at, $args = array(), $options = array()) { return true; }
		};
		$logger = new class { public function log($message, $level = 'info', $context = array()) {} };
		$notifications = new class {
			public $claim_calls = array();
			public $retry_calls = array();
			public function generation_already_running(...$args) { $this->claim_calls[] = $args; }
			public function generation_retry_scheduled(...$args) { $this->retry_calls[] = $args; }
		};
		$scheduler = new AIPS_Generation_Retry_Scheduler($state, $jobs, $logger, $notifications);
		$outcome = new AIPS_Generation_Outcome(AIPS_Generation_Outcome::ALREADY_RUNNING);

		$scheduler->handle_outcome(
			AIPS_Generation_State_Repository::FLOW_AUTHOR_TOPIC,
			(object) array('id' => 42, 'name' => 'Jane'),
			$outcome
		);

		$this->assertCount(1, $notifications->claim_calls);
		$this->assertEmpty($notifications->retry_calls);
	}
}
