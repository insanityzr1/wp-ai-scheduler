<?php
/**
 * Contract tests for AIPS_Prompt_Builder_Section and AIPS_Prompt_Builder_Section_Base
 *
 * Verifies that all prompt-builder section classes implement the shared interface,
 * extend the base class, and instantiate with expected dependency wiring.
 *
 * @package AI_Post_Scheduler
 */

class Test_Prompt_Builder_Section_Contract extends WP_UnitTestCase {

	/**
	 * List of all 9 prompt builder section classes.
	 *
	 * @var string[]
	 */
	private $section_classes = array(
		'AIPS_Prompt_Builder_Article_Structure_Section',
		'AIPS_Prompt_Builder_Authors',
		'AIPS_Prompt_Builder_Post_Content',
		'AIPS_Prompt_Builder_Post_Excerpt',
		'AIPS_Prompt_Builder_Post_Featured_Image',
		'AIPS_Prompt_Builder_Post_Metadata',
		'AIPS_Prompt_Builder_Post_Title',
		'AIPS_Prompt_Builder_Taxonomy',
		'AIPS_Prompt_Builder_Topic',
	);

	/**
	 * Test that all 9 section classes implement AIPS_Prompt_Builder_Section.
	 */
	public function test_all_sections_implement_interface() {
		foreach ($this->section_classes as $class_name) {
			$this->assertTrue(
				class_exists($class_name),
				"Class {$class_name} should exist."
			);

			$implements = class_implements($class_name);
			$this->assertArrayHasKey(
				'AIPS_Prompt_Builder_Section',
				$implements,
				"Class {$class_name} should implement AIPS_Prompt_Builder_Section."
			);
		}
	}

	/**
	 * Test that all 9 section classes extend AIPS_Prompt_Builder_Section_Base.
	 */
	public function test_all_sections_extend_base_class() {
		foreach ($this->section_classes as $class_name) {
			$parents = class_parents($class_name);
			$this->assertArrayHasKey(
				'AIPS_Prompt_Builder_Section_Base',
				$parents,
				"Class {$class_name} should extend AIPS_Prompt_Builder_Section_Base."
			);
		}
	}

	/**
	 * Test default zero-argument instantiation for each section class.
	 */
	public function test_section_instances_can_be_constructed_without_arguments() {
		foreach ($this->section_classes as $class_name) {
			$instance = new $class_name();
			$this->assertInstanceOf('AIPS_Prompt_Builder_Section', $instance);
			$this->assertInstanceOf('AIPS_Prompt_Builder_Section_Base', $instance);
		}
	}

	/**
	 * Test that AIPS_Prompt_Builder_Diversity_Injector is NOT a section class.
	 */
	public function test_diversity_injector_is_not_section_implementor() {
		$implements = class_implements('AIPS_Prompt_Builder_Diversity_Injector');
		$this->assertArrayNotHasKey(
			'AIPS_Prompt_Builder_Section',
			$implements,
			'AIPS_Prompt_Builder_Diversity_Injector should not implement AIPS_Prompt_Builder_Section.'
		);
	}

	/**
	 * Test base class dependency injection and resolution with custom mocks.
	 */
	public function test_base_class_accepts_injected_dependencies() {
		$mock_tp = new AIPS_Template_Processor();
		$mock_di = new AIPS_Prompt_Builder_Diversity_Injector();

		$instance = new AIPS_Prompt_Builder_Post_Title($mock_tp, $mock_di);
		$this->assertInstanceOf('AIPS_Prompt_Builder_Post_Title', $instance);
		$this->assertInstanceOf('AIPS_Prompt_Builder_Section', $instance);
	}
}
