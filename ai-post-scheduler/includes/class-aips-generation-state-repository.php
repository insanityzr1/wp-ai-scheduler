<?php
/**
 * Generation State Repository
 *
 * Persists per-author, per-flow generation attempt/retry state so scheduling
 * decisions (advance vs. retry) survive across cron runs and are observable
 * (Phase 2, finding 3).
 *
 * One row per (flow_type, author_id). Flows: 'author_topic', 'author_post'.
 *
 * @package AI_Post_Scheduler
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class AIPS_Generation_State_Repository
 */
class AIPS_Generation_State_Repository {

	const FLOW_AUTHOR_TOPIC = 'author_topic';
	const FLOW_AUTHOR_POST  = 'author_post';

	/**
	 * @var string Table name with prefix.
	 */
	private $table_name;

	/**
	 * @var wpdb WordPress database abstraction object.
	 */
	private $wpdb;

	/**
	 * Initialize the repository.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb       = $wpdb;
		$this->table_name = $wpdb->prefix . 'aips_generation_state';
	}

	/**
	 * Get the state row for a flow/author, or null.
	 *
	 * @param string $flow      Flow type.
	 * @param int    $author_id Author ID.
	 * @return object|null
	 */
	public function get(string $flow, int $author_id) {
		if (!$this->table_exists()) {
			return null;
		}

		return $this->wpdb->get_row($this->wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE flow_type = %s AND author_id = %d",
			$flow,
			$author_id
		));
	}

	/**
	 * Record the start of a generation attempt.
	 *
	 * @param string $flow           Flow type.
	 * @param int    $author_id      Author ID.
	 * @param string $correlation_id Correlation ID.
	 * @param string $run_id         Optional run ID.
	 * @return void
	 */
	public function record_attempt(string $flow, int $author_id, string $correlation_id = '', string $run_id = ''): void {
		$this->upsert($flow, $author_id, array(
			'last_attempt_at' => AIPS_DateTime::now()->timestamp(),
			'correlation_id'  => $correlation_id,
			'run_id'          => $run_id,
		));
	}

	/**
	 * Record a successful run: stamp last_success_at and reset failure/retry state.
	 *
	 * @param string $flow      Flow type.
	 * @param int    $author_id Author ID.
	 * @param string $outcome   Outcome constant.
	 * @return void
	 */
	public function record_success(string $flow, int $author_id, string $outcome): void {
		$now = AIPS_DateTime::now()->timestamp();
		$this->upsert($flow, $author_id, array(
			'last_success_at'      => $now,
			'last_outcome'         => $outcome,
			'last_error_code'      => null,
			'last_error_message'   => null,
			'consecutive_failures' => 0,
			'retry_attempts'       => 0,
			'next_retry_at'        => 0,
		));
	}

	/**
	 * Record a failed run, incrementing the consecutive-failure counter.
	 *
	 * @param string $flow          Flow type.
	 * @param int    $author_id     Author ID.
	 * @param string $outcome       Outcome constant.
	 * @param string $error_code    Machine error code.
	 * @param string $error_message Human-readable message.
	 * @return int The new consecutive-failure count.
	 */
	public function record_failure(string $flow, int $author_id, string $outcome, string $error_code = '', string $error_message = ''): int {
		$existing  = $this->get($flow, $author_id);
		$new_count = ($existing ? (int) $existing->consecutive_failures : 0) + 1;

		$this->upsert($flow, $author_id, array(
			'last_outcome'         => $outcome,
			'last_error_code'      => $error_code,
			'last_error_message'   => $error_message,
			'consecutive_failures' => $new_count,
		));

		return $new_count;
	}

	/**
	 * Record that a retry has been scheduled.
	 *
	 * @param string $flow          Flow type.
	 * @param int    $author_id     Author ID.
	 * @param int    $next_retry_at Unix timestamp of the scheduled retry.
	 * @param int    $retry_attempt The attempt number just scheduled.
	 * @return void
	 */
	public function set_next_retry(string $flow, int $author_id, int $next_retry_at, int $retry_attempt): void {
		$this->upsert($flow, $author_id, array(
			'next_retry_at'  => max(0, $next_retry_at),
			'retry_attempts' => max(0, $retry_attempt),
		));
	}

	/**
	 * Clear any pending retry marker (e.g. after the retry fires or budget is spent).
	 *
	 * @param string $flow      Flow type.
	 * @param int    $author_id Author ID.
	 * @return void
	 */
	public function clear_retry(string $flow, int $author_id): void {
		$this->upsert($flow, $author_id, array(
			'next_retry_at' => 0,
		));
	}

	/**
	 * Get state rows whose scheduled retry time is due.
	 *
	 * @param int|null $now Reference timestamp; defaults to now.
	 * @return object[]
	 */
	public function get_due_retries($now = null): array {
		if (!$this->table_exists()) {
			return array();
		}

		$now = (null === $now) ? AIPS_DateTime::now()->timestamp() : (int) $now;

		$rows = $this->wpdb->get_results($this->wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE next_retry_at > 0 AND next_retry_at <= %d ORDER BY next_retry_at ASC",
			$now
		));

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Upsert a partial set of columns for a (flow, author) row.
	 *
	 * @param string $flow      Flow type.
	 * @param int    $author_id Author ID.
	 * @param array  $data      Column => value pairs.
	 * @return void
	 */
	private function upsert(string $flow, int $author_id, array $data): void {
		if (!$this->table_exists() || '' === $flow || $author_id <= 0) {
			return;
		}

		$data['flow_type']  = $flow;
		$data['author_id']  = $author_id;
		$data['updated_at'] = AIPS_DateTime::now()->timestamp();

		$existing = $this->get($flow, $author_id);

		if ($existing) {
			unset($data['flow_type'], $data['author_id']);
			$this->wpdb->update(
				$this->table_name,
				$data,
				array('flow_type' => $flow, 'author_id' => $author_id)
			);
			return;
		}

		$this->wpdb->insert($this->table_name, $data);
	}

	/**
	 * Whether the state table exists. Cached per request.
	 *
	 * @return bool
	 */
	private function table_exists(): bool {
		static $exists = null;
		if (null !== $exists) {
			return $exists;
		}

		$found  = $this->wpdb->get_var(
			$this->wpdb->prepare('SHOW TABLES LIKE %s', $this->table_name)
		);
		$exists = ($found === $this->table_name);

		return $exists;
	}
}
