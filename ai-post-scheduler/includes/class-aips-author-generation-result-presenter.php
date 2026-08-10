<?php
/**
 * Normalize structured author generation results for admin clients.
 *
 * @package AI_Post_Scheduler
 * @since 3.4.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class AIPS_Author_Generation_Result_Presenter {
	/**
	 * Present an author-post result with actionable links and retries.
	 *
	 * @param AIPS_Author_Post_Generation_Result $result        Generation result.
	 * @param array                              $schedule_info Schedule state.
	 * @param array                              $author_counts Fresh aggregate counters.
	 * @return array
	 */
	public function present_post(AIPS_Author_Post_Generation_Result $result, array $schedule_info = array(), array $author_counts = array()): array {
		$data = $result->to_array();
		$post_links = array();
		foreach ($result->get_post_ids() as $post_id) {
			$post_links[] = array(
				'id'       => (int) $post_id,
				'edit_url' => esc_url_raw(get_edit_post_link((int) $post_id, 'raw')),
			);
		}

		$retry_topic_ids = array();
		foreach ($data['failures'] as $failure) {
			$topic_id = isset($failure['topic_id']) ? absint($failure['topic_id']) : 0;
			if ($topic_id > 0) {
				$retry_topic_ids[] = $topic_id;
			}
		}
		foreach ($data['skipped'] as $skipped) {
			$topic_id = isset($skipped['topic_id']) ? absint($skipped['topic_id']) : 0;
			if ($topic_id > 0 && 'already_running' === ($skipped['reason'] ?? '')) {
				$retry_topic_ids[] = $topic_id;
			}
		}

		$message = sprintf(
			__('Generated %1$d of %2$d requested posts.', 'ai-post-scheduler'),
			(int) $data['success_count'],
			(int) $data['requested_count']
		);
		$first_link = !empty($post_links) ? $post_links[0] : array('id' => 0, 'edit_url' => '');

		return array_merge(
			$data,
			$schedule_info,
			array(
				'message'         => $message,
				'is_success'      => $result->is_success(),
				'post_links'      => $post_links,
				'post_id'         => (int) $first_link['id'],
				'edit_url'        => (string) $first_link['edit_url'],
				'retry_topic_ids' => array_values(array_unique($retry_topic_ids)),
				'author_counts'   => $author_counts,
			)
		);
	}

	/**
	 * Present an author-topic result with quality and review details.
	 *
	 * @param AIPS_Author_Topic_Generation_Result $result        Generation result.
	 * @param array                               $schedule_info Schedule state.
	 * @param string                              $review_url    Pending-topic review URL.
	 * @param array                               $author_counts Fresh aggregate counters.
	 * @return array
	 */
	public function present_topic(AIPS_Author_Topic_Generation_Result $result, array $schedule_info = array(), string $review_url = '', array $author_counts = array()): array {
		$data = $result->to_array();
		$message = sprintf(
			__('Generated %1$d of %2$d requested topics.', 'ai-post-scheduler'),
			(int) $data['persisted_count'],
			(int) $data['requested_count']
		);
		if (!$result->is_success()) {
			$message = !empty($data['error'])
				? (string) $data['error']
				: __('No topics were generated.', 'ai-post-scheduler');
		}

		return array_merge(
			$data,
			$schedule_info,
			array(
				'message'       => $message,
				'is_success'    => $result->is_success(),
				'topics'        => $result->get_persisted_topics(),
				'review_url'    => esc_url_raw($review_url),
				'author_counts' => $author_counts,
			)
		);
	}
}
