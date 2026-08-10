<?php
/**
 * AJAX controller for queued bulk author topic generation.
 *
 * @package AI_Post_Scheduler
 * @since 3.4.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class AIPS_Author_Topic_Batch_Controller {
	private $service;

	public function __construct($service = null) {
		$this->service = $service ?: new AIPS_Author_Topic_Batch_Service();
		add_action('wp_ajax_aips_enqueue_author_topic_generation', array($this, 'ajax_enqueue'));
		add_action('wp_ajax_aips_get_author_topic_batch_status', array($this, 'ajax_status'));
		add_action('wp_ajax_aips_cancel_author_topic_batch', array($this, 'ajax_cancel'));
	}

	private function authorize(): void {
		if (!check_ajax_referer('aips_ajax_nonce', 'nonce', false)) {
			AIPS_Ajax_Response::error(__('Invalid nonce.', 'ai-post-scheduler'));
		}
		if (!current_user_can('manage_options')) {
			AIPS_Ajax_Response::permission_denied();
		}
	}

	public function ajax_enqueue(): void {
		$this->authorize();
		$raw_ids = isset($_POST['author_ids']) && is_array($_POST['author_ids']) ? wp_unslash($_POST['author_ids']) : array();
		$author_ids = array_values(array_unique(array_filter(array_map('absint', $raw_ids))));
		$request_key = isset($_POST['request_key']) ? sanitize_text_field(wp_unslash($_POST['request_key'])) : '';
		if ('' === $request_key) {
			$request_key = wp_generate_uuid4();
		}

		$result = $this->service->enqueue($author_ids, $request_key);
		if (is_wp_error($result)) {
			AIPS_Ajax_Response::error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()));
		}
		AIPS_Ajax_Response::success($result);
	}

	public function ajax_status(): void {
		$this->authorize();
		$batch_id = isset($_POST['batch_id']) ? sanitize_text_field(wp_unslash($_POST['batch_id'])) : '';
		$result = $this->service->get_status($batch_id);
		if (is_wp_error($result)) {
			AIPS_Ajax_Response::error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()));
		}
		AIPS_Ajax_Response::success($result);
	}

	public function ajax_cancel(): void {
		$this->authorize();
		$batch_id = isset($_POST['batch_id']) ? sanitize_text_field(wp_unslash($_POST['batch_id'])) : '';
		$result = $this->service->cancel($batch_id);
		if (is_wp_error($result)) {
			AIPS_Ajax_Response::error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()));
		}
		AIPS_Ajax_Response::success(array('batch_id' => $batch_id, 'status' => AIPS_Bulk_Batch_Job_Store::STATUS_CANCELED));
	}
}
