<?php
/**
 * Admin Hub Shell Partial
 *
 * Wraps an admin hub page with consistent container, header, and rail layout.
 *
 * @package AI_Post_Scheduler
 * @since   3.7.0
 *
 * @var array<string, mixed> $args
 * @var callable|null        $content_callback
 */

if (!defined('ABSPATH')) {
	exit;
}

$wrap_class = isset($args['wrap_class']) ? $args['wrap_class'] : 'wrap aips-wrap';
$header_args = isset($args['header']) ? (array) $args['header'] : array();
$rail_args   = isset($args['rail']) ? (array) $args['rail'] : array();
$content     = isset($args['content']) ? $args['content'] : '';
$callback    = isset($args['content_callback']) && is_callable($args['content_callback'])
	? $args['content_callback']
	: $content_callback;
?>
<div class="<?php echo esc_attr($wrap_class); ?>">
	<div class="aips-page-container">
		<?php
		if (!empty($header_args)) {
			AIPS_Admin_UI_Primitives::render_page_header($header_args);
		}
		?>

		<?php if (!empty($rail_args['items'])) : ?>
			<div class="aips-rail-layout">
				<?php AIPS_Admin_UI_Primitives::render_rail($rail_args); ?>

				<main class="aips-rail-main">
					<?php
					if (is_callable($callback)) {
						call_user_func($callback);
					} else {
						echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</main>
			</div>
		<?php else : ?>
			<div class="aips-page-main-stage">
				<?php
				if (is_callable($callback)) {
					call_user_func($callback);
				} else {
					echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
		<?php endif; ?>
	</div>
</div>
