<?php
/**
 * Database-free author generation status tests.
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Author_Generation_Status_Unit extends WP_UnitTestCase {

	public function test_aggregate_exposes_last_result_counts_and_error_details(): void {
		$wpdb = new class {
			public $prefix = 'wp_';
			public $posts = 'wp_posts';
			private $query = 0;

			public function prepare($query, ...$args) { return $query; }

			public function get_results($query) {
				$this->query++;
				if (1 === $this->query) {
					return array((object) array(
						'flow_type'           => 'author_post',
						'author_id'           => 42,
						'last_attempt_at'      => 100,
						'last_success_at'      => 90,
						'last_outcome'         => 'partial',
						'last_requested_count' => 4,
						'last_generated_count' => 2,
						'last_error_code'      => 'provider_timeout',
						'last_error_message'   => 'Two topics timed out.',
						'next_retry_at'        => 120,
					));
				}
				return array();
			}
		};

		$repository = new AIPS_Author_Generation_Status_Repository($wpdb);
		$status = $repository->get_for_authors(array(42));

		$this->assertSame(4, $status[42]['post']['last_requested_count']);
		$this->assertSame(2, $status[42]['post']['last_generated_count']);
		$this->assertSame('provider_timeout', $status[42]['post']['last_error_code']);
		$this->assertSame('Two topics timed out.', $status[42]['post']['last_error_message']);
	}
}
