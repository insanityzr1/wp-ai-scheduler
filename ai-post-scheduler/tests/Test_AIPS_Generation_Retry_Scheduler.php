<?php
/**
 * Tests for AIPS_Generation_Retry_Scheduler — backoff + outcome policy.
 *
 * Uses in-memory fakes for the state repository and job scheduler so no DB or
 * cron is required.
 *
 * @package AI_Post_Scheduler
 */

/**
 * In-memory generation-state fake.
 */
class AIPS_Fake_Generation_State {
	public $rows = array();
	public $success_calls = 0;

	private function key($flow, $author_id) { return $flow . ':' . $author_id; }

	public function get($flow, $author_id) {
		$k = $this->key($flow, $author_id);
		return isset($this->rows[$k]) ? (object) $this->rows[$k] : null;
	}
	public function record_attempt($flow, $author_id, $corr = '', $run = '') {}
	public function record_success($flow, $author_id, $outcome) {
		$this->success_calls++;
		$this->rows[$this->key($flow, $author_id)] = array('retry_attempts' => 0, 'consecutive_failures' => 0, 'next_retry_at' => 0);
	}
	public function record_failure($flow, $author_id, $outcome, $code = '', $msg = '') {
		$k = $this->key($flow, $author_id);
		$prev = isset($this->rows[$k]['consecutive_failures']) ? (int) $this->rows[$k]['consecutive_failures'] : 0;
		if (!isset($this->rows[$k])) { $this->rows[$k] = array('retry_attempts' => 0); }
		$this->rows[$k]['consecutive_failures'] = $prev + 1;
		return $prev + 1;
	}
	public function set_next_retry($flow, $author_id, $when, $attempt) {
		$k = $this->key($flow, $author_id);
		$this->rows[$k]['next_retry_at']  = $when;
		$this->rows[$k]['retry_attempts'] = $attempt;
	}
	public function set_next_claim_recheck($flow, $author_id, $when, $attempt) {
		$k = $this->key($flow, $author_id);
		$this->rows[$k]['next_retry_at'] = $when;
		$this->rows[$k]['claim_recheck_attempts'] = $attempt;
	}
	public function clear_retry($flow, $author_id) {
		$k = $this->key($flow, $author_id);
		if (isset($this->rows[$k])) { $this->rows[$k]['next_retry_at'] = 0; }
	}
}

/**
 * Job-scheduler fake capturing schedule_simple() calls.
 */
class AIPS_Fake_Job_Scheduler {
	public $calls = array();
	public $return = true;
	public function schedule_simple($hook, $fire_at, $args = array(), $options = array()): bool {
		$this->calls[] = array('hook' => $hook, 'fire_at' => $fire_at, 'args' => $args, 'options' => $options);
		return $this->return;
	}
}

class Test_AIPS_Generation_Retry_Scheduler extends WP_UnitTestCase {

	private function make($state = null, $jobs = null) {
		$state = $state ?: new AIPS_Fake_Generation_State();
		$jobs  = $jobs ?: new AIPS_Fake_Job_Scheduler();
		$logger = new class { public function log($m, $l = 'info', $c = array()) {} };
		$notifs = new class {}; // no notification methods → guarded no-ops.
		$scheduler = new AIPS_Generation_Retry_Scheduler($state, $jobs, $logger, $notifs);
		return array($scheduler, $state, $jobs);
	}

	private function author() {
		return (object) array('id' => 77, 'name' => 'Retry Author');
	}

	private function flow() {
		return AIPS_Generation_State_Repository::FLOW_AUTHOR_TOPIC;
	}

	public function test_compute_delay_follows_backoff_schedule() {
		list($scheduler) = $this->make();

		// Jitter disabled for deterministic assertions.
		add_filter('aips_generation_retry_jitter', '__return_zero');

		$this->assertSame(300, $scheduler->compute_delay($this->flow(), 1));
		$this->assertSame(900, $scheduler->compute_delay($this->flow(), 2));
		$this->assertSame(3600, $scheduler->compute_delay($this->flow(), 3));
		// Beyond the schedule length, the last delay is reused.
		$this->assertSame(3600, $scheduler->compute_delay($this->flow(), 9));

		remove_filter('aips_generation_retry_jitter', '__return_zero');
	}

	public function test_compute_delay_honours_larger_provider_delay() {
		list($scheduler) = $this->make();
		add_filter('aips_generation_retry_jitter', '__return_zero');

		$this->assertSame(5000, $scheduler->compute_delay($this->flow(), 1, 5000));
		// Smaller provider delay does not shrink the backoff.
		$this->assertSame(300, $scheduler->compute_delay($this->flow(), 1, 100));

		remove_filter('aips_generation_retry_jitter', '__return_zero');
	}

	public function test_success_records_and_does_not_retry() {
		list($scheduler, $state, $jobs) = $this->make();
		$outcome = new AIPS_Generation_Outcome(AIPS_Generation_Outcome::FULL_SUCCESS);

		$decision = $scheduler->handle_outcome($this->flow(), $this->author(), $outcome);

		$this->assertTrue($decision['advance']);
		$this->assertFalse($decision['retry_scheduled']);
		$this->assertSame(1, $state->success_calls);
		$this->assertEmpty($jobs->calls);
	}

	public function test_transient_failure_schedules_retry_and_blocks_advance() {
		list($scheduler, $state, $jobs) = $this->make();
		$outcome = new AIPS_Generation_Outcome(AIPS_Generation_Outcome::TRANSIENT_FAILURE, 'server_error', 'blip');

		$decision = $scheduler->handle_outcome($this->flow(), $this->author(), $outcome);

		$this->assertFalse($decision['advance']);
		$this->assertTrue($decision['retry_scheduled']);
		$this->assertCount(1, $jobs->calls);
		$this->assertSame(AIPS_Generation_Retry_Scheduler::HOOK_TOPIC, $jobs->calls[0]['hook']);
		$this->assertSame(77, $jobs->calls[0]['args'][0]);
		$this->assertSame(1, $jobs->calls[0]['args'][2], 'First retry attempt is #1.');
	}

	public function test_retry_budget_is_bounded_then_exhausts() {
		add_filter('aips_generation_retry_max_attempts', function () { return 2; });

		list($scheduler, $state, $jobs) = $this->make();
		$author  = $this->author();
		$outcome = new AIPS_Generation_Outcome(AIPS_Generation_Outcome::TRANSIENT_FAILURE, 'server_error', 'blip');

		$d1 = $scheduler->handle_outcome($this->flow(), $author, $outcome); // attempt 1
		$d2 = $scheduler->handle_outcome($this->flow(), $author, $outcome); // attempt 2
		$d3 = $scheduler->handle_outcome($this->flow(), $author, $outcome); // exceeds budget

		$this->assertTrue($d1['retry_scheduled']);
		$this->assertTrue($d2['retry_scheduled']);
		$this->assertFalse($d3['retry_scheduled']);
		$this->assertTrue($d3['exhausted']);
		$this->assertCount(2, $jobs->calls, 'Only two retries scheduled within the budget.');

		remove_all_filters('aips_generation_retry_max_attempts');
	}

	public function test_permanent_error_does_not_retry() {
		list($scheduler, $state, $jobs) = $this->make();
		$outcome = new AIPS_Generation_Outcome(AIPS_Generation_Outcome::PERMANENT_ERROR, 'invalid_api_key', 'bad key');

		$decision = $scheduler->handle_outcome($this->flow(), $this->author(), $outcome);

		$this->assertFalse($decision['advance']);
		$this->assertFalse($decision['retry_scheduled']);
		$this->assertEmpty($jobs->calls);
	}

	public function test_no_approved_topics_advances_without_retry() {
		list($scheduler, $state, $jobs) = $this->make();
		$outcome = new AIPS_Generation_Outcome(AIPS_Generation_Outcome::NO_APPROVED_TOPICS);

		$decision = $scheduler->handle_outcome(AIPS_Generation_State_Repository::FLOW_AUTHOR_POST, $this->author(), $outcome);

		$this->assertTrue($decision['advance']);
		$this->assertFalse($decision['retry_scheduled']);
		$this->assertEmpty($jobs->calls);
	}

	public function test_already_running_schedules_short_recheck_without_recording_failure() {
		add_filter('aips_generation_already_running_recheck_delay', function () { return 45; });

		list($scheduler, $state, $jobs) = $this->make();
		$outcome = new AIPS_Generation_Outcome(AIPS_Generation_Outcome::ALREADY_RUNNING, 'already_running', 'busy');

		$decision = $scheduler->handle_outcome($this->flow(), $this->author(), $outcome, 'corr-1');

		$this->assertFalse($decision['advance']);
		$this->assertTrue($decision['retry_scheduled']);
		$this->assertCount(1, $jobs->calls);
		$this->assertEqualsWithDelta(45, $jobs->calls[0]['fire_at'] - time(), 2.0);
		$this->assertSame(0, $state->rows[$this->flow() . ':77']['consecutive_failures'] ?? 0);

		remove_all_filters('aips_generation_already_running_recheck_delay');
	}
}
