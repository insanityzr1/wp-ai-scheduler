<?php
/**
 * Persistence for per-author progress within author topic batches.
 *
 * @package AI_Post_Scheduler
 * @since 3.4.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class AIPS_Author_Topic_Batch_Items_Repository {
	private $wpdb;
	private $table_name;

	public function __construct() {
		global $wpdb;
		$this->wpdb       = $wpdb;
		$this->table_name = $wpdb->prefix . 'aips_author_topic_batch_items';
	}

	public function create_batch_items(string $batch_id, array $author_ids): bool {
		$success = true;
		$now = AIPS_DateTime::now()->timestamp();
		foreach (array_values(array_unique(array_map('absint', $author_ids))) as $author_id) {
			$result = $this->wpdb->insert(
				$this->table_name,
				array(
					'batch_id'   => $batch_id,
					'author_id'  => $author_id,
					'status'     => 'queued',
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
			$success = false !== $result && $success;
		}
		return $success;
	}

	public function get_by_batch(string $batch_id): array {
		$rows = $this->wpdb->get_results($this->wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE batch_id = %s ORDER BY author_id ASC",
			$batch_id
		));
		return is_array($rows) ? $rows : array();
	}

	public function claim(string $batch_id, int $author_id): bool {
		$result = $this->wpdb->query($this->wpdb->prepare(
			"UPDATE {$this->table_name} SET status = 'running', updated_at = %d WHERE batch_id = %s AND author_id = %d AND status = 'queued'",
			AIPS_DateTime::now()->timestamp(),
			$batch_id,
			$author_id
		));
		return 1 === (int) $result;
	}

	public function record_result(string $batch_id, int $author_id, $result): bool {
		$is_error = is_wp_error($result);
		$result_data = $result instanceof AIPS_Generation_Result_Interface ? $result->to_array() : $result;
		if ($result instanceof AIPS_Generation_Result_Interface) {
			$is_error = !$result->is_success();
		}
		$data = array(
			'status'        => $is_error ? 'failed' : 'completed',
			'error_code'    => is_wp_error($result) ? (string) $result->get_error_code() : '',
			'error_message' => is_wp_error($result) ? (string) $result->get_error_message() : ($is_error && is_array($result_data) ? (string) ($result_data['error'] ?? '') : ''),
			'result_json'   => wp_json_encode(is_wp_error($result) ? array() : $result_data),
			'updated_at'    => AIPS_DateTime::now()->timestamp(),
		);
		return false !== $this->wpdb->update($this->table_name, $data, array('batch_id' => $batch_id, 'author_id' => $author_id));
	}

	public function cancel_pending(string $batch_id): bool {
		$result = $this->wpdb->query($this->wpdb->prepare(
			"UPDATE {$this->table_name} SET status = 'canceled', updated_at = %d WHERE batch_id = %s AND status = 'queued'",
			AIPS_DateTime::now()->timestamp(),
			$batch_id
		));
		return false !== $result;
	}
}
