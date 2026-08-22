<?php
/**
 * Admin Menu Helper
 *
 * Provides a centralized way to generate URLs for admin pages to prevent
 * hardcoding admin menu page slugs throughout the application.
 *
 * @package AI_Post_Scheduler
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class AIPS_Admin_Menu_Helper
 *
 * Helper class for generating admin URLs.
 */
class AIPS_Admin_Menu_Helper {

	/**
	 * Major admin pages that should share persistent quick navigation.
	 *
	 * @var string[]
	 */
	private static $major_pages = array(
		'dashboard',
		'automations',
		'diagnostics',
		'generated_posts',
		'history',
	);

	/**
	 * Map of logical page names to their actual slugs.
	 *
	 * @var array<string, string>
	 */
	private static $page_slugs = array(
		'dashboard'            => 'ai-post-scheduler',
		'templates'            => 'aips-templates',
		'authors'              => 'aips-authors',
		'post_slices'          => 'aips-post-slices',
		'schedule'             => 'aips-schedule',
		'campaigns'            => 'aips-campaigns',
		'campaign_wizard'      => 'aips-campaign-wizard',
		'campaign_detail'      => 'aips-campaign-detail',
		'generated_posts'      => 'aips-generated-posts',
		'author_topics'        => 'aips-author-topics',
		'system_status'        => 'aips-status',
		'operations_insights'   => 'aips-operations-insights',
		'telemetry'            => 'aips-telemetry',
		'settings'             => 'aips-settings',
		'onboarding'           => 'aips-onboarding',
		'history'              => 'aips-history',
		'seeder'               => 'aips-seeder',
		'dev_tools'            => 'aips-dev-tools',
		'diagnostics'          => 'aips-diagnostics',
		'automations'          => 'aips-automations',
		'research'             => 'aips-research',
	);

	/**
	 * Map of logical diagnostics page names to Diagnostics tabs.
	 *
	 * @var array<string, string>
	 */
	private static $diagnostics_tabs = array(
		'system_status'      => 'status',
		'seeder'             => 'seeder',
		'operations_insights' => 'operations-insights',
		'telemetry'          => 'telemetry',
		'dev_tools'          => 'dev-tools',
	);

	/**
	 * Map of logical automation page names to Automations tabs.
	 *
	 * @var array<string, string>
	 */
	private static $automations_tabs = array(
		'schedule'       => 'schedules',
		'campaigns'      => 'campaigns',
		'templates'      => 'templates',
		'authors'        => 'authors',
		'author_topics'  => 'author-topics',
		'sources'        => 'sources',
		'internal_links' => 'internal-links',
		'taxonomy'       => 'taxonomy',
	);

	/**
	 * Get the admin URL for a specific plugin page.
	 *
	 * @param string $page The logical name of the page (e.g., 'dashboard', 'templates', 'schedule').
	 * @param array<string, string|int> $args Optional query arguments to append to the URL.
	 * @return string The escaped admin URL.
	 */
	public static function get_page_url($page, $args = array()) {
		if (isset(self::$diagnostics_tabs[$page])) {
			$args = array_merge(array('tab' => self::$diagnostics_tabs[$page]), $args);
			$url  = admin_url('admin.php?page=aips-diagnostics');

			if (!empty($args)) {
				$url = add_query_arg($args, $url);
			}

			return $url;
		}

		if (isset(self::$automations_tabs[$page])) {
			$args = array_merge(array('tab' => self::$automations_tabs[$page]), $args);
			$url  = admin_url('admin.php?page=aips-automations');

			if (!empty($args)) {
				$url = add_query_arg($args, $url);
			}

			return $url;
		}

		if (!isset(self::$page_slugs[$page])) {
			// Fallback to the provided slug if it's not in our map
			$slug = $page;
		} else {
			$slug = self::$page_slugs[$page];
		}

		$url = admin_url('admin.php?page=' . $slug);

		if (!empty($args)) {
			$url = add_query_arg($args, $url);
		}

		return $url;
	}

	/**
	 * Return the actual slug for a given page name.
	 *
	 * @param string $page Logical page name.
	 * @return string The registered slug.
	 */
	public static function get_slug($page) {
		return isset(self::$page_slugs[$page]) ? self::$page_slugs[$page] : $page;
	}

	/**
	 * Return the logical page key for a registered admin slug.
	 *
	 * @param string $slug Admin page slug.
	 * @return string
	 */
	public static function get_page_key_from_slug($slug) {
		$slug = sanitize_key((string) $slug);

		foreach (self::$page_slugs as $page => $mapped_slug) {
			if ($mapped_slug === $slug) {
				return $page;
			}
		}

		return $slug;
	}

	/**
	 * Determine whether the provided slug belongs to this plugin.
	 *
	 * @param string $slug Admin page slug.
	 * @return bool
	 */
	public static function is_plugin_page_slug($slug) {
		$slug = (string) $slug;

		return 'ai-post-scheduler' === $slug || 0 === strpos($slug, 'aips-');
	}

	/**
	 * Get the standard label for a logical page.
	 *
	 * @param string $page Logical page name.
	 * @return string
	 */
	public static function get_page_label($page) {
		switch ($page) {
			case 'dashboard':
				return __('Dashboard', 'ai-post-scheduler');
			case 'automations':
				return __('Automations', 'ai-post-scheduler');
			case 'diagnostics':
				return __('Diagnostics', 'ai-post-scheduler');
			case 'generated_posts':
				return __('Content', 'ai-post-scheduler');
			case 'history':
				return __('History', 'ai-post-scheduler');
			case 'authors':
				return __('Authors', 'ai-post-scheduler');
			case 'author_topics':
				return __('Author Topics', 'ai-post-scheduler');
			case 'templates':
				return __('Templates', 'ai-post-scheduler');
			case 'schedule':
				return __('Schedules', 'ai-post-scheduler');
			default:
				return ucwords(str_replace(array('-', '_'), ' ', (string) $page));
		}
	}

	/**
	 * Get the standard Dashicon class for a logical page.
	 *
	 * @param string $page Logical page name.
	 * @return string
	 */
	public static function get_page_icon($page) {
		switch ($page) {
			case 'dashboard':
				return 'dashicons-chart-bar';
			case 'automations':
				return 'dashicons-controls-repeat';
			case 'diagnostics':
				return 'dashicons-admin-tools';
			case 'generated_posts':
				return 'dashicons-admin-post';
			case 'history':
				return 'dashicons-backup';
			case 'authors':
				return 'dashicons-admin-users';
			case 'author_topics':
				return 'dashicons-lightbulb';
			case 'templates':
				return 'dashicons-media-document';
			case 'schedule':
				return 'dashicons-calendar-alt';
			default:
				return 'dashicons-admin-links';
		}
	}

	/**
	 * Return the shared major-page registry.
	 *
	 * @return string[]
	 */
	public static function get_major_pages() {
		return self::$major_pages;
	}
}
