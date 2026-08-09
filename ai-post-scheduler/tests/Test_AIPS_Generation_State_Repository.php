<?php
/**
 * Tests for AIPS_Generation_State_Repository.
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Generation_State_Repository extends WP_UnitTestCase {

	/** @var AIPS_Generation_State_Repository */
	private $repo;

	/** @var string */
	private $table;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->table = $wpdb->prefix . 'aips_generation_state';
		$this->repo  = new AIPS_Generation_State_Repository();
		if (!$this->should_skip()) {
			$wpdb->query("DELETE FROM {$this->table}");
		}
	}

	public function tearDown(): void {
		global $wpdb;
		if (!$this->should_skip()) {
			$wpdb->query("DELETE FROM {$this->table}");
		}
		parent::tearDown();
	}

	private function should_skip() {
		global $wpdb;
		if (property_exists($wpdb, 'get_results_return_val')) {
			return true;
		}
		return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table)) !== $this->table;
	}

	private function flow() {
		return AIPS_Generation_State_Repository::FLOW_AUTHOR_TOPIC;
	}

	public function test_record_attempt_creates_row() {
		if ($this->should_skip()) { $this->markTestSkipped('State table not available.'); }

		$this->repo->record_attempt($this->flow(), 5, 'corr-1', 'run-1');
		$row = $this->repo->get($this->flow(), 5);

		$this->assertNotNull($row);
		$this->assertSame('corr-1', $row->correlation_id);
		$this->assertSame('run-1', $row->run_id);
		$this->assertGreaterThan(0, (int) $row->last_attempt_at);
	}

	public function test_record_failure_increments_consecutive() {
		if ($this->should_skip()) { $this->markTestSkipped('State table not available.'); }

		$c1 = $this->repo->record_failure($this->flow(), 7, 'transient_failure', 'server_error', 'blip');
		$c2 = $this->repo->record_failure($this->flow(), 7, 'transient_failure', 'server_error', 'blip');

		$this->assertSame(1, $c1);
		$this->assertSame(2, $c2);

		$row = $this->repo->get($this->flow(), 7);
		$this->assertSame(2, (int) $row->consecutive_failures);
		$this->assertSame('server_error', $row->last_error_code);
	}

	public function test_record_success_resets_failure_and_retry_state() {
		if ($this->should_skip()) { $this->markTestSkipped('State table not available.'); }

		$this->repo->record_failure($this->flow(), 9, 'transient_failure', 'server_error', 'blip');
		$this->repo->set_next_retry($this->flow(), 9, AIPS_DateTime::now()->timestamp() + 300, 1);

		$this->repo->record_success($this->flow(), 9, 'full_success');
		$row = $this->repo->get($this->flow(), 9);

		$this->assertSame(0, (int) $row->consecutive_failures);
		$this->assertSame(0, (int) $row->retry_attempts);
		$this->assertSame(0, (int) $row->next_retry_at);
		$this->assertGreaterThan(0, (int) $row->last_success_at);
		$this->assertSame('full_success', $row->last_outcome);
	}

	public function test_get_due_retries_returns_only_due_rows() {
		if ($this->should_skip()) { $this->markTestSkipped('State table not available.'); }

		$now = AIPS_DateTime::now()->timestamp();

		$this->repo->record_failure($this->flow(), 21, 'transient_failure', 'e', 'm');
		$this->repo->set_next_retry($this->flow(), 21, $now - 10, 1); // due

		$this->repo->record_failure($this->flow(), 22, 'transient_failure', 'e', 'm');
		$this->repo->set_next_retry($this->flow(), 22, $now + 600, 1); // not due

		$due = $this->repo->get_due_retries($now);
		$ids = array_map(function ($r) { return (int) $r->author_id; }, $due);

		$this->assertContains(21, $ids);
		$this->assertNotContains(22, $ids);
	}

	public function test_flow_and_author_are_independent_rows() {
		if ($this->should_skip()) { $this->markTestSkipped('State table not available.'); }

		$this->repo->record_failure(AIPS_Generation_State_Repository::FLOW_AUTHOR_TOPIC, 30, 'transient_failure', 'e', 'm');
		$this->repo->record_failure(AIPS_Generation_State_Repository::FLOW_AUTHOR_POST, 30, 'db_failure', 'db_x', 'm');

		$topic = $this->repo->get(AIPS_Generation_State_Repository::FLOW_AUTHOR_TOPIC, 30);
		$post  = $this->repo->get(AIPS_Generation_State_Repository::FLOW_AUTHOR_POST, 30);

		$this->assertSame('transient_failure', $topic->last_outcome);
		$this->assertSame('db_failure', $post->last_outcome);
	}
}
