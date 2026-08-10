<?php
/**
 * Lightweight bootstrap for database-free unit tests.
 *
 * This intentionally implements only the WordPress contracts exercised by
 * pure generation policy/value-object tests. Full integration tests continue
 * to use tests/bootstrap.php and the canonical WordPress test library.
 *
 * @package AI_Post_Scheduler
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(function($class) {
	$base = dirname(__DIR__) . '/includes/';
	if (0 === strpos($class, 'AIPS_')) {
		$file = $base . 'class-' . strtolower(str_replace('_', '-', $class)) . '.php';
		if (is_readable($file)) {
			require_once $file;
		}
	}
});

if (!defined('ABSPATH')) {
	define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('MINUTE_IN_SECONDS')) {
	define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
	define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
}

class WP_UnitTestCase extends PHPUnit\Framework\TestCase {}

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct($code = '', $message = '', $data = null) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class AIPS_DateTime {
	public static function now() {
		return new class {
			public function timestamp() {
				return time();
			}
		};
	}
}

class AIPS_Correlation_ID {
	public static function get() {
		return 'unit-correlation';
	}
}

class AIPS_Post_Manager {
	const META_ORIGINAL_POST_STATUS       = '_aips_original_post_status';
	const META_POST_GENERATION_TOTAL_TIME = '_aips_post_generation_total_time';
}

class AIPS_Container {
	public static function get_instance() {
		return new self();
	}

	public function has($class) {
		return false;
	}
}

$GLOBALS['aips_unit_filters'] = array();
$GLOBALS['aips_unit_nonce_valid'] = true;
$GLOBALS['aips_unit_can_manage'] = true;

function __($text, $domain = null) {
	return $text;
}

function __return_zero() {
	return 0;
}

function add_filter($hook, $callback) {
	$GLOBALS['aips_unit_filters'][$hook][] = $callback;
	return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
	return true;
}

function check_ajax_referer($action, $query_arg = false, $die = true) {
	return $GLOBALS['aips_unit_nonce_valid'];
}

function current_user_can($capability) {
	return $GLOBALS['aips_unit_can_manage'];
}

function wp_unslash($value) {
	return $value;
}

function remove_filter($hook, $callback) {
	if (empty($GLOBALS['aips_unit_filters'][$hook])) {
		return false;
	}
	$GLOBALS['aips_unit_filters'][$hook] = array_values(array_filter(
		$GLOBALS['aips_unit_filters'][$hook],
		function($registered) use ($callback) {
			return $registered !== $callback;
		}
	));
	return true;
}

function remove_all_filters($hook) {
	unset($GLOBALS['aips_unit_filters'][$hook]);
	return true;
}

function apply_filters($hook, $value, ...$args) {
	foreach ($GLOBALS['aips_unit_filters'][$hook] ?? array() as $callback) {
		$value = $callback($value, ...$args);
	}
	return $value;
}

function wp_rand($min, $max) {
	return $min;
}

function wp_generate_uuid4() {
	return '00000000-0000-4000-8000-000000000001';
}

function wp_json_encode($value) {
	return json_encode($value);
}

function absint($value) {
	return abs((int) $value);
}

function sanitize_key($value) {
	return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function sanitize_text_field($value) {
	return trim(strip_tags((string) $value));
}

function is_wp_error($value) {
	return $value instanceof WP_Error;
}

function update_post_meta($post_id, $key, $value) {
	return true;
}

function get_post($post_id) {
	return (object) array('ID' => $post_id, 'post_status' => 'publish');
}

function wp_update_post($postarr) {
	return isset($postarr['ID']) ? (int) $postarr['ID'] : 0;
}

function wp_delete_post($post_id, $force_delete = false) {
	return true;
}

function get_edit_post_link($post_id, $context = 'display') {
	return '/edit.php?post=' . (int) $post_id;
}

function esc_url_raw($url) {
	return (string) $url;
}
