<?php
/**
 * Author Topic Batch Service.
 *
 * @package AI_Post_Scheduler
 * @since 3.4.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class AIPS_Author_Topic_Batch_Service {
	const JOB_TYPE = 'author_topic_generation';

	private $authors_repository;
	private $job_store;
	private $item_repository;
	private $queue_service;

	public function __construct($authors_repository = null, $job_store = null, $item_repository = null, $queue_service = null) {
		$this->authors_repository = $authors_repository ?: new AIPS_Authors_Repository();
		$this->job_store          = $job_store ?: new AIPS_Bulk_Batch_Job_Store();
		$this->item_repository    = $item_repository ?: new AIPS_Author_Topic_Batch_Items_Repository();
		$this->queue_service      = $queue_service ?: new AIPS_Batch_Queue_Service();
	}

	/**
	 * Validate authors, persist an idempotent batch, and dispatch its workers.
	 *
	 * @param int[]  $author_ids Author IDs.
	 * @param string $request_key Client idempotency key.
	 * @return array|WP_Error
	 */
	public function enqueue(array $author_ids, string $request_key) {
		$request_key = trim($request_key);
		$request_key = function_exists('mb_substr') ? mb_substr($request_key, 0, 100, 'UTF-8') : substr($request_key, 0, 100);
		if ('' === $request_key) {
			return new WP_Error('missing_request_key', __('A request key is required.', 'ai-post-scheduler'));
		}

		$existing = method_exists($this->job_store, 'find_by_request_key')
			? $this->job_store->find_by_request_key(self::JOB_TYPE, $request_key)
			: $this->job_store->find_active_by_request_key(self::JOB_TYPE, $request_key);
		if ($existing) {
			return array(
				'batch_id' => (string) $existing->job_id,
				'status'   => (string) $existing->status,
				'existing' => true,
			);
		}

		$author_ids = array_values(array_unique(array_filter(array_map('absint', $author_ids))));
		sort($author_ids, SORT_NUMERIC);
		$accepted = array();
		$invalid  = array();
		foreach ($author_ids as $author_id) {
			if ($this->authors_repository->get_by_id($author_id)) {
				$accepted[] = $author_id;
			} else {
				$invalid[] = $author_id;
			}
		}

		if (empty($accepted)) {
			return new WP_Error('no_valid_authors', __('No valid authors were selected.', 'ai-post-scheduler'));
		}

		$correlation_id = (string) AIPS_Correlation_ID::get();
		$batch_id = $this->job_store->create(
			self::JOB_TYPE,
			$accepted,
			array(
				'request_key'        => $request_key,
				'invalid_author_ids' => $invalid,
				'correlation_id'     => $correlation_id,
			)
		);
		if (is_wp_error($batch_id)) {
			$existing = method_exists($this->job_store, 'find_by_request_key')
				? $this->job_store->find_by_request_key(self::JOB_TYPE, $request_key)
				: $this->job_store->find_active_by_request_key(self::JOB_TYPE, $request_key);
			if ($existing) {
				return array('batch_id' => (string) $existing->job_id, 'status' => (string) $existing->status, 'existing' => true);
			}
			return $batch_id;
		}

		if (!$this->item_repository->create_batch_items($batch_id, $accepted)) {
			$this->job_store->mark_failed($batch_id);
			return new WP_Error('batch_items_create_failed', __('The author batch items could not be saved.', 'ai-post-scheduler'));
		}

		$dispatch = $this->queue_service->dispatch_generic(
			AIPS_Bulk_Batch_Processor::HOOK,
			count($accepted),
			AIPS_DateTime::now()->timestamp(),
			array($batch_id),
			$correlation_id
		);
		if (is_wp_error($dispatch)) {
			$this->job_store->mark_failed($batch_id);
			return $dispatch;
		}

		return array(
			'batch_id'           => $batch_id,
			'status'             => AIPS_Bulk_Batch_Job_Store::STATUS_PENDING,
			'existing'           => false,
			'accepted_author_ids' => $accepted,
			'invalid_author_ids'  => $invalid,
			'dispatch'            => $dispatch,
		);
	}

	/**
	 * Return aggregate and per-author progress.
	 *
	 * @param string $batch_id Batch UUID.
	 * @return array|WP_Error
	 */
	public function get_status(string $batch_id) {
		$job = $this->job_store->get($batch_id);
		if (!$job || self::JOB_TYPE !== (string) ($job->job_type ?? self::JOB_TYPE)) {
			return new WP_Error('batch_not_found', __('Author topic batch not found.', 'ai-post-scheduler'));
		}

		$total     = max(0, (int) $job->total);
		$processed = min($total, max(0, (int) $job->processed));

		$authors = array_map(function($row) { return (array) $row; }, $this->item_repository->get_by_batch($batch_id));
		$completed_count = count(array_filter($authors, function($author) { return 'completed' === ($author['status'] ?? ''); }));
		$failed_count = count(array_filter($authors, function($author) { return 'failed' === ($author['status'] ?? ''); }));
		$status = (string) $job->status;
		if ($processed >= $total && $completed_count > 0 && $failed_count > 0) {
			$status = 'partial';
		}

		return array(
			'batch_id'  => (string) $job->job_id,
			'status'    => $status,
			'total'     => $total,
			'processed' => $processed,
			'percent'   => $total > 0 ? (int) floor(($processed / $total) * 100) : 0,
			'authors'   => $authors,
		);
	}

	/**
	 * Cancel work that has not started yet.
	 *
	 * @param string $batch_id Batch UUID.
	 * @return bool|WP_Error
	 */
	public function cancel(string $batch_id) {
		$job = $this->job_store->get($batch_id);
		if (!$job || self::JOB_TYPE !== (string) ($job->job_type ?? '')) {
			return new WP_Error('batch_not_found', __('Author topic batch not found.', 'ai-post-scheduler'));
		}
		if (!in_array((string) $job->status, array(AIPS_Bulk_Batch_Job_Store::STATUS_PENDING, AIPS_Bulk_Batch_Job_Store::STATUS_PROCESSING), true)) {
			return new WP_Error('batch_not_cancelable', __('Only active author topic batches can be canceled.', 'ai-post-scheduler'));
		}
		$canceled = method_exists($this->job_store, 'cancel_active')
			? $this->job_store->cancel_active($batch_id, self::JOB_TYPE)
			: $this->job_store->update_status($batch_id, AIPS_Bulk_Batch_Job_Store::STATUS_CANCELED);
		if (!$canceled) {
			return new WP_Error('batch_cancel_failed', __('The author topic batch could not be canceled.', 'ai-post-scheduler'));
		}
		$this->item_repository->cancel_pending($batch_id);
		return true;
	}
}
