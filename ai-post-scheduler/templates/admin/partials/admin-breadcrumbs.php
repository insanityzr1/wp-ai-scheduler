<?php
/**
 * Admin Breadcrumbs Partial
 *
 * Renders an accessible, semantic breadcrumb trail for admin hubs and subviews.
 *
 * @package AI_Post_Scheduler
 * @since   3.7.0
 *
 * @var array<int, array{label:string, url?:string, icon?:string}> $args
 */

if (!defined('ABSPATH')) {
	exit;
}

$crumbs = is_array($args) ? $args : array();
if (empty($crumbs)) {
	return;
}

$total = count($crumbs);
?>
<nav class="aips-breadcrumb-nav" aria-label="<?php esc_attr_e('Breadcrumbs', 'ai-post-scheduler'); ?>">
	<ol class="aips-breadcrumb-trail">
		<?php foreach ($crumbs as $index => $crumb) : ?>
			<?php
			$is_first = (0 === $index);
			$is_last  = ($index === $total - 1);
			$label    = isset($crumb['label']) ? $crumb['label'] : '';
			$url      = isset($crumb['url']) ? $crumb['url'] : '';
			$icon     = isset($crumb['icon']) ? $crumb['icon'] : '';

			$item_classes = array('aips-breadcrumb-item');
			if ($is_first) {
				$item_classes[] = 'aips-breadcrumb-root';
			}
			if ($is_last) {
				$item_classes[] = 'aips-breadcrumb-current';
			}
			?>
			<li class="<?php echo esc_attr(implode(' ', $item_classes)); ?>"<?php echo $is_last ? ' aria-current="page"' : ''; ?>>
				<?php if (!empty($url) && !$is_last) : ?>
					<a href="<?php echo esc_url($url); ?>" class="aips-breadcrumb-link">
						<?php if (!empty($icon)) : ?>
							<span class="dashicons <?php echo esc_attr($icon); ?> aips-breadcrumb-icon" aria-hidden="true"></span>
						<?php endif; ?>
						<span><?php echo esc_html($label); ?></span>
					</a>
				<?php else : ?>
					<span class="aips-breadcrumb-text">
						<?php if (!empty($icon)) : ?>
							<span class="dashicons <?php echo esc_attr($icon); ?> aips-breadcrumb-icon" aria-hidden="true"></span>
						<?php endif; ?>
						<span><?php echo esc_html($label); ?></span>
					</span>
				<?php endif; ?>
				<?php if (!$is_last) : ?>
					<span class="aips-breadcrumb-sep" aria-hidden="true">/</span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</nav>
