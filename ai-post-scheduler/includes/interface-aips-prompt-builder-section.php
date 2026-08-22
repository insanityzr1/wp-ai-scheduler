<?php
/**
 * Prompt Builder Section Interface
 *
 * Formal contract for all prompt-builder section classes that assemble
 * specific AI prompt components.
 *
 * @package AI_Post_Scheduler
 * @since 2.9.2
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Interface AIPS_Prompt_Builder_Section
 *
 * Enforces a shared build entrypoint for prompt builder section classes.
 */
interface AIPS_Prompt_Builder_Section {

	/**
	 * Build the section-specific prompt string.
	 *
	 * @param mixed ...$args Variable arguments specific to each prompt section implementation.
	 * @return string|WP_Error
	 */
	public function build(...$args);
}
