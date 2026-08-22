<?php
/**
 * Prompt Builder Section Base Class
 *
 * Abstract base class providing shared dependencies and container-backed
 * fallback resolution for prompt-builder section classes.
 *
 * @package AI_Post_Scheduler
 * @since 2.9.2
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Abstract Class AIPS_Prompt_Builder_Section_Base
 *
 * Base implementation for prompt builder sections.
 */
abstract class AIPS_Prompt_Builder_Section_Base implements AIPS_Prompt_Builder_Section {

	/**
	 * @var AIPS_Template_Processor Template processor for prompt variables.
	 */
	protected $template_processor;

	/**
	 * @var AIPS_Prompt_Builder_Diversity_Injector Diversity block builder.
	 */
	protected $diversity_injector;

	/**
	 * Constructor.
	 *
	 * @param AIPS_Template_Processor|null                $template_processor Optional template processor.
	 * @param AIPS_Prompt_Builder_Diversity_Injector|null $diversity_injector Optional diversity injector.
	 */
	public function __construct($template_processor = null, $diversity_injector = null) {
		$container = class_exists('AIPS_Container') ? AIPS_Container::get_instance() : null;

		if ($template_processor !== null) {
			$this->template_processor = $template_processor;
		} elseif ($container !== null && $container->has(AIPS_Template_Processor::class)) {
			$this->template_processor = $container->make(AIPS_Template_Processor::class);
		} else {
			$this->template_processor = new AIPS_Template_Processor();
		}

		if ($diversity_injector !== null) {
			$this->diversity_injector = $diversity_injector;
		} elseif ($container !== null && $container->has(AIPS_Prompt_Builder_Diversity_Injector::class)) {
			$this->diversity_injector = $container->make(AIPS_Prompt_Builder_Diversity_Injector::class);
		} else {
			$this->diversity_injector = new AIPS_Prompt_Builder_Diversity_Injector();
		}
	}
}
