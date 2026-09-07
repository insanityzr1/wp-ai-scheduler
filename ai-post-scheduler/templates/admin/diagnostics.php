<?php
/**
 * Diagnostics Admin Template
 *
 * @package AI_Post_Scheduler
 */

if (!defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap aips-wrap aips-diagnostics-wrap">
	<div class="aips-page-container">
		<div class="aips-page-header">
			<div class="aips-page-header-top">
				<div>
					<h1 class="aips-page-title">
						<span class="dashicons dashicons-admin-tools aips-page-title-icon"></span>
						<?php esc_html_e('Diagnostics', 'ai-post-scheduler'); ?>
					</h1>
					<p class="aips-page-description"><?php esc_html_e('Review system health, generation operations, telemetry, seeding utilities, and developer tools from one place.', 'ai-post-scheduler'); ?></p>
				</div>
			</div>
		</div>

		<!-- Vertical Sidebar Rail Layout -->
		<div class="aips-rail-layout">
			<nav class="aips-rail-sidebar" aria-label="<?php esc_attr_e('Diagnostics Navigation', 'ai-post-scheduler'); ?>">
				<ul class="aips-rail-nav">
					<?php foreach ($tabs as $tab_key => $tab) : ?>
						<?php
						$is_active = ($active_tab === $tab_key);
						$item_classes = 'aips-rail-item' . ($is_active ? ' active' : '');
						$tab_icon = !empty($tab['icon']) ? $tab['icon'] : 'dashicons-admin-generic';
						$tab_desc = !empty($tab['description']) ? $tab['description'] : '';
						?>
						<li>
							<a href="<?php echo esc_url($diagnostics_controller->get_tab_url($tab_key)); ?>" class="<?php echo esc_attr($item_classes); ?>" role="tab" aria-selected="<?php echo esc_attr($is_active ? 'true' : 'false'); ?>">
								<span class="dashicons <?php echo esc_attr($tab_icon); ?> aips-rail-icon"></span>
								<span class="aips-rail-text">
									<span class="aips-rail-title"><?php echo esc_html($tab['label']); ?></span>
									<?php if ($tab_desc) : ?>
										<span class="aips-rail-desc"><?php echo esc_html($tab_desc); ?></span>
									<?php endif; ?>
								</span>
								<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>

			<main class="aips-rail-main">
				<div class="aips-diagnostics-stage">
					<?php $diagnostics_controller->render_tab_content($active_tab); ?>
				</div>
			</main>
		</div><!-- /.aips-rail-layout -->
	</div>
</div>
