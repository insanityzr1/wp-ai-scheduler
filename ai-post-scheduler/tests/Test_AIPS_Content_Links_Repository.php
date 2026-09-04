<?php
/**
 * Tests for AIPS_Content_Links_Repository
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Content_Links_Repository extends WP_UnitTestCase {

	/** @var AIPS_Content_Links_Repository */
	private $repo;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->repo = new AIPS_Content_Links_Repository();
		$wpdb->query("DELETE FROM " . $wpdb->prefix . "aips_content_links");
	}

	public function tearDown(): void {
		global $wpdb;
		$wpdb->query("DELETE FROM " . $wpdb->prefix . "aips_content_links");
		parent::tearDown();
	}

	public function test_sync_post_links_and_queries() {
		$post_a = $this->factory->post->create(array('post_title' => 'Post A', 'post_status' => 'publish'));
		$post_b = $this->factory->post->create(array('post_title' => 'Post B', 'post_status' => 'publish'));
		$post_c = $this->factory->post->create(array('post_title' => 'Post C', 'post_status' => 'publish'));

		$links_a = array(
			array(
				'target_id'   => $post_b,
				'anchor_text' => 'Learn Post B',
				'link_url'    => get_permalink($post_b),
				'post_type'   => 'post',
			),
			array(
				'target_id'   => $post_c,
				'anchor_text' => 'Learn Post C',
				'link_url'    => get_permalink($post_c),
				'post_type'   => 'post',
			),
		);

		$this->assertTrue($this->repo->sync_post_links($post_a, $links_a));

		// Verify outbound for Post A
		$outbound_a = $this->repo->get_outbound_links($post_a);
		$this->assertCount(2, $outbound_a);
		$this->assertSame(2, $this->repo->get_outbound_count($post_a));

		// Verify inbound for Post B
		$inbound_b = $this->repo->get_inbound_links($post_b);
		$this->assertCount(1, $inbound_b);
		$this->assertSame($post_a, (int) $inbound_b[0]->source_id);
		$this->assertSame('Learn Post B', $inbound_b[0]->anchor_text);
		$this->assertSame(1, $this->repo->get_inbound_count($post_b));

		// Test batch counts
		$batch = $this->repo->get_inbound_counts(array($post_a, $post_b, $post_c));
		$this->assertSame(0, $batch[$post_a]);
		$this->assertSame(1, $batch[$post_b]);
		$this->assertSame(1, $batch[$post_c]);

		// Post A is an orphan (0 inbounds)
		$orphans = $this->repo->get_orphan_post_ids(array('post'));
		$this->assertContains($post_a, $orphans);
		$this->assertNotContains($post_b, $orphans);

		// Verify directed edges
		$edges = $this->repo->get_all_directed_edges();
		$this->assertCount(2, $edges);

		// Delete Post A and verify cascade
		$this->repo->delete_by_post($post_a);
		$this->assertSame(0, $this->repo->get_outbound_count($post_a));
		$this->assertSame(0, $this->repo->get_inbound_count($post_b));
	}
}
