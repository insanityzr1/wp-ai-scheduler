<?php
/**
 * Admin Error Fallback Partial
 *
 * Renders standardized error state fallback boxes for sections or controllers that encounter exceptions.
 *
 * @package AI_Post_Scheduler
 * @since   3.7.0
 *
 * @var array<string, mixed> $args
 */

if (!defined('ABSPATH')) {
	exit;
}

$title       = isset($args['title']) ? $args['title'] : __('Section Unavailable', 'ai-post-scheduler');
$message     = isset($args['message']) ? $args['message'] : __('An error occurred while loading this section. Please try again or check the system logs.', 'ai-post-scheduler');
$retry_url   = isset($args['retry_url']) ? $args['retry_url'] : '';
$retry_label = isset($args['retry_label']) ? $args['retry_label'] : __('Retry', 'ai-post-scheduler');
$details     = isset($args['details']) ? $args['details'] : '';
?>
<div class="notice notice-error aips-error-fallback" style="margin:20px 0;padding:16px;border-left:4px solid #dc3232;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.05);border-radius:4px;">
	<div style="display:flex;align-items:flex-start;gap:12px;">
		<span class="dashicons dashicons-warning" style="color:#dc3232;font-size:24px;width:24px;height:24px;margin-top:2px;"></span>
		<div style="flex:1;">
			<h4 style="margin:0 0 6px;font-size:15px;color:#1d2327;font-weight:600;"><?php echo esc_html($title); ?></h4>
			<p style="margin:0 0 10px;font-size:13px;color:#50575e;line-height:1.5;"><?php echo esc_html($message); ?></p>

			<?php if (!empty($details)) : ?>
				<details style="margin:8px 0 12px;background:#f6f7f7;padding:8px 12px;border-radius:4px;border:1px solid #dcdcde;">
					<summary style="cursor:pointer;font-size:12px;font-weight:600;color:#50575e;"><?php esc_html_e('Technical Details', 'ai-post-scheduler'); ?></summary>
					<pre style="margin:8px 0 0;font-size:11px;overflow:auto;max-height:160px;color:#2c3338;white-space:pre-wrap;"><?php echo esc_html($details); ?></pre>
				</details>
			<?php endif; ?>

			<?php if (!empty($retry_url)) : ?>
				<div style="margin-top:8px;">
					<a href="<?php echo esc_url($retry_url); ?>" class="aips-btn aips-btn-sm aips-btn-secondary">
						<span class="dashicons dashicons-update"></span>
						<?php echo esc_html($retry_label); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
