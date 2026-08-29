<?php
/**
 * Tests for AIPS_REST_Editor_Controller
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_REST_Editor_Controller extends WP_UnitTestCase {

	/**
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Set up before each test method.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action('rest_api_init');
	}

	/**
	 * Clean up after each test method.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Test that routes are registered.
	 */
	public function test_register_routes() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey('/aips/v1/editor/link-suggestions', $routes);
		$this->assertArrayHasKey('/aips/v1/editor/find-anchors', $routes);
	}

	/**
	 * Test permission callback blocks unauthorized users.
	 */
	public function test_check_editor_permissions_unauthorized() {
		wp_set_current_user(0);

		$request = new WP_REST_Request('POST', '/aips/v1/editor/link-suggestions');
		$request->set_body_params(array('content' => 'Sample draft content for indexing.'));
		$response = $this->server->dispatch($request);

		$this->assertSame(401, $response->get_status());
	}

	/**
	 * Test permission callback allows users with edit_posts capabilities.
	 */
	public function test_check_editor_permissions_authorized_user() {
		$user_id = $this->factory->user->create(array('role' => 'editor'));
		wp_set_current_user($user_id);

		$request = new WP_REST_Request('POST', '/aips/v1/editor/link-suggestions');
		$request->set_body_params(array('content' => 'Short text'));
		$response = $this->server->dispatch($request);

		$this->assertSame(200, $response->get_status());
	}

	/**
	 * Test link suggestions returns precomputed relationships when available.
	 */
	public function test_get_link_suggestions_precomputed() {
		$user_id = $this->factory->user->create(array('role' => 'editor'));
		wp_set_current_user($user_id);

		$source_post_id = $this->factory->post->create(array(
			'post_title'   => 'Source Article',
			'post_content' => 'Discussing advanced WordPress architecture and vector embeddings.',
			'post_status'  => 'publish',
		));

		$target_post_id = $this->factory->post->create(array(
			'post_title'   => 'Target Guide',
			'post_content' => 'A comprehensive guide to vector embeddings and semantic search.',
			'post_status'  => 'publish',
		));

		$rel_repo = new AIPS_Relationships_Repository();
		$rel_repo->upsert('post', $source_post_id, 'post', $target_post_id, 0.88, 'related_post');

		$controller = new AIPS_REST_Editor_Controller($rel_repo);
		$request    = new WP_REST_Request('POST', '/aips/v1/editor/link-suggestions');
		$request->set_body_params(array(
			'post_id' => $source_post_id,
		));

		$response = $controller->get_link_suggestions($request);
		$data     = $response->get_data();

		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['suggestions']);
		$this->assertSame($target_post_id, $data['suggestions'][0]['id']);
		$this->assertSame('Target Guide', $data['suggestions'][0]['title']);
		$this->assertSame(88, $data['suggestions'][0]['similarity_pct']);
		$this->assertTrue($data['suggestions'][0]['is_precomputed']);
	}

	/**
	 * Test find anchors validates required parameters.
	 */
	public function test_find_anchors_validates_required_args() {
		$user_id = $this->factory->user->create(array('role' => 'editor'));
		wp_set_current_user($user_id);

		$controller = new AIPS_REST_Editor_Controller();

		// Missing content
		$request = new WP_REST_Request('POST', '/aips/v1/editor/find-anchors');
		$request->set_body_params(array(
			'target_post_id' => 123,
		));
		$response = $controller->find_anchors($request);
		$this->assertWPError($response);
		$this->assertSame('empty_content', $response->get_error_code());

		// Missing target ID
		$request = new WP_REST_Request('POST', '/aips/v1/editor/find-anchors');
		$request->set_body_params(array(
			'source_content' => 'Some sample content here.',
		));
		$response = $controller->find_anchors($request);
		$this->assertWPError($response);
		$this->assertSame('invalid_target', $response->get_error_code());
	}

	/**
	 * Test that target_post_type filters out suggestions of other post types.
	 */
	public function test_get_link_suggestions_filters_by_post_type() {
		$user_id = $this->factory->user->create(array('role' => 'editor'));
		wp_set_current_user($user_id);

		$source_post_id = $this->factory->post->create(array(
			'post_title'   => 'Parent Article',
			'post_type'    => 'post',
			'post_status'  => 'publish',
		));

		$target_post_id = $this->factory->post->create(array(
			'post_title'   => 'Target Regular Post',
			'post_type'    => 'post',
			'post_status'  => 'publish',
		));

		$target_page_id = $this->factory->post->create(array(
			'post_title'   => 'Target Page Doc',
			'post_type'    => 'page',
			'post_status'  => 'publish',
		));

		$rel_repo = new AIPS_Relationships_Repository();
		$rel_repo->upsert('post', $source_post_id, 'post', $target_post_id, 0.90, 'related_post');
		$rel_repo->upsert('post', $source_post_id, 'post', $target_page_id, 0.85, 'related_post');

		$controller = new AIPS_REST_Editor_Controller($rel_repo);

		// Request only 'page'
		$request = new WP_REST_Request('POST', '/aips/v1/editor/link-suggestions');
		$request->set_body_params(array(
			'post_id'          => $source_post_id,
			'target_post_type' => 'page',
		));

		$response = $controller->get_link_suggestions($request);
		$data     = $response->get_data();

		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['suggestions']);
		$this->assertSame($target_page_id, $data['suggestions'][0]['id']);
		$this->assertSame('page', $data['suggestions'][0]['post_type']);
	}

	/**
	 * Test that custom query parameter generates similarity suggestions based on keyword.
	 */
	public function test_get_link_suggestions_with_query_override() {
		$user_id = $this->factory->user->create(array('role' => 'editor'));
		wp_set_current_user($user_id);

		$target_post_id = $this->factory->post->create(array(
			'post_title'   => 'Docker Container Architecture',
			'post_type'    => 'post',
			'post_status'  => 'publish',
		));

		$embeddings_repo = $this->createMock(AIPS_Embeddings_Repository::class);
		$embeddings_repo->method('get_all_for_similarity')->willReturn(array(
			(object) array(
				'object_id'        => $target_post_id,
				'object_post_type' => 'post',
				'embedding'        => json_encode(array(0.1, 0.2, 0.3)),
			),
		));

		$embeddings_service = $this->createMock(AIPS_Embeddings_Service::class);
		$embeddings_service->method('is_embeddings_supported')->willReturn(true);
		$embeddings_service->method('generate_embedding')->willReturn(array(0.1, 0.2, 0.3));
		$embeddings_service->method('find_nearest_neighbors')->willReturn(array(
			array(
				'id'         => $target_post_id,
				'similarity' => 0.95,
			),
		));

		$controller = new AIPS_REST_Editor_Controller(null, $embeddings_repo, $embeddings_service);

		$request = new WP_REST_Request('POST', '/aips/v1/editor/link-suggestions');
		$request->set_body_params(array(
			'query' => 'Docker caching',
		));

		$response = $controller->get_link_suggestions($request);
		$data     = $response->get_data();

		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['suggestions']);
		$this->assertSame($target_post_id, $data['suggestions'][0]['id']);
		$this->assertSame(95, $data['suggestions'][0]['similarity_pct']);
	}
}
