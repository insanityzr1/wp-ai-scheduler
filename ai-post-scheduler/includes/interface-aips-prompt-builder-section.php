<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Interface AIPS_Prompt_Builder_Section
 *
 * Shared type for the focused prompt-builder "section" classes that
 * AIPS_Prompt_Builder composes: topic, post content, post title, post excerpt,
 * post metadata, featured image, taxonomy, authors, and article structure.
 * Each section assembles one slice of the AI prompt and, by convention, exposes
 * a single public entry point named build() that returns the assembled prompt.
 *
 * Formalizing that de-facto convention behind a real type lets callers and the
 * aggregating AIPS_Prompt_Builder type-hint and instanceof-check sections
 * against one contract, and forces a new section class to opt in explicitly
 * (via `implements AIPS_Prompt_Builder_Section`) rather than diverging
 * silently. It mirrors the interface+implementors approach already used by
 * AIPS_Cache_Driver and AIPS_AI_Provider_Interface.
 *
 * Why build() is documented here but NOT declared as a typed method:
 *   The section builders intentionally take heterogeneous inputs - some accept
 *   a template/context object, others an array of post contents, a structure
 *   id, or an author - so their required argument counts AND parameter types
 *   genuinely differ (e.g. AIPS_Prompt_Builder_Authors::build(array $inputs,
 *   $count) versus AIPS_Prompt_Builder_Post_Content::build($template_or_context,
 *   $topic = null, $voice = null)). PHP has no single build() signature that
 *   every section could satisfy without either mis-typing the parameters or
 *   stripping the descriptive, type-hinted signatures the sections already
 *   have. Declaring an honest shared type while keeping each concrete signature
 *   (and its existing type safety) intact - with zero behavior change - is the
 *   deliberate trade made here. Every implementor still exposes build(); see
 *   each concrete class's own @return tag for its input and return contract
 *   (most return a prompt string; AIPS_Prompt_Builder_Article_Structure_Section
 *   may return a WP_Error).
 *
 * Deliberately excluded: AIPS_Prompt_Builder_Diversity_Injector is a helper the
 * sections consume (it exposes build_*_block() methods) rather than a
 * standalone prompt slice, so it does not implement this interface.
 *
 * @package AI_Post_Scheduler
 * @since   3.4.2
 */
interface AIPS_Prompt_Builder_Section {
}
