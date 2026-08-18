<?php
/**
 * Validation and orchestration for generated-post feedback.
 *
 * @package AI_Post_Scheduler
 */

if (!defined('ABSPATH')) {
	exit;
}

class AIPS_Post_Feedback_Service {

	const REACTIONS = array('liked', 'disliked', 'cleared');
	const REASONS = array(
		'tone_style', 'originality', 'relevance', 'accuracy', 'structure',
		'depth', 'engagement', 'seo', 'policy_safety', 'other',
	);

	private $repository;
	private $history_repository;
	private $indexing_service;

	public function __construct($repository = null, $history_repository = null, $indexing_service = null) {
		$this->repository         = $repository ?: new AIPS_Post_Feedback_Repository();
		$this->history_repository = $history_repository ?: new AIPS_History_Repository();
		$this->indexing_service    = $indexing_service ?: new AIPS_Internal_Links_Service();
	}

	public function record($post_id, $reaction, $reason_category = null, $comment = null, $user_id = 0) {
		$post_id  = absint($post_id);
		$user_id  = absint($user_id ?: get_current_user_id());
		$reaction = sanitize_key($reaction);
		$reason_category = $reason_category ? sanitize_key($reason_category) : null;

		if (!in_array($reaction, array('liked', 'disliked'), true)) {
			return new WP_Error('invalid_feedback_reaction', __('Reaction must be liked or disliked.', 'ai-post-scheduler'));
		}
		if ($reason_category && !in_array($reason_category, self::REASONS, true)) {
			return new WP_Error('invalid_feedback_reason', __('Invalid feedback reason.', 'ai-post-scheduler'));
		}

		$post = get_post($post_id);
		if (!$post || '1' !== (string) get_post_meta($post_id, AIPS_Post_Manager::META_GENERATED_POST, true)) {
			return new WP_Error('not_generated_post', __('Feedback is available only for generated posts.', 'ai-post-scheduler'));
		}

		$comment = null === $comment ? null : mb_substr(sanitize_textarea_field($comment), 0, 2000);
		$history = $this->history_repository->get_by_post_id($post_id);
		$event = array(
			'post_id'         => $post_id,
			'history_id'      => $history ? (int) $history->id : null,
			'user_id'         => $user_id,
			'reaction'        => $reaction,
			'reason_category' => $reason_category,
			'comment'         => $comment,
			'content_hash'    => $this->calculate_content_hash($post),
			'author_id'       => $history && !empty($history->author_id) ? (int) $history->author_id : null,
			'template_id'     => $history && !empty($history->template_id) ? (int) $history->template_id : null,
		);
		$event_id = $this->repository->append_event($event);
		if (is_wp_error($event_id)) {
			return $event_id;
		}

		$index_result = $this->indexing_service->index_post($post_id);
		return array(
			'event_id'    => $event_id,
			'feedback'    => $this->repository->get_current_for_post($post_id),
			'index_status'=> is_wp_error($index_result) ? 'unavailable' : 'indexed',
		);
	}

	public function clear($post_id, $user_id = 0) {
		$post_id = absint($post_id);
		if (!get_post($post_id) || '1' !== (string) get_post_meta($post_id, AIPS_Post_Manager::META_GENERATED_POST, true)) {
			return new WP_Error('not_generated_post', __('Feedback is available only for generated posts.', 'ai-post-scheduler'));
		}
		$current = $this->repository->get_current_for_post($post_id);
		$event_id = $this->repository->append_event(array(
			'post_id'     => $post_id,
			'history_id'  => $current ? $current->history_id : null,
			'user_id'     => absint($user_id ?: get_current_user_id()),
			'reaction'    => 'cleared',
			'content_hash'=> $current ? $current->content_hash : null,
			'author_id'   => $current ? $current->author_id : null,
			'template_id' => $current ? $current->template_id : null,
		));
		return is_wp_error($event_id) ? $event_id : array('event_id' => $event_id, 'feedback' => null);
	}

	public function get_current($post_id) {
		$current = $this->repository->get_current_for_post($post_id);
		return $current && 'cleared' !== $current->reaction ? $current : null;
	}

	public function get_current_many(array $post_ids) {
		$rows = $this->repository->get_current_for_posts($post_ids);
		return array_filter($rows, static function($row) { return 'cleared' !== $row->reaction; });
	}

	public function calculate_content_hash($post) {
		if (is_numeric($post)) {
			$post = get_post(absint($post));
		}
		if (!$post) {
			return '';
		}
		$parts = array($post->post_title, $post->post_excerpt, wp_strip_all_tags($post->post_content));
		$normalized = preg_replace('/\s+/u', ' ', trim(implode("\n", $parts)));
		return hash('sha256', (string) $normalized);
	}
}
