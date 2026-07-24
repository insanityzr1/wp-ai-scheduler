<?php
/**
 * Tests for AIPS_Integration_Manager
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Integration_Manager extends WP_UnitTestCase {

	/** @var AIPS_Integration_Mappings_Repository */
	private $repo;

	/** @var AIPS_Test_Stub_AI_Service */
	private $ai_service;

	/** @var AIPS_Integration_Manager */
	private $manager;

	public function setUp(): void {
		parent::setUp();

		$this->repo = new AIPS_Integration_Mappings_Repository();
		$this->ai_service = new AIPS_Test_Stub_AI_Service();
		$this->manager = new AIPS_Integration_Manager($this->repo, $this->ai_service);

		add_filter('aips_integrations_registry', array($this, 'register_stub_adapter'));
	}

	public function tearDown(): void {
		remove_filter('aips_integrations_registry', array($this, 'register_stub_adapter'));
		parent::tearDown();
	}

	public function register_stub_adapter($map) {
		$map['stub'] = 'AIPS_Test_Stub_Manager_Integration';
		return $map;
	}

	private function make_context($template_id) {
		$template = new stdClass();
		$template->id = $template_id;
		return new AIPS_Template_Context($template);
	}

	public function test_ignores_non_template_contexts() {
		$context = $this->getMockBuilder(AIPS_Generation_Context::class)->getMock();
		$context->method('get_type')->willReturn('topic');

		$this->manager->handle_post_generated(1, null, null, $context);

		$this->assertSame(0, AIPS_Test_Stub_Manager_Integration::$write_calls);
	}

	public function test_no_op_when_no_mappings_saved() {
		$context = $this->make_context(999999);
		$this->manager->handle_post_generated(1, null, null, $context);
		$this->assertSame(0, AIPS_Test_Stub_Manager_Integration::$write_calls);
	}

	public function test_generates_and_writes_supported_field() {
		AIPS_Test_Stub_Manager_Integration::$write_calls = 0;
		AIPS_Test_Stub_Manager_Integration::$last_write = null;

		$template_id = 42;
		$this->repo->save_mapping(array(
			'template_id'    => $template_id,
			'integration_id' => 'stub',
			'source_key'     => 'group_1',
			'field_key'      => 'field_headline',
			'field_label'    => 'Headline',
			'field_type'     => 'text',
			'custom_prompt'  => 'Write a headline.',
			'is_active'      => true,
		));

		$this->ai_service->next_response = 'Generated Headline';

		$context = $this->make_context($template_id);
		$this->manager->handle_post_generated(555, null, null, $context);

		$this->assertSame(1, AIPS_Test_Stub_Manager_Integration::$write_calls);
		$this->assertSame(array(555, 'field_headline', 'Generated Headline'), AIPS_Test_Stub_Manager_Integration::$last_write);
	}

	public function test_skips_inactive_mapping() {
		AIPS_Test_Stub_Manager_Integration::$write_calls = 0;

		$template_id = 43;
		$this->repo->save_mapping(array(
			'template_id'    => $template_id,
			'integration_id' => 'stub',
			'source_key'     => 'group_1',
			'field_key'      => 'field_inactive',
			'field_type'     => 'text',
			'is_active'      => false,
		));

		$context = $this->make_context($template_id);
		$this->manager->handle_post_generated(556, null, null, $context);

		$this->assertSame(0, AIPS_Test_Stub_Manager_Integration::$write_calls);
	}

	public function test_skips_unsupported_shape() {
		AIPS_Test_Stub_Manager_Integration::$write_calls = 0;

		$template_id = 44;
		$this->repo->save_mapping(array(
			'template_id'    => $template_id,
			'integration_id' => 'stub',
			'source_key'     => 'group_1',
			'field_key'      => 'field_repeater',
			'field_type'     => 'repeater', // maps to SHAPE_STRUCTURED_LIST — not yet generatable.
			'is_active'      => true,
		));

		$context = $this->make_context($template_id);
		$this->manager->handle_post_generated(557, null, null, $context);

		$this->assertSame(0, AIPS_Test_Stub_Manager_Integration::$write_calls);
	}

	public function test_handle_template_deleted_removes_mappings() {
		$template_id = 45;
		$this->repo->save_mapping(array(
			'template_id'    => $template_id,
			'integration_id' => 'stub',
			'source_key'     => 'group_1',
			'field_key'      => 'field_headline',
			'field_type'     => 'text',
			'is_active'      => true,
		));

		$this->assertCount(1, $this->repo->get_by_template($template_id, false));

		$this->manager->handle_template_deleted(array('action' => 'deleted', 'template_id' => $template_id));

		$this->assertCount(0, $this->repo->get_by_template($template_id, false));
	}
}

if (!class_exists('AIPS_Test_Stub_AI_Service')) {
	class AIPS_Test_Stub_AI_Service implements AIPS_AI_Service_Interface {
		public $next_response = 'stub response';
		public function is_available() {
			return true;
		}
		public function generate_text($prompt, $options = array()) {
			return $this->next_response;
		}
		public function generate_json($prompt, $options = array()) {
			return array();
		}
		public function generate_image($prompt, $options = array()) {
			return '';
		}
		public function get_call_log() {
			return array();
		}
	}
}

if (!class_exists('AIPS_Test_Stub_Manager_Integration')) {
	class AIPS_Test_Stub_Manager_Integration implements AIPS_Integration_Interface {
		public static $write_calls = 0;
		public static $last_write = null;

		public function get_id() {
			return 'stub';
		}
		public function get_label() {
			return 'Stub';
		}
		public function is_available() {
			return true;
		}
		public function get_field_groups($post_type = null) {
			return array();
		}
		public function get_fields($group_id) {
			return array();
		}
		public function get_supported_field_types() {
			return array(
				'text'     => AIPS_Integration_Interface::SHAPE_SHORT_TEXT,
				'repeater' => AIPS_Integration_Interface::SHAPE_STRUCTURED_LIST,
			);
		}
		public function write_field_value($post_id, $field_key, $value) {
			self::$write_calls++;
			self::$last_write = array($post_id, $field_key, $value);
			return true;
		}
	}
}
