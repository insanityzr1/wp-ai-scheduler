<?php
/**
 * Shared admin quick actions bar.
 *
 * @package AI_Post_Scheduler
 */

if (!defined('ABSPATH')) {
	exit;
}

$render_quick_action = static function($action) use ($return_url) {
	$item_classes = array('aips-quick-action-item');
	if (!empty($action['is_current'])) {
		$item_classes[] = 'is-current';
	}
	if (!empty($action['is_pinned'])) {
		$item_classes[] = 'is-pinned';
	}

	$pin_intent = !empty($action['is_pinned']) ? 'unpin' : 'pin';
	$pin_label  = !empty($action['is_pinned']) ? __('Unpin action', 'ai-post-scheduler') : __('Pin action', 'ai-post-scheduler');
	?>
	<li class="<?php echo esc_attr(implode(' ', $item_classes)); ?>">
		<a href="<?php echo esc_url($action['url']); ?>" class="aips-quick-action-link">
			<span class="dashicons <?php echo esc_attr($action['icon']); ?>" aria-hidden="true"></span>
			<span class="aips-quick-action-text"><?php echo esc_html($action['label']); ?></span>
		</a>
		<form method="post" class="aips-quick-action-pin-form">
			<?php wp_nonce_field('aips_quick_action_update'); ?>
			<input type="hidden" name="aips_quick_action_intent" value="<?php echo esc_attr($pin_intent); ?>">
			<input type="hidden" name="aips_quick_action_key" value="<?php echo esc_attr($action['key']); ?>">
			<input type="hidden" name="aips_quick_action_label" value="<?php echo esc_attr($action['label']); ?>">
			<input type="hidden" name="aips_quick_action_url" value="<?php echo esc_url($action['url']); ?>">
			<input type="hidden" name="aips_quick_action_icon" value="<?php echo esc_attr($action['icon']); ?>">
			<input type="hidden" name="aips_quick_action_return" value="<?php echo esc_url($return_url); ?>">
			<button type="submit" class="aips-quick-action-pin" aria-label="<?php echo esc_attr($pin_label); ?>">
				<span class="dashicons <?php echo !empty($action['is_pinned']) ? 'dashicons-star-filled' : 'dashicons-star-empty'; ?>" aria-hidden="true"></span>
			</button>
		</form>
	</li>
	<?php
};
?>
<div class="aips-quick-actions" aria-label="<?php esc_attr_e('Quick actions', 'ai-post-scheduler'); ?>">
	<div class="aips-quick-actions-header">
		<div>
			<h2 class="aips-quick-actions-title"><?php esc_html_e('Quick Actions', 'ai-post-scheduler'); ?></h2>
			<p class="aips-quick-actions-description"><?php esc_html_e('Jump between key workflows, pin repeat actions, and reopen recent views.', 'ai-post-scheduler'); ?></p>
		</div>
		<?php if (!empty($current['label'])) : ?>
			<span class="aips-quick-actions-current"><?php echo esc_html($current['label']); ?></span>
		<?php endif; ?>
	</div>

	<div class="aips-quick-actions-grid">
		<div class="aips-quick-actions-section">
			<h3 class="aips-quick-actions-section-title"><?php esc_html_e('Pages', 'ai-post-scheduler'); ?></h3>
			<ul class="aips-quick-action-list">
				<?php foreach ($major as $action) : ?>
					<?php $render_quick_action($action); ?>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="aips-quick-actions-section">
			<h3 class="aips-quick-actions-section-title"><?php esc_html_e('Context', 'ai-post-scheduler'); ?></h3>
			<?php if (!empty($context)) : ?>
				<ul class="aips-quick-action-list">
					<?php foreach ($context as $action) : ?>
						<?php $render_quick_action($action); ?>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="aips-quick-action-empty"><?php esc_html_e('Context links appear when you filter by author, template, or topic.', 'ai-post-scheduler'); ?></p>
			<?php endif; ?>
		</div>

		<div class="aips-quick-actions-section">
			<h3 class="aips-quick-actions-section-title"><?php esc_html_e('Pinned', 'ai-post-scheduler'); ?></h3>
			<?php if (!empty($pinned)) : ?>
				<ul class="aips-quick-action-list">
					<?php foreach ($pinned as $action) : ?>
						<?php $render_quick_action($action); ?>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="aips-quick-action-empty"><?php esc_html_e('Pin actions you use often to keep them here.', 'ai-post-scheduler'); ?></p>
			<?php endif; ?>
		</div>

		<div class="aips-quick-actions-section">
			<h3 class="aips-quick-actions-section-title"><?php esc_html_e('Recent', 'ai-post-scheduler'); ?></h3>
			<?php if (!empty($recent)) : ?>
				<ul class="aips-quick-action-list">
					<?php foreach ($recent as $action) : ?>
						<?php $render_quick_action($action); ?>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="aips-quick-action-empty"><?php esc_html_e('Recent views will appear here as you move between pages.', 'ai-post-scheduler'); ?></p>
			<?php endif; ?>
		</div>
	</div>
</div>
