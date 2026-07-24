<?php
/**
 * Integration Event Handler
 *
 * Binds the third-party plugin bridge (AIPS_Integration_Manager) to the
 * WordPress hooks that fire during generation. Mirrors
 * AIPS_Notifications_Event_Handler's declarative hook-bindings pattern.
 *
 * @package AI_Post_Scheduler
 * @since 2.10.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class AIPS_Integration_Event_Handler {

	/**
	 * @var AIPS_Integration_Manager
	 */
	private $manager;

	/**
	 * Tracks whether the WordPress action hooks have been registered by any
	 * instance so that multiple instantiations do not register duplicate handlers.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * @param AIPS_Integration_Manager $manager The integration manager to dispatch to.
	 */
	public function __construct($manager) {
		$this->manager = $manager;
		$this->register_hooks();
	}

	/**
	 * Declarative list of WordPress action hooks this handler binds to.
	 *
	 * @return array<int, array{hook: string, method: string, priority?: int, accepted_args?: int}>
	 */
	public static function get_hook_bindings() {
		$bindings = array(
			array(
				'hook'          => 'aips_post_generated',
				'method'        => 'handle_post_generated',
				'priority'      => 10,
				'accepted_args' => 4,
			),
			array(
				'hook'          => 'aips_template_changed',
				'method'        => 'handle_template_deleted',
				'priority'      => 10,
				'accepted_args' => 1,
			),
		);

		/**
		 * Filter the list of WordPress action hooks AIPS_Integration_Event_Handler
		 * registers automatically. Each item is an associative array with
		 * 'hook', 'method', optional 'priority', and optional 'accepted_args'.
		 *
		 * @since 2.10.0
		 * @param array $bindings Current list of hook binding maps.
		 */
		return apply_filters('aips_integration_hook_bindings', $bindings);
	}

	/**
	 * Register WordPress action hooks for all declared bindings.
	 *
	 * @return void
	 */
	private function register_hooks() {
		if (self::$hooks_registered) {
			return;
		}
		self::$hooks_registered = true;

		foreach (self::get_hook_bindings() as $binding) {
			if (empty($binding['hook']) || empty($binding['method'])) {
				continue;
			}

			if (!method_exists($this, $binding['method'])) {
				continue;
			}

			$priority      = isset($binding['priority'])      ? (int) $binding['priority']      : 10;
			$accepted_args = isset($binding['accepted_args']) ? (int) $binding['accepted_args'] : 1;

			add_action($binding['hook'], array($this, $binding['method']), $priority, $accepted_args);
		}
	}

	/**
	 * Delegate 'aips_post_generated' to the integration manager.
	 *
	 * @param int                     $post_id             Generated post ID.
	 * @param object                  $template_or_context Template object or context.
	 * @param int|string|null         $history_id           History entry ID.
	 * @param AIPS_Generation_Context $context              Generation context.
	 * @return void
	 */
	public function handle_post_generated($post_id, $template_or_context, $history_id, $context) {
		$this->manager->handle_post_generated($post_id, $template_or_context, $history_id, $context);
	}

	/**
	 * Delegate 'aips_template_changed' to the integration manager, which
	 * cleans up field mappings when their owning Template is deleted.
	 *
	 * @param array $args Event payload (see AIPS_Integration_Manager::handle_template_deleted()).
	 * @return void
	 */
	public function handle_template_deleted($args) {
		$this->manager->handle_template_deleted($args);
	}
}
