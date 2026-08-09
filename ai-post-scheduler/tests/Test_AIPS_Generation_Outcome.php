<?php
/**
 * Unit tests for AIPS_Generation_Outcome (outcome classification + policy).
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Generation_Outcome extends WP_UnitTestCase {

	private function post_result($status_calls) {
		$result = new AIPS_Author_Post_Generation_Result(1, 2);
		$status_calls($result);
		return $result->finalize();
	}

	public function test_full_success_advances_no_retry() {
		$result = $this->post_result(function ($r) {
			$r->add_success(10);
			$r->add_success(11);
		});
		$outcome = AIPS_Generation_Outcome::from_post_result($result);

		$this->assertSame(AIPS_Generation_Outcome::FULL_SUCCESS, $outcome->get_outcome());
		$this->assertTrue($outcome->advances_schedule());
		$this->assertFalse($outcome->should_retry());
	}

	public function test_partial_success_advances_no_retry() {
		$result = $this->post_result(function ($r) {
			$r->add_success(10);
			$r->add_failure(2, 'T2', 'generation_failed', 'boom');
		});
		$outcome = AIPS_Generation_Outcome::from_post_result($result);

		$this->assertSame(AIPS_Generation_Outcome::PARTIAL_SUCCESS, $outcome->get_outcome());
		$this->assertTrue($outcome->advances_schedule());
		$this->assertFalse($outcome->should_retry());
	}

	public function test_no_work_advances() {
		$result = new AIPS_Author_Post_Generation_Result(1, 1);
		$result->mark_no_work();
		$outcome = AIPS_Generation_Outcome::from_post_result($result);

		$this->assertSame(AIPS_Generation_Outcome::NO_APPROVED_TOPICS, $outcome->get_outcome());
		$this->assertTrue($outcome->advances_schedule());
		$this->assertFalse($outcome->should_retry());
	}

	public function test_already_running_no_advance_no_retry() {
		$result = new AIPS_Author_Post_Generation_Result(1, 1);
		$result->mark_already_running();
		$outcome = AIPS_Generation_Outcome::from_post_result($result);

		$this->assertSame(AIPS_Generation_Outcome::ALREADY_RUNNING, $outcome->get_outcome());
		$this->assertFalse($outcome->advances_schedule());
		$this->assertFalse($outcome->should_retry());
	}

	public function test_transient_failure_retries_no_advance() {
		$result = $this->post_result(function ($r) {
			$r->add_failure(1, 'T1', 'server_error', 'temporary blip');
		});
		$outcome = AIPS_Generation_Outcome::from_post_result($result);

		$this->assertSame(AIPS_Generation_Outcome::TRANSIENT_FAILURE, $outcome->get_outcome());
		$this->assertFalse($outcome->advances_schedule());
		$this->assertTrue($outcome->should_retry());
	}

	public function test_permanent_failure_no_retry() {
		$result = $this->post_result(function ($r) {
			$r->add_failure(1, 'T1', 'invalid_api_key', 'bad key');
		});
		$outcome = AIPS_Generation_Outcome::from_post_result($result);

		$this->assertSame(AIPS_Generation_Outcome::PERMANENT_ERROR, $outcome->get_outcome());
		$this->assertFalse($outcome->advances_schedule());
		$this->assertFalse($outcome->should_retry());
		$this->assertTrue($outcome->is_permanent());
	}

	public function test_db_failure_retries() {
		$result = $this->post_result(function ($r) {
			$r->add_failure(1, 'T1', 'db_insert_error', 'db down');
		});
		$outcome = AIPS_Generation_Outcome::from_post_result($result);

		$this->assertSame(AIPS_Generation_Outcome::DB_FAILURE, $outcome->get_outcome());
		$this->assertTrue($outcome->should_retry());
		$this->assertFalse($outcome->advances_schedule());
	}

	public function test_mixed_failures_prefer_transient_over_permanent() {
		$result = $this->post_result(function ($r) {
			$r->add_failure(1, 'T1', 'invalid_api_key', 'perm');
			$r->add_failure(2, 'T2', 'server_error', 'transient');
		});
		$outcome = AIPS_Generation_Outcome::from_post_result($result);

		// A transient failure among the batch means a retry can still help.
		$this->assertSame(AIPS_Generation_Outcome::TRANSIENT_FAILURE, $outcome->get_outcome());
		$this->assertTrue($outcome->should_retry());
	}

	public function test_topic_result_success() {
		$result = new AIPS_Author_Topic_Generation_Result(1, 2, 'run');
		$result->set_persisted_topics(array(array('id' => 1), array('id' => 2)));
		$result->finalize();

		$outcome = AIPS_Generation_Outcome::from_topic_result($result);
		$this->assertSame(AIPS_Generation_Outcome::FULL_SUCCESS, $outcome->get_outcome());
		$this->assertTrue($outcome->advances_schedule());
	}

	public function test_topic_result_parsing_shortfall_retries() {
		$result = new AIPS_Author_Topic_Generation_Result(1, 3, 'run');
		$result->mark_failed(new WP_Error('no_topics_parsed', 'nothing usable'));
		$result->finalize();

		$outcome = AIPS_Generation_Outcome::from_topic_result($result);
		$this->assertSame(AIPS_Generation_Outcome::PARSING_SHORTFALL, $outcome->get_outcome());
		$this->assertTrue($outcome->should_retry());
		$this->assertFalse($outcome->advances_schedule());
	}

	public function test_wp_error_rate_limit_carries_retry_after() {
		$error   = new WP_Error('rate_limit_exceeded', 'slow down', array('retry_after' => 42));
		$outcome = AIPS_Generation_Outcome::from_wp_error($error);

		$this->assertSame(AIPS_Generation_Outcome::RATE_LIMIT, $outcome->get_outcome());
		$this->assertTrue($outcome->should_retry());
		$this->assertSame(42, $outcome->get_retry_after());
	}

	public function test_wp_error_already_running() {
		$outcome = AIPS_Generation_Outcome::from_wp_error(new WP_Error('already_running', 'busy'));
		$this->assertSame(AIPS_Generation_Outcome::ALREADY_RUNNING, $outcome->get_outcome());
		$this->assertFalse($outcome->should_retry());
		$this->assertFalse($outcome->advances_schedule());
	}

	public function test_wp_error_permanent_insufficient_quota() {
		$outcome = AIPS_Generation_Outcome::from_wp_error(new WP_Error('insufficient_quota', 'no quota'));
		$this->assertSame(AIPS_Generation_Outcome::PERMANENT_ERROR, $outcome->get_outcome());
		$this->assertTrue($outcome->is_permanent());
	}
}
