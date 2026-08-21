<?php
/**
 * Tests for the shared AIPS_Prompt_Builder_Section contract.
 *
 * Guards the formalization introduced for the prompt-builder section family:
 * every section builder must opt in to the shared type, the diversity injector
 * (a helper the sections consume) must stay excluded, and each section must
 * still expose its build() entry point.
 *
 * @package AI_Post_Scheduler
 */

class Test_Prompt_Builder_Section_Interface extends WP_UnitTestCase {

	/**
	 * Section builders that make up the prompt-builder family.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function section_class_provider() {
		return array(
			'topic'                    => array( 'AIPS_Prompt_Builder_Topic' ),
			'post content'             => array( 'AIPS_Prompt_Builder_Post_Content' ),
			'post title'               => array( 'AIPS_Prompt_Builder_Post_Title' ),
			'post excerpt'             => array( 'AIPS_Prompt_Builder_Post_Excerpt' ),
			'post metadata'            => array( 'AIPS_Prompt_Builder_Post_Metadata' ),
			'post featured image'      => array( 'AIPS_Prompt_Builder_Post_Featured_Image' ),
			'taxonomy'                 => array( 'AIPS_Prompt_Builder_Taxonomy' ),
			'authors'                  => array( 'AIPS_Prompt_Builder_Authors' ),
			'article structure section' => array( 'AIPS_Prompt_Builder_Article_Structure_Section' ),
		);
	}

	/**
	 * Every section builder must implement the shared interface.
	 *
	 * @dataProvider section_class_provider
	 *
	 * @param string $class Fully-qualified section class name.
	 */
	public function test_section_implements_interface( $class ) {
		$this->assertTrue(
			is_subclass_of( $class, 'AIPS_Prompt_Builder_Section' ) || in_array( 'AIPS_Prompt_Builder_Section', class_implements( $class ), true ),
			$class . ' should implement AIPS_Prompt_Builder_Section'
		);
	}

	/**
	 * Every section builder must still expose the build() entry point that the
	 * interface documents as the shared convention.
	 *
	 * @dataProvider section_class_provider
	 *
	 * @param string $class Fully-qualified section class name.
	 */
	public function test_section_exposes_public_build_method( $class ) {
		$this->assertTrue( method_exists( $class, 'build' ), $class . ' should expose a build() method' );

		$method = new ReflectionMethod( $class, 'build' );
		$this->assertTrue( $method->isPublic(), $class . '::build() should be public' );
	}

	/**
	 * The diversity injector is a collaborator the sections consume, not a
	 * section itself, and must stay excluded from the interface.
	 */
	public function test_diversity_injector_is_not_a_section() {
		$this->assertNotInstanceOf(
			'AIPS_Prompt_Builder_Section',
			new AIPS_Prompt_Builder_Diversity_Injector(),
			'AIPS_Prompt_Builder_Diversity_Injector is a helper and must not implement the section interface'
		);
	}
}
