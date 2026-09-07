<?php
if (!defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap aips-wrap">
	<div class="aips-page-container">
		<!-- Page Header -->
		<div class="aips-page-header">
			<div class="aips-page-header-top">
				<div>
					<h1 class="aips-page-title">
						<span class="dashicons dashicons-admin-settings" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:8px;color:#2271b1;"></span>
						<?php esc_html_e('Settings', 'ai-post-scheduler'); ?>
					</h1>
					<p class="aips-page-description"><?php esc_html_e('Configure plugin settings, check system status, and manage AI Engine connection.', 'ai-post-scheduler'); ?></p>
				</div>
			</div>
		</div>

		<!-- Vertical Sidebar Rail Layout -->
		<div class="aips-rail-layout">
			<!-- Rail Navigation Sidebar -->
			<nav class="aips-rail-sidebar" id="aips-settings-tab-nav" aria-label="<?php esc_attr_e('Settings Navigation', 'ai-post-scheduler'); ?>">
				<ul class="aips-rail-nav">
					<li>
						<button type="button" class="aips-rail-item active" data-tab="settings-general">
							<span class="dashicons dashicons-admin-generic aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('General', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Defaults & post settings', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
					<li>
						<button type="button" class="aips-rail-item" data-tab="settings-ai">
							<span class="dashicons dashicons-rest-api aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('AI Engine', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Models & AI connection', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
					<li>
						<button type="button" class="aips-rail-item" data-tab="settings-feedback">
							<span class="dashicons dashicons-thumbs-up aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('Feedback', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Deduplication & scoring', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
					<li>
						<button type="button" class="aips-rail-item" data-tab="settings-notifications">
							<span class="dashicons dashicons-email-alt aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('Notifications', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Email & alert channels', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
					<li>
						<button type="button" class="aips-rail-item" data-tab="settings-resilience">
							<span class="dashicons dashicons-shield aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('Resilience & Limits', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Failover & circuit breaker', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
					<li>
						<button type="button" class="aips-rail-item" data-tab="settings-content-strategy">
							<span class="dashicons dashicons-art aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('Content Strategy', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Brand voice & persona', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
					<li>
						<button type="button" class="aips-rail-item" data-tab="settings-cache">
							<span class="dashicons dashicons-performance aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('Performance', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Caching layer & driver', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
					<li>
						<button type="button" class="aips-rail-item" data-tab="settings-api-keys">
							<span class="dashicons dashicons-admin-network aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('API Keys', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Third-party credentials', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
					<li>
						<button type="button" class="aips-rail-item" data-tab="settings-developers">
							<span class="dashicons dashicons-editor-code aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('Developers', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Debug & dev tools', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
				</ul>
			</nav>

			<!-- Main Stage Area -->
			<main class="aips-rail-main">
				<div class="aips-content-panel">
					<div class="aips-panel-body">
						<form method="post" action="options.php" id="aips-settings-form">
							<?php settings_fields('aips_settings'); ?>

							<!-- General Tab -->
							<div id="settings-general-tab" class="aips-tab-content active">
								<p class="description"><?php esc_html_e('Configure default settings for AI-generated posts.', 'ai-post-scheduler'); ?></p>
								<table class="form-table" role="presentation">
									<?php do_settings_fields('aips-settings', 'aips_general_section'); ?>
								</table>
								<p class="submit">
									<input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'ai-post-scheduler'); ?>">
								</p>
							</div>

							<!-- AI Tab -->
							<div id="settings-ai-tab" class="aips-tab-content" style="display:none;">
								<p class="description"><?php esc_html_e('Configure the AI Engine model and environment used for content generation.', 'ai-post-scheduler'); ?></p>
								<table class="form-table" role="presentation">
									<?php do_settings_fields('aips-settings', 'aips_ai_section'); ?>
								</table>
								<p class="submit">
									<input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'ai-post-scheduler'); ?>">
								</p>
							</div>

							<!-- Feedback Tab -->
							<div id="settings-feedback-tab" class="aips-tab-content" style="display:none;">
								<p class="description"><?php esc_html_e('Configure how the plugin evaluates and deduplicates generated topic suggestions.', 'ai-post-scheduler'); ?></p>
								<table class="form-table" role="presentation">
									<?php do_settings_fields('aips-settings', 'aips_feedback_section'); ?>
								</table>
								<p class="submit">
									<input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'ai-post-scheduler'); ?>">
								</p>
							</div>

							<!-- Notifications Tab -->
							<div id="settings-notifications-tab" class="aips-tab-content" style="display:none;">
								<p class="description"><?php esc_html_e('Configure the notification email address and delivery channels for all plugin notifications.', 'ai-post-scheduler'); ?></p>
								<table class="form-table" role="presentation">
									<?php do_settings_fields('aips-settings', 'aips_notifications_section'); ?>
								</table>
								<p class="submit">
									<input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'ai-post-scheduler'); ?>">
								</p>
							</div>

							<!-- Resilience & Limits Tab -->
							<div id="settings-resilience-tab" class="aips-tab-content" style="display:none;">
								<p class="description"><?php esc_html_e('Configure advanced resilience options to protect the application from failing and being blocked when external services return errors.', 'ai-post-scheduler'); ?></p>
								<table class="form-table" role="presentation">
									<?php do_settings_fields('aips-settings', 'aips_resilience_section'); ?>
								</table>
								<p class="submit">
									<input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'ai-post-scheduler'); ?>">
								</p>
							</div>

							<!-- Content Strategy Tab -->
							<div id="settings-content-strategy-tab" class="aips-tab-content" style="display:none;">
								<p class="description"><?php esc_html_e('Define the overall content identity of your website. These settings are shared across Author Suggestions, topic generation, and post generation to ensure consistent, on-brand output.', 'ai-post-scheduler'); ?></p>
								<table class="form-table" role="presentation">
									<?php do_settings_fields('aips-settings', 'aips_content_strategy_section'); ?>
								</table>
								<p class="submit">
									<input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'ai-post-scheduler'); ?>">
								</p>
							</div>

							<!-- Performance Tab -->
							<div id="settings-cache-tab" class="aips-tab-content" style="display:none;">
								<p class="description"><?php esc_html_e('Configure performance-related options for the plugin, including the internal cache layer used to speed up database reads, template processing, and scheduled operations.', 'ai-post-scheduler'); ?></p>

								<h3><?php esc_html_e('Cache System', 'ai-post-scheduler'); ?></h3>
								<table class="form-table" role="presentation" id="aips-cache-settings-table">
									<?php do_settings_fields('aips-settings', 'aips_cache_section'); ?>
								</table>
								<p class="submit">
									<input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'ai-post-scheduler'); ?>">
								</p>
							</div>

							<!-- API Keys Tab -->
							<div id="settings-api-keys-tab" class="aips-tab-content" style="display:none;">
								<p class="description"><?php esc_html_e('Enter API keys for third-party services used by the plugin.', 'ai-post-scheduler'); ?></p>
								<table class="form-table" role="presentation">
									<?php do_settings_fields('aips-settings', 'aips_api_keys_section'); ?>
								</table>
								<p class="submit">
									<input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'ai-post-scheduler'); ?>">
								</p>
							</div>

							<!-- Developers Tab -->
							<div id="settings-developers-tab" class="aips-tab-content" style="display:none;">
								<p class="description"><?php esc_html_e('Options for debugging and plugin development. Not recommended for production use.', 'ai-post-scheduler'); ?></p>
								<table class="form-table" role="presentation">
									<?php do_settings_fields('aips-settings', 'aips_developers_section'); ?>
								</table>
								<p class="submit">
									<input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'ai-post-scheduler'); ?>">
								</p>
							</div>

						</form>
					</div>
				</div>
			</main>
		</div>

	</div>
</div>
