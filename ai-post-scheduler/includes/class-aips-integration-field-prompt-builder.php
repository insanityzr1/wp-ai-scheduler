<?php
/**
 * Integration Field Prompt Builder
 *
 * Assembles the AI prompt used to generate a single third-party plugin
 * field's value (e.g. one ACF field). Mirrors the shape of
 * AIPS_Prompt_Builder_Post_Content: process template variables, then let
 * plugins adjust the result via a filter.
 *
 * @package AI_Post_Scheduler
 * @since 2.10.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class AIPS_Integration_Field_Prompt_Builder {

	/**
	 * @var AIPS_Template_Processor
	 */
	private $template_processor;

	/**
	 * @param AIPS_Template_Processor|null $template_processor Optional template processor.
	 */
	public function __construct($template_processor = null) {
		$this->template_processor = $template_processor ?: new AIPS_Template_Processor();
	}

	/**
	 * Build the prompt for a single field.
	 *
	 * Prefers an explicit per-field custom prompt (saved on the mapping row);
	 * falls back to the field's own "instructions" text (e.g. ACF's field
	 * help text) when no custom prompt was set, and finally to a generic
	 * instruction built from the field label so every mapped field always
	 * gets a usable prompt.
	 *
	 * @param array                    $field_def     Field definition as returned by AIPS_Integration_Interface::get_fields().
	 * @param AIPS_Generation_Context  $context       Generation context driving this post.
	 * @param string                   $custom_prompt Optional per-field custom prompt (may contain template variables).
	 * @return string
	 */
	public function build($field_def, $context, $custom_prompt = '') {
		do_action('aips_before_build_integration_field_prompt', $field_def, $context);

		$topic = $context instanceof AIPS_Generation_Context ? $context->get_topic() : null;
		$label = isset($field_def['label']) ? $field_def['label'] : '';
		$instructions = isset($field_def['instructions']) ? $field_def['instructions'] : '';

		if (!empty($custom_prompt)) {
			$base_instruction = $this->template_processor->process($custom_prompt, $topic);
		} elseif (!empty($instructions)) {
			$base_instruction = $instructions;
		} else {
			$base_instruction = sprintf(
				/* translators: %s: field label. */
				__('Write the content for the "%s" field.', 'ai-post-scheduler'),
				$label
			);
		}

		$prompt = sprintf(
			/* translators: 1: field label, 2: generation instruction. */
			__("Field: %1\$s\n%2\$s", 'ai-post-scheduler'),
			$label,
			$base_instruction
		);

		return apply_filters('aips_integration_field_prompt', $prompt, $field_def, $context);
	}
}
