<?php
/**
 * Bulk Batch Job Store
 *
 * Persists bulk-generation job descriptors to the `aips_bulk_batch_jobs`
 * database table so that the job payload (items + options) survives beyond
 * the current PHP request and can be retrieved by a later cron worker.
 *
 * This is the persistence layer for the async bulk-generation pipeline.
 * Closures are NOT serialisable, so only the job_type string is stored;
 * the actual per-item callable is looked up at execution time via
 * AIPS_Bulk_Batch_Processor's strategy registry.
 *
 * Table schema (see AIPS_DB_Manager::get_schema())
 * ------------------------------------------------
 *   job_id      varchar(36)  UUID v4 primary key
 *   job_type    varchar(100) Strategy key (registered in AIPS_Bulk_Batch_Processor)
 *   items_json  longtext     JSON-encoded items array
 *   options_json longtext    JSON-encoded options array (serialisable subset)
 *   status      varchar(20)  pending | processing | completed | failed
 *   total       int          Total item count
 *   processed   int          Items processed so far (across all batch slices)
 *   created_at  bigint       Unix timestamp
 *   updated_at  bigint       Unix timestamp
 *
 * @package AI_Post_Scheduler
 * @since   2.6.0
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class AIPS_Bulk_Batch_Job_Store
 *
 * Repository for async bulk-generation job descriptors.
 * All SQL lives here; no SQL in callers.
 */
class AIPS_Bulk_Batch_Job_Store {

	/**
	 * Job status constants.
	 */
	const STATUS_PENDING    = 'pending';
	const STATUS_PROCESSING = 'processing';
	const STATUS_COMPLETED  = 'completed';
	const STATUS_FAILED     = 'failed';
	const STATUS_CANCELED   = 'canceled';

	/**
	 * Number of days after which completed/failed jobs are eligible for cleanup.
	 *
	 * @var int
	 */
	const CLEANUP_DAYS = 7;
	const CLEANUP_BATCH_SIZE = 500;

	/**
	 * Return the full table name (with WP prefix).
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aips_bulk_batch_jobs';
	}

	/**
	 * Generate a v4-style UUID.
	 *
	 * Uses wp_generate_uuid4() when available (WP 4.7+); falls back to a
	 * manual implementation for older environments.
	 *
	 * @return string UUID string.
	 */
	private function generate_uuid(): string {
		if (function_exists('wp_generate_uuid4')) {
			return wp_generate_uuid4();
		}

		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand(0, 0xffff),
			wp_rand(0, 0xffff),
			wp_rand(0, 0xffff),
			wp_rand(0, 0x0fff) | 0x4000,
			wp_rand(0, 0x3fff) | 0x8000,
			wp_rand(0, 0xffff),
			wp_rand(0, 0xffff),
			wp_rand(0, 0xffff)
		);
	}

	// -----------------------------------------------------------------------
	// Write methods
	// -----------------------------------------------------------------------

	/**
	 * Persist a new bulk batch job.
	 *
	 * @param string $job_type Job type key — must match a registered strategy.
	 * @param array  $items    Items to process (must be JSON-serialisable).
	 * @param array  $options  Serialisable options subset (no closures).
	 * @return string|WP_Error The new job_id UUID on success, or WP_Error on failure.
	 */
	public function create( string $job_type, array $items, array $options = array() ) {
		global $wpdb;

		$job_id = $this->generate_uuid();
		$now    = time();

		// Strip any non-serialisable keys (closures, objects) from options.
		$safe_options = $this->strip_non_serialisable( $options );

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'job_id'       => $job_id,
				'job_type'     => $job_type,
				'items_json'   => wp_json_encode( $items ),
				'options_json' => wp_json_encode( $safe_options ),
				'request_key'  => isset($safe_options['request_key']) ? (string) $safe_options['request_key'] : null,
				'status'       => self::STATUS_PENDING,
				'total'        => count( $items ),
				'processed'    => 0,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
		);

		if ( $result === false ) {
			return new WP_Error(
				'bulk_batch_job_create_failed',
				/* translators: %s: database error message */
				sprintf( __( 'Failed to create bulk batch job: %s', 'ai-post-scheduler' ), $wpdb->last_error )
			);
		}

		return $job_id;
	}

	public function find_by_request_key(string $job_type, string $request_key) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$this->table()} WHERE job_type = %s AND request_key = %s ORDER BY created_at DESC LIMIT 1",
			$job_type,
			$request_key
		));
	}

	public function find_active_by_request_key(string $job_type, string $request_key) {
		return $this->find_by_request_key($job_type, $request_key);
	}

	/**
	 * Retrieve a job by its UUID.
	 *
	 * @param string $job_id UUID of the job.
	 * @return object|null Row object or null when not found.
	 */
	public function get( string $job_id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE job_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);

		if ( ! $row ) {
			return null;
		}

		// Decode JSON fields into arrays for convenience.
		$row->items   = json_decode( $row->items_json,   true ) ?: array();
		$row->options = json_decode( $row->options_json, true ) ?: array();

		return $row;
	}

	/**
	 * Get counts for selected statuses.
	 *
	 * @param array $statuses Statuses to include.
	 * @return array<string,int>
	 */
	public function get_status_counts( array $statuses ): array {
		global $wpdb;

		$statuses = array_values(
			array_filter(
				array_map( 'sanitize_key', $statuses )
			)
		);

		if ( empty( $statuses ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql          = "SELECT status, COUNT(*) AS count FROM {$this->table()} WHERE status IN ({$placeholders}) GROUP BY status"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prepared_sql = $wpdb->prepare( $sql, $statuses );
		$rows         = $wpdb->get_results( $prepared_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$counts = array_fill_keys( $statuses, 0 );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$status = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ] = isset( $row['count'] ) ? (int) $row['count'] : 0;
				}
			}
		}

		return $counts;
	}

	/**
	 * Mark a job as failed immediately, overriding whatever status it currently holds.
	 *
	 * Call this as soon as any slice reports failures so that the final
	 * completion step cannot accidentally revert the job to 'completed'.
	 *
	 * @param string $job_id UUID of the job to fail.
	 * @return bool True on success, false on failure.
	 */
	public function mark_failed( string $job_id ): bool {
		return $this->update_status( $job_id, self::STATUS_FAILED );
	}

	/**
	 * Mark a job as completed, but only if it is still in 'processing' state.
	 *
	 * The conditional WHERE clause ensures that a job already marked 'failed'
	 * by an earlier slice is never overwritten with 'completed'.
	 *
	 * @param string $job_id UUID of the job to complete.
	 * @return bool True when the row was updated, false when not (e.g. already failed).
	 */
	public function mark_completed( string $job_id ): bool {
		global $wpdb;

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"UPDATE {$this->table()} SET status = %s, updated_at = %d WHERE job_id = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_COMPLETED,
				AIPS_DateTime::now()->timestamp(),
				$job_id,
				self::STATUS_PROCESSING
			)
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Transition a job from pending to processing.
	 *
	 * Safe to call from every slice; only the first pending slice actually
	 * changes the row and later slices simply observe the existing state.
	 *
	 * @param string $job_id UUID of the job to start.
	 * @return bool True on success, false on DB failure.
	 */
	public function start_processing( string $job_id ): bool {
		global $wpdb;

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"UPDATE {$this->table()} SET status = %s, updated_at = %d WHERE job_id = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PROCESSING,
				time(),
				$job_id,
				self::STATUS_PENDING
			)
		);

		return $result !== false;
	}

	/**
	 * Update a job's status and optionally its processed count.
	 *
	 * @param string   $job_id    UUID of the job to update.
	 * @param string   $status    New status value.
	 * @param int|null $processed Optional; set the processed item count.
	 * @return bool True on success, false on failure.
	 */
	public function update_status( string $job_id, string $status, ?int $processed = null ): bool {
		global $wpdb;

		$data   = array(
			'status'     => $status,
			'updated_at' => time(),
		);
		$format = array( '%s', '%d' );

		if ( $processed !== null ) {
			$data['processed'] = $processed;
			$format[]          = '%d';
		}

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			$data,
			array( 'job_id' => $job_id ),
			$format,
			array( '%s' )
		);

		return $result !== false;
	}

	/**
	 * Atomically cancel an active job of the expected type.
	 *
	 * @param string $job_id   UUID of the job.
	 * @param string $job_type Expected job type.
	 * @return bool True only when an active matching row was changed.
	 */
	public function cancel_active(string $job_id, string $job_type): bool {
		global $wpdb;

		$result = $wpdb->query($wpdb->prepare(
			"UPDATE {$this->table()} SET status = %s, updated_at = %d WHERE job_id = %s AND job_type = %s AND status IN (%s,%s,%s)",
			self::STATUS_CANCELED,
			AIPS_DateTime::now()->timestamp(),
			$job_id,
			$job_type,
			self::STATUS_PENDING,
			self::STATUS_PROCESSING,
			self::STATUS_FAILED
		));

		return 1 === (int) $result;
	}

	/**
	 * Increment the processed item counter for a job.
	 *
	 * Uses a single UPDATE to avoid race conditions between concurrent batch
	 * workers processing slices of the same job.
	 *
	 * @param string $job_id UUID of the job.
	 * @param int    $count  Number of items completed in this slice.
	 * @return bool True only when the parent counter changed.
	 */
	public function increment_processed( string $job_id, int $count ): bool {
		global $wpdb;

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"UPDATE {$this->table()} SET processed = LEAST(total, processed + %d), updated_at = %d WHERE job_id = %s AND processed < total", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count,
				AIPS_DateTime::now()->timestamp(),
				$job_id
			)
		);

		return 1 === (int) $result;
	}

	/**
	 * Reconcile a parent counter from idempotent terminal child rows.
	 *
	 * @return bool True only when the parent row changed.
	 */
	public function reconcile_processed(string $job_id, int $processed, string $final_status = ''): bool {
		global $wpdb;
		$processed = max(0, $processed);
		$now = AIPS_DateTime::now()->timestamp();
		if ('' !== $final_status) {
			$result = $wpdb->query($wpdb->prepare(
				"UPDATE {$this->table()} SET processed = GREATEST(processed, %d), status = %s, updated_at = %d WHERE job_id = %s AND status <> %s AND (processed < %d OR status <> %s)",
				$processed,
				$final_status,
				$now,
				$job_id,
				self::STATUS_CANCELED,
				$processed,
				$final_status
			));
		} else {
			$result = $wpdb->query($wpdb->prepare(
				"UPDATE {$this->table()} SET processed = GREATEST(processed, %d), updated_at = %d WHERE job_id = %s AND processed < %d",
				$processed,
				$now,
				$job_id,
				$processed
			));
		}
		return 1 === (int) $result;
	}

	// -----------------------------------------------------------------------
	// Maintenance
	// -----------------------------------------------------------------------

	/**
	 * Delete completed and failed jobs older than CLEANUP_DAYS days.
	 *
	 * Called by the `aips_cleanup_bulk_batch_jobs` daily cron hook.
	 *
	 * @return int Number of rows deleted.
	 */
	public function cleanup_old_jobs($item_repository = null): int {
		global $wpdb;

		$cutoff = AIPS_DateTime::now()->timestamp() - ( self::CLEANUP_DAYS * DAY_IN_SECONDS );
		$job_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT job_id FROM {$this->table()} WHERE status IN ('completed','failed','canceled') AND updated_at < %d ORDER BY updated_at ASC LIMIT %d",
			$cutoff,
			self::CLEANUP_BATCH_SIZE
		));
		if (empty($job_ids)) {
			return 0;
		}

		$item_repository = $item_repository ?: new AIPS_Author_Topic_Batch_Items_Repository();
		if (!$item_repository->delete_for_batches($job_ids)) {
			return 0;
		}

		$placeholders = implode(',', array_fill(0, count($job_ids), '%s'));
		$deleted = $wpdb->query($wpdb->prepare(
			"DELETE FROM {$this->table()} WHERE job_id IN ({$placeholders})",
			$job_ids
		));

		return (int) $deleted;
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Remove non-serialisable values (closures, objects) from an options array.
	 *
	 * Only scalar values, arrays of scalars, and null are preserved so that
	 * json_encode() always produces a valid string.
	 *
	 * @param array $options Raw options array.
	 * @return array Filtered options safe for JSON storage.
	 */
	private function strip_non_serialisable( array $options ): array {
		$safe = array();
		foreach ( $options as $key => $value ) {
			if ( is_scalar( $value ) || is_null( $value ) ) {
				$safe[ $key ] = $value;
			} elseif ( is_array( $value ) ) {
				$safe[ $key ] = $this->strip_non_serialisable( $value );
			}
			// Closures and objects are silently dropped.
		}
		return $safe;
	}
}
