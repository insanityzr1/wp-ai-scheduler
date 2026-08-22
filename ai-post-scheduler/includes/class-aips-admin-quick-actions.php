<?php
/**
 * Admin Quick Actions
 *
 * Builds and renders the shared quick action bar shown on major admin pages.
 *
 * @package AI_Post_Scheduler
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class AIPS_Admin_Quick_Actions
 */
class AIPS_Admin_Quick_Actions {

	/**
	 * User-meta key for pinned quick actions.
	 */
	private const PINNED_META_KEY = 'aips_quick_actions_pinned';

	/**
	 * User-meta key for recent quick actions.
	 */
	private const RECENT_META_KEY = 'aips_quick_actions_recent';

	/**
	 * Form field name for quick-action mutations.
	 */
	private const FORM_ACTION = 'aips_quick_action_intent';

	/**
	 * Nonce action used by pin/unpin forms.
	 */
	private const NONCE_ACTION = 'aips_quick_action_update';

	/**
	 * Max stored pinned items per user.
	 */
	private const MAX_PINNED_ACTIONS = 8;

	/**
	 * Max stored recent items per user.
	 */
	private const MAX_RECENT_ACTIONS = 6;

	/**
	 * Render the quick action bar for the current admin request.
	 *
	 * @return void
	 */
	public static function render() {
		$instance   = new self();
		$view_model = $instance->build_view_model();

		if (empty($view_model['current'])) {
			return;
		}

		$current     = $view_model['current'];
		$major       = $view_model['major'];
		$context     = $view_model['context'];
		$recent      = $view_model['recent'];
		$pinned      = $view_model['pinned'];
		$return_url  = $view_model['return_url'];

		include AIPS_PLUGIN_DIR . 'templates/partials/admin-quick-actions.php';
	}

	/**
	 * Build the quick-action view model.
	 *
	 * @return array<string, mixed>
	 */
	private function build_view_model() {
		if (!is_admin() || !current_user_can('manage_options')) {
			return array();
		}

		$this->maybe_handle_form_submission();

		$current = $this->get_current_action();
		if (empty($current)) {
			return array();
		}

		$this->track_recent_action($current);

		$pinned = $this->get_pinned_actions();

		return array(
			'current'    => $this->mark_action_flags($current, $current, $pinned),
			'major'      => $this->mark_action_collection($this->get_major_actions(), $current, $pinned),
			'context'    => $this->mark_action_collection($this->get_context_actions(), $current, $pinned),
			'recent'     => $this->mark_action_collection($this->get_recent_actions($current), $current, $pinned),
			'pinned'     => $this->mark_action_collection($pinned, $current, $pinned),
			'return_url' => $current['url'],
		);
	}

	/**
	 * Handle pin/unpin form submissions and redirect back to the active page.
	 *
	 * @return void
	 */
	private function maybe_handle_form_submission() {
		if ('POST' !== strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '')) {
			return;
		}

		$intent = isset($_POST[ self::FORM_ACTION ]) ? sanitize_key(wp_unslash($_POST[ self::FORM_ACTION ])) : '';
		if (!in_array($intent, array('pin', 'unpin'), true)) {
			return;
		}

		check_admin_referer(self::NONCE_ACTION);

		$return_url = isset($_POST['aips_quick_action_return']) ? $this->sanitize_action_url(wp_unslash($_POST['aips_quick_action_return'])) : '';
		if (empty($return_url)) {
			$return_url = AIPS_Admin_Menu_Helper::get_page_url('dashboard');
		}

		$action = $this->sanitize_action_from_request($_POST);
		if (!empty($action)) {
			if ('pin' === $intent) {
				$this->store_pinned_action($action);
			} else {
				$this->remove_pinned_action($action['key']);
			}
		}

		wp_safe_redirect($return_url);
		exit;
	}

	/**
	 * Read the current page as a quick-action entry.
	 *
	 * @return array<string, string>
	 */
	private function get_current_action() {
		$page_slug = filter_input(INPUT_GET, 'page', FILTER_UNSAFE_RAW);
		$page_slug = is_string($page_slug) ? sanitize_key($page_slug) : '';

		if (empty($page_slug) || !AIPS_Admin_Menu_Helper::is_plugin_page_slug($page_slug)) {
			return array();
		}

		$page_key = AIPS_Admin_Menu_Helper::get_page_key_from_slug($page_slug);
		if (!in_array($page_key, AIPS_Admin_Menu_Helper::get_major_pages(), true)) {
			return array();
		}

		$args  = $this->get_current_page_args($page_key);
		$url   = $this->normalize_action_url(AIPS_Admin_Menu_Helper::get_page_url($page_key, $args));
		$label = $this->build_current_action_label($page_key);

		return $this->build_action(
			'current-' . $page_key . '-' . md5($url),
			$label,
			$url,
			AIPS_Admin_Menu_Helper::get_page_icon($page_key)
		);
	}

	/**
	 * Build the fixed major-page actions.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_major_actions() {
		$actions = array();

		foreach (AIPS_Admin_Menu_Helper::get_major_pages() as $page_key) {
			$args      = $this->get_default_page_args($page_key);
			$actions[] = $this->build_action(
				'major-' . $page_key,
				AIPS_Admin_Menu_Helper::get_page_label($page_key),
				AIPS_Admin_Menu_Helper::get_page_url($page_key, $args),
				AIPS_Admin_Menu_Helper::get_page_icon($page_key)
			);
		}

		return $actions;
	}

	/**
	 * Build entity-aware shortcuts for the current request.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_context_actions() {
		$actions     = array();
		$author_id   = filter_input(INPUT_GET, 'author_id', FILTER_VALIDATE_INT);
		$template_id = filter_input(INPUT_GET, 'template_id', FILTER_VALIDATE_INT);
		$topic_id    = filter_input(INPUT_GET, 'topic_id', FILTER_VALIDATE_INT);

		$author_id   = $author_id ? absint($author_id) : 0;
		$template_id = $template_id ? absint($template_id) : 0;
		$topic_id    = $topic_id ? absint($topic_id) : 0;

		if ($author_id > 0) {
			$author_name = $this->get_author_name($author_id);
			if (!empty($author_name)) {
				$actions[] = $this->build_action(
					'author-edit-' . $author_id,
					sprintf(
						/* translators: %s: author name */
						__('Edit %s', 'ai-post-scheduler'),
						$author_name
					),
					AIPS_Admin_Menu_Helper::get_page_url('authors', array('author_id' => $author_id)),
					AIPS_Admin_Menu_Helper::get_page_icon('authors')
				);
				$actions[] = $this->build_action(
					'author-topics-' . $author_id,
					sprintf(
						/* translators: %s: author name */
						__('Topics for %s', 'ai-post-scheduler'),
						$author_name
					),
					AIPS_Admin_Menu_Helper::get_page_url('author_topics', array('author_id' => $author_id)),
					AIPS_Admin_Menu_Helper::get_page_icon('author_topics')
				);
				$actions[] = $this->build_action(
					'author-content-' . $author_id,
					sprintf(
						/* translators: %s: author name */
						__('Content for %s', 'ai-post-scheduler'),
						$author_name
					),
					AIPS_Admin_Menu_Helper::get_page_url('generated_posts', array('author_id' => $author_id)),
					AIPS_Admin_Menu_Helper::get_page_icon('generated_posts')
				);
			}
		}

		if ($template_id > 0) {
			$template_name = $this->get_template_name($template_id);
			if (!empty($template_name)) {
				$actions[] = $this->build_action(
					'template-content-' . $template_id,
					sprintf(
						/* translators: %s: template name */
						__('Content from %s', 'ai-post-scheduler'),
						$template_name
					),
					AIPS_Admin_Menu_Helper::get_page_url('generated_posts', array('template_id' => $template_id)),
					AIPS_Admin_Menu_Helper::get_page_icon('generated_posts')
				);
				$actions[] = $this->build_action(
					'template-schedule-' . $template_id,
					sprintf(
						/* translators: %s: template name */
						__('Schedule %s', 'ai-post-scheduler'),
						$template_name
					),
					AIPS_Admin_Menu_Helper::get_page_url('schedule', array('schedule_template' => $template_id)),
					AIPS_Admin_Menu_Helper::get_page_icon('schedule')
				);
			}
		}

		if ($topic_id > 0 && $author_id > 0) {
			$topic_title = $this->get_topic_title($topic_id);
			if (!empty($topic_title)) {
				$actions[] = $this->build_action(
					'topic-' . $topic_id,
					sprintf(
						/* translators: %s: topic title */
						__('Topic: %s', 'ai-post-scheduler'),
						$topic_title
					),
					AIPS_Admin_Menu_Helper::get_page_url('author_topics', array('author_id' => $author_id)) . '#aips-topic-' . $topic_id,
					AIPS_Admin_Menu_Helper::get_page_icon('author_topics')
				);
			}
		}

		return $this->unique_actions($actions);
	}

	/**
	 * Return the user's recent quick actions, excluding the current page.
	 *
	 * @param array<string, string> $current_action Current page action.
	 * @return array<int, array<string, string>>
	 */
	private function get_recent_actions(array $current_action) {
		$recent = get_user_meta(get_current_user_id(), self::RECENT_META_KEY, true);
		$recent = is_array($recent) ? $recent : array();

		$actions = array();
		foreach ($recent as $item) {
			$action = $this->sanitize_stored_action($item);
			if (empty($action) || $action['url'] === $current_action['url']) {
				continue;
			}

			$actions[] = $action;
		}

		return array_slice($actions, 0, self::MAX_RECENT_ACTIONS);
	}

	/**
	 * Return the user's pinned quick actions.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_pinned_actions() {
		$pinned = get_user_meta(get_current_user_id(), self::PINNED_META_KEY, true);
		$pinned = is_array($pinned) ? $pinned : array();

		$actions = array();
		foreach ($pinned as $item) {
			$action = $this->sanitize_stored_action($item);
			if (!empty($action)) {
				$actions[] = $action;
			}
		}

		return array_slice($actions, 0, self::MAX_PINNED_ACTIONS);
	}

	/**
	 * Persist the current page into the recent list.
	 *
	 * @param array<string, string> $current_action Current page action.
	 * @return void
	 */
	private function track_recent_action(array $current_action) {
		$recent = $this->get_recent_actions(array('url' => ''));
		$recent = array_filter(
			$recent,
			static function($item) use ($current_action) {
				return $item['url'] !== $current_action['url'];
			}
		);

		array_unshift($recent, array_merge($current_action, array('tracked_at' => (string) AIPS_DateTime::now()->timestamp())));

		update_user_meta(
			get_current_user_id(),
			self::RECENT_META_KEY,
			array_values(array_slice($recent, 0, self::MAX_RECENT_ACTIONS))
		);
	}

	/**
	 * Save a new pinned action.
	 *
	 * @param array<string, string> $action Sanitized action.
	 * @return void
	 */
	private function store_pinned_action(array $action) {
		$pinned = $this->get_pinned_actions();
		$pinned = array_filter(
			$pinned,
			static function($item) use ($action) {
				return $item['key'] !== $action['key'];
			}
		);

		array_unshift($pinned, $action);

		update_user_meta(
			get_current_user_id(),
			self::PINNED_META_KEY,
			array_values(array_slice($pinned, 0, self::MAX_PINNED_ACTIONS))
		);
	}

	/**
	 * Remove a pinned action by key.
	 *
	 * @param string $key Action key.
	 * @return void
	 */
	private function remove_pinned_action($key) {
		$key    = sanitize_key((string) $key);
		$pinned = $this->get_pinned_actions();
		$pinned = array_filter(
			$pinned,
			static function($item) use ($key) {
				return $item['key'] !== $key;
			}
		);

		update_user_meta(get_current_user_id(), self::PINNED_META_KEY, array_values($pinned));
	}

	/**
	 * Build the current action label with tab/entity context.
	 *
	 * @param string $page_key Logical page key.
	 * @return string
	 */
	private function build_current_action_label($page_key) {
		$parts = array(AIPS_Admin_Menu_Helper::get_page_label($page_key));

		if ('automations' === $page_key) {
			$tab = AIPS_Automations_Controller::get_active_tab_key();
			$tabs = (new AIPS_Automations_Controller())->get_tabs($tab);
			if (!empty($tabs[ $tab ]['label'])) {
				$parts[] = $tabs[ $tab ]['label'];
			}
		}

		if ('diagnostics' === $page_key) {
			$tab  = AIPS_Diagnostics_Controller::get_active_tab_key();
			$tabs = (new AIPS_Diagnostics_Controller())->get_tabs();
			if (!empty($tabs[ $tab ]['label'])) {
				$parts[] = $tabs[ $tab ]['label'];
			}
		}

		$author_id = filter_input(INPUT_GET, 'author_id', FILTER_VALIDATE_INT);
		if ($author_id) {
			$author_name = $this->get_author_name(absint($author_id));
			if (!empty($author_name)) {
				$parts[] = $author_name;
			}
		}

		$template_id = filter_input(INPUT_GET, 'template_id', FILTER_VALIDATE_INT);
		if ($template_id) {
			$template_name = $this->get_template_name(absint($template_id));
			if (!empty($template_name)) {
				$parts[] = $template_name;
			}
		}

		$topic_id = filter_input(INPUT_GET, 'topic_id', FILTER_VALIDATE_INT);
		if ($topic_id) {
			$topic_title = $this->get_topic_title(absint($topic_id));
			if (!empty($topic_title)) {
				$parts[] = $topic_title;
			}
		}

		return implode(' · ', array_filter($parts));
	}

	/**
	 * Get stable, allowed query args for the current major page.
	 *
	 * @param string $page_key Logical page key.
	 * @return array<string, string|int>
	 */
	private function get_current_page_args($page_key) {
		$args = array();

		switch ($page_key) {
			case 'dashboard':
				$args = $this->get_request_args(array('date_from', 'date_to'));
				break;
			case 'automations':
				$args = $this->get_request_args(array('tab', 'author_id'));
				break;
			case 'diagnostics':
				$args = $this->get_request_args(array('tab'));
				break;
			case 'generated_posts':
				$args = $this->get_request_args(array('author_id', 'template_id', 'campaign_id', 's'));
				break;
			case 'history':
				$args = $this->get_request_args(array('status', 'domain', 'actor', 'date_from', 'date_to', 's'));
				break;
		}

		return $args;
	}

	/**
	 * Get the default quick-link args for a major page.
	 *
	 * @param string $page_key Logical page key.
	 * @return array<string, string|int>
	 */
	private function get_default_page_args($page_key) {
		if ('automations' === $page_key) {
			return array('tab' => AIPS_Automations_Controller::get_active_tab_key());
		}

		if ('diagnostics' === $page_key) {
			return array('tab' => AIPS_Diagnostics_Controller::get_active_tab_key());
		}

		return array();
	}

	/**
	 * Read selected request args with sanitization.
	 *
	 * @param string[] $keys Allowed request keys.
	 * @return array<string, string|int>
	 */
	private function get_request_args(array $keys) {
		$args = array();

		foreach ($keys as $key) {
			$value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
			if (null === $value || false === $value || '' === $value) {
				continue;
			}

			if (in_array($key, array('author_id', 'template_id', 'topic_id', 'campaign_id'), true)) {
				$value = absint($value);
			} elseif ('tab' === $key) {
				$value = sanitize_key($value);
			} else {
				$value = sanitize_text_field(wp_unslash($value));
			}

			if ('' !== $value && 0 !== $value) {
				$args[ $key ] = $value;
			}
		}

		return $args;
	}

	/**
	 * Build a normalized action array.
	 *
	 * @param string $key   Stable action key.
	 * @param string $label Display label.
	 * @param string $url   Target URL.
	 * @param string $icon  Dashicon class.
	 * @return array<string, string>
	 */
	private function build_action($key, $label, $url, $icon) {
		$url = $this->normalize_action_url($url);

		return array(
			'key'   => sanitize_key((string) $key),
			'label' => sanitize_text_field((string) $label),
			'url'   => $url,
			'icon'  => sanitize_html_class((string) $icon),
		);
	}

	/**
	 * Normalize and validate a plugin-admin action URL.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_action_url($url) {
		$url = $this->sanitize_action_url($url);

		return !empty($url) ? $url : AIPS_Admin_Menu_Helper::get_page_url('dashboard');
	}

	/**
	 * Sanitize a URL and ensure it points to a plugin admin page.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function sanitize_action_url($url) {
		$url = esc_url_raw((string) $url);
		if (empty($url)) {
			return '';
		}

		$query = wp_parse_url($url, PHP_URL_QUERY);
		if (!is_string($query) || '' === $query) {
			return '';
		}

		parse_str($query, $args);
		$page = isset($args['page']) ? sanitize_key((string) $args['page']) : '';

		if (empty($page) || !AIPS_Admin_Menu_Helper::is_plugin_page_slug($page)) {
			return '';
		}

		return remove_query_arg(
			array(self::FORM_ACTION, 'aips_quick_action_key', 'aips_quick_action_label', 'aips_quick_action_url', 'aips_quick_action_icon', 'aips_quick_action_return', '_wpnonce'),
			$url
		);
	}

	/**
	 * Sanitize an action payload from a POST request.
	 *
	 * @param array<string, mixed> $data Raw request data.
	 * @return array<string, string>
	 */
	private function sanitize_action_from_request(array $data) {
		$key   = isset($data['aips_quick_action_key']) ? sanitize_key(wp_unslash($data['aips_quick_action_key'])) : '';
		$label = isset($data['aips_quick_action_label']) ? sanitize_text_field(wp_unslash($data['aips_quick_action_label'])) : '';
		$url   = isset($data['aips_quick_action_url']) ? $this->sanitize_action_url(wp_unslash($data['aips_quick_action_url'])) : '';
		$icon  = isset($data['aips_quick_action_icon']) ? sanitize_html_class(wp_unslash($data['aips_quick_action_icon'])) : '';

		if (empty($key) || empty($label) || empty($url)) {
			return array();
		}

		return array(
			'key'   => $key,
			'label' => $label,
			'url'   => $url,
			'icon'  => !empty($icon) ? $icon : 'dashicons-admin-links',
		);
	}

	/**
	 * Sanitize an action loaded from user meta.
	 *
	 * @param mixed $item Raw meta item.
	 * @return array<string, string>
	 */
	private function sanitize_stored_action($item) {
		if (!is_array($item)) {
			return array();
		}

		return $this->sanitize_action_from_request(array(
			'aips_quick_action_key'   => isset($item['key']) ? $item['key'] : '',
			'aips_quick_action_label' => isset($item['label']) ? $item['label'] : '',
			'aips_quick_action_url'   => isset($item['url']) ? $item['url'] : '',
			'aips_quick_action_icon'  => isset($item['icon']) ? $item['icon'] : '',
		));
	}

	/**
	 * Add current/pinned flags to an action.
	 *
	 * @param array<string, string>               $action   Action item.
	 * @param array<string, string>               $current  Current page action.
	 * @param array<int, array<string, string>>   $pinned   Pinned action list.
	 * @return array<string, mixed>
	 */
	private function mark_action_flags(array $action, array $current, array $pinned) {
		$pinned_keys = wp_list_pluck($pinned, 'key');

		return array_merge(
			$action,
			array(
				'is_current' => $action['url'] === $current['url'],
				'is_pinned'  => in_array($action['key'], $pinned_keys, true),
			)
		);
	}

	/**
	 * Add current/pinned flags to an action collection.
	 *
	 * @param array<int, array<string, string>> $actions Action collection.
	 * @param array<string, string>             $current Current action.
	 * @param array<int, array<string, string>> $pinned  Pinned collection.
	 * @return array<int, array<string, mixed>>
	 */
	private function mark_action_collection(array $actions, array $current, array $pinned) {
		$marked = array();

		foreach ($actions as $action) {
			$marked[] = $this->mark_action_flags($action, $current, $pinned);
		}

		return $marked;
	}

	/**
	 * Remove duplicates from an action list by URL.
	 *
	 * @param array<int, array<string, string>> $actions Action collection.
	 * @return array<int, array<string, string>>
	 */
	private function unique_actions(array $actions) {
		$unique = array();
		$seen   = array();

		foreach ($actions as $action) {
			if (empty($action['url']) || isset($seen[ $action['url'] ])) {
				continue;
			}

			$seen[ $action['url'] ] = true;
			$unique[]               = $action;
		}

		return $unique;
	}

	/**
	 * Resolve an author name for contextual shortcuts.
	 *
	 * @param int $author_id Author ID.
	 * @return string
	 */
	private function get_author_name($author_id) {
		$author = (new AIPS_Authors_Repository())->get_by_id($author_id);

		return is_object($author) && !empty($author->name) ? sanitize_text_field($author->name) : '';
	}

	/**
	 * Resolve a template name for contextual shortcuts.
	 *
	 * @param int $template_id Template ID.
	 * @return string
	 */
	private function get_template_name($template_id) {
		$template = (new AIPS_Template_Repository())->get_by_id($template_id);

		return is_object($template) && !empty($template->name) ? sanitize_text_field($template->name) : '';
	}

	/**
	 * Resolve a topic title for contextual shortcuts.
	 *
	 * @param int $topic_id Topic ID.
	 * @return string
	 */
	private function get_topic_title($topic_id) {
		$topic = (new AIPS_Author_Topics_Repository())->get_by_id($topic_id);

		return is_object($topic) && !empty($topic->topic_title) ? sanitize_text_field($topic->topic_title) : '';
	}
}
