<?php
/**
 * Actionable manual generation result contracts.
 *
 * Removing failure details, retry IDs, links, refill data, or counters must fail.
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Author_Generation_Result_Presenter_Unit extends WP_UnitTestCase {
	public function test_partial_post_result_contains_links_failure_details_retry_ids_and_counters() {
		$result = new AIPS_Author_Post_Generation_Result(7, 3, 'corr-1');
		$result->set_attempted_topic_ids(array(11, 12, 13));
		$result->add_success(101);
		$result->add_failure(12, 'Failed topic title', 'provider_timeout', 'Provider timed out.');
		$result->add_skipped(13, 'Busy topic title', 'already_running');
		$result->finalize();

		$payload = (new AIPS_Author_Generation_Result_Presenter())->present_post(
			$result,
			array('schedule_reset' => false),
			array('generated_posts' => 8, 'approved' => 2)
		);

		$this->assertSame('partial', $payload['status']);
		$this->assertSame('Generated 1 of 3 requested posts.', $payload['message']);
		$this->assertSame(array(12, 13), $payload['retry_topic_ids']);
		$this->assertSame('Failed topic title', $payload['failures'][0]['topic_title']);
		$this->assertSame('Provider timed out.', $payload['failures'][0]['error_message']);
		$this->assertSame('/edit.php?post=101', $payload['post_links'][0]['edit_url']);
		$this->assertSame(8, $payload['author_counts']['generated_posts']);
	}

	public function test_topic_result_contains_review_link_refill_metrics_and_counters() {
		$result = new AIPS_Author_Topic_Generation_Result(7, 3, 'run-1', 'corr-1');
		$result->set_candidate_counts(2, 1, 1);
		$result->set_quality_metrics(
			array('returned' => 4, 'accepted' => 2, 'invalid' => 1, 'exact_duplicates' => 1, 'fuzzy_duplicates' => 0),
			array(array('title' => 'Duplicate title', 'reason' => 'duplicate_existing')),
			2
		);
		$result->set_persisted_topics(array(array('id' => 31), array('id' => 32)));
		$result->finalize();

		$payload = (new AIPS_Author_Generation_Result_Presenter())->present_topic(
			$result,
			array('schedule_reset' => true),
			'/review-topics',
			array('pending' => 6, 'approved' => 1)
		);

		$this->assertSame('partial', $payload['status']);
		$this->assertSame('Generated 2 of 3 requested topics.', $payload['message']);
		$this->assertSame('/review-topics', $payload['review_url']);
		$this->assertSame(2, $payload['refill_attempts']);
		$this->assertSame(1, $payload['quality']['invalid']);
		$this->assertSame(6, $payload['author_counts']['pending']);
	}

	public function test_topic_failure_message_preserves_the_actionable_reason() {
		$result = new AIPS_Author_Topic_Generation_Result(7, 3, 'run-1', 'corr-1');
		$result->mark_already_running();

		$payload = (new AIPS_Author_Generation_Result_Presenter())->present_topic($result);

		$this->assertSame('already_running', $payload['status']);
		$this->assertSame($payload['error'], $payload['message']);
		$this->assertStringContainsString('already in progress', $payload['message']);
	}

	/**
	 * @dataProvider non_success_post_status_provider
	 */
	public function test_non_success_post_statuses_remain_explicit($configure, $expected_status) {
		$result = new AIPS_Author_Post_Generation_Result(7, 1, 'corr-1');
		$configure($result);
		$payload = (new AIPS_Author_Generation_Result_Presenter())->present_post($result);

		$this->assertSame($expected_status, $payload['status']);
		$this->assertFalse($payload['is_success']);
	}

	public function non_success_post_status_provider() {
		return array(
			'failed' => array(function($result) { $result->add_failure(11, 'Topic', 'failed', 'Failure'); $result->finalize(); }, 'failed'),
			'no work' => array(function($result) { $result->mark_no_work(); }, 'no_work'),
			'already running' => array(function($result) { $result->mark_already_running(); }, 'already_running'),
		);
	}
}
