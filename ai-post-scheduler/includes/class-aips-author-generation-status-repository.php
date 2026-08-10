<?php
/**
 * Aggregate author generation status queries for the Authors admin screen.
 *
 * @package AI_Post_Scheduler
 * @since 3.4.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class AIPS_Author_Generation_Status_Repository {
	private $wpdb;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Get topic/post flow status for all requested authors in four bounded queries.
	 *
	 * @param int[] $author_ids Author IDs.
	 * @return array<int,array>
	 */
	public function get_for_authors(array $author_ids): array {
		$author_ids = array_values(array_unique(array_filter(array_map('absint', $author_ids))));
		if (empty($author_ids)) {
			return array();
		}

		$status = array();
		foreach ($author_ids as $author_id) {
			$status[$author_id] = array(
				'topic' => array('last_attempt_at' => 0, 'last_success_at' => 0, 'last_outcome' => '', 'next_retry_at' => 0, 'running' => false),
				'post'  => array('last_attempt_at' => 0, 'last_success_at' => 0, 'last_outcome' => '', 'next_retry_at' => 0, 'running' => false),
				'counts' => array('pending' => 0, 'approved' => 0, 'rejected' => 0, 'posts_generated' => 0, 'generated_posts' => 0),
			);
		}

		$in = implode(',', array_fill(0, count($author_ids), '%d'));
		$state_table  = $this->wpdb->prefix . 'aips_generation_state';
		$claims_table = $this->wpdb->prefix . 'aips_generation_claims';
		$topics_table = $this->wpdb->prefix . 'aips_author_topics';
		$authors_table = $this->wpdb->prefix . 'aips_authors';
		$logs_table = $this->wpdb->prefix . 'aips_author_topic_logs';
		$posts_table = $this->wpdb->posts;

		$states = $this->wpdb->get_results($this->wpdb->prepare(
			"SELECT * FROM {$state_table} WHERE author_id IN ({$in})",
			$author_ids
		));
		foreach ((array) $states as $row) {
			$flow = 'author_post' === $row->flow_type ? 'post' : 'topic';
			$status[(int) $row->author_id][$flow] = array(
				'last_attempt_at' => (int) $row->last_attempt_at,
				'last_success_at' => (int) $row->last_success_at,
				'last_outcome'    => (string) $row->last_outcome,
				'next_retry_at'   => (int) $row->next_retry_at,
				'running'         => false,
			);
		}

		$claims = $this->wpdb->get_results($this->wpdb->prepare(
			"SELECT claim_type, resource_id FROM {$claims_table} WHERE resource_id IN ({$in}) AND expires_at > %d AND claim_type IN (%s,%s)",
			array_merge($author_ids, array(
				AIPS_DateTime::now()->timestamp(),
				AIPS_Generation_Claims_Repository::TYPE_AUTHOR_TOPIC_GENERATION,
				AIPS_Generation_Claims_Repository::TYPE_AUTHOR_POST_GENERATION,
			))
		));
		foreach ((array) $claims as $row) {
			$flow = AIPS_Generation_Claims_Repository::TYPE_AUTHOR_POST_GENERATION === $row->claim_type ? 'post' : 'topic';
			$status[(int) $row->resource_id][$flow]['running'] = true;
		}

		$counts = $this->wpdb->get_results($this->wpdb->prepare(
			"SELECT t.author_id,
			SUM(t.status = 'pending') AS pending,
			SUM(t.status = 'rejected') AS rejected,
			SUM(t.status = 'approved' AND COALESCE(g.generated_count, 0) < GREATEST(1, COALESCE(a.max_posts_per_topic, 1))) AS approved,
			SUM(t.status = 'approved' AND COALESCE(g.generated_count, 0) >= GREATEST(1, COALESCE(a.max_posts_per_topic, 1))) AS posts_generated,
			SUM(COALESCE(g.generated_count, 0)) AS generated_posts
			FROM {$topics_table} t
			INNER JOIN {$authors_table} a ON a.id = t.author_id
			LEFT JOIN (
				SELECT l.author_topic_id, COUNT(*) AS generated_count
				FROM {$logs_table} l INNER JOIN {$posts_table} p ON p.ID = l.post_id
				WHERE l.action = 'post_generated' AND l.post_id IS NOT NULL
				GROUP BY l.author_topic_id
			) g ON g.author_topic_id = t.id
			WHERE t.author_id IN ({$in}) GROUP BY t.author_id",
			$author_ids
		));
		foreach ((array) $counts as $row) {
			$status[(int) $row->author_id]['counts'] = array(
				'pending'         => (int) $row->pending,
				'approved'        => (int) $row->approved,
				'rejected'        => (int) $row->rejected,
				'posts_generated' => (int) $row->posts_generated,
				'generated_posts' => (int) $row->generated_posts,
			);
		}

		return $status;
	}
}
