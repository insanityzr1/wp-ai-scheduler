<?php
/**
 * Content Admin Template
 *
 * Container for the Content admin page with three tab panels:
 *
 * Tab 1: Generated Posts  - @see templates/admin/tab-generated-posts.php
 * Tab 2: Partial Generations - @see templates/admin/tab-partial-generations.php
 * Tab 3: Pending Review      - @see templates/admin/tab-pending-review.php
 *
 * @package AI_Post_Scheduler
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
	exit;
}

/** @var AIPS_Generated_Posts_Controller $controller */

$active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'aips-generated-posts';
$valid_tabs = array('aips-generated-posts', 'aips-partial-generations', 'aips-pending-review', 'aips-content-indexer');
if ('content-indexer' === $active_tab || 'indexer' === $active_tab) {
	$active_tab = 'aips-content-indexer';
} elseif ('partial-generations' === $active_tab || 'partial' === $active_tab) {
	$active_tab = 'aips-partial-generations';
} elseif ('pending-review' === $active_tab || 'pending' === $active_tab) {
	$active_tab = 'aips-pending-review';
} elseif (!in_array($active_tab, $valid_tabs, true)) {
	$active_tab = 'aips-generated-posts';
}
?>

<div class="wrap aips-wrap aips-content-wrap">
	<div class="aips-page-container">
		<!-- Page Header -->
		<div class="aips-page-header">
			<div class="aips-page-header-top">
				<div>
					<h1 class="aips-page-title">
						<span class="dashicons dashicons-admin-post" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:8px;color:#2271b1;"></span>
						<?php esc_html_e('Content', 'ai-post-scheduler'); ?>
					</h1>
					<p class="aips-page-description"><?php esc_html_e('View and manage all AI-generated posts including published articles, drafts pending review, and semantic embeddings.', 'ai-post-scheduler'); ?></p>
				</div>
			</div>
		</div>

		<!-- Vertical Sidebar Rail Layout -->
		<div class="aips-rail-layout">
			<nav class="aips-rail-sidebar" aria-label="<?php esc_attr_e('Content Navigation', 'ai-post-scheduler'); ?>">
				<ul class="aips-rail-nav">
					<li>
						<button type="button" class="aips-rail-item<?php echo $active_tab === 'aips-generated-posts' ? ' active' : ''; ?>" data-tab="aips-generated-posts">
							<span class="dashicons dashicons-admin-post aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('Generated Posts', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Published & drafted articles', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
					<li>
						<button type="button" class="aips-rail-item<?php echo $active_tab === 'aips-partial-generations' ? ' active' : ''; ?>" data-tab="aips-partial-generations">
							<span class="dashicons dashicons-warning aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('Partial Generations', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Incomplete runs & recovery', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
					<li>
						<button type="button" class="aips-rail-item<?php echo $active_tab === 'aips-pending-review' ? ' active' : ''; ?>" data-tab="aips-pending-review">
							<span class="dashicons dashicons-visibility aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('Pending Review', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Drafts awaiting human review', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
					<li>
						<button type="button" class="aips-rail-item<?php echo $active_tab === 'aips-content-indexer' ? ' active' : ''; ?>" data-tab="aips-content-indexer">
							<span class="dashicons dashicons-database aips-rail-icon"></span>
							<span class="aips-rail-text">
								<span class="aips-rail-title"><?php esc_html_e('Content Indexer', 'ai-post-scheduler'); ?></span>
								<span class="aips-rail-desc"><?php esc_html_e('Vectors & semantic embeddings', 'ai-post-scheduler'); ?></span>
							</span>
							<span class="dashicons dashicons-arrow-right-alt2 aips-rail-arrow"></span>
						</button>
					</li>
				</ul>
			</nav>

			<main class="aips-rail-main">
				<!-- Tab 1: Generated Posts -->
				<div id="aips-generated-posts-tab" class="aips-tab-content<?php echo $active_tab === 'aips-generated-posts' ? ' active' : ''; ?>" style="<?php echo $active_tab === 'aips-generated-posts' ? '' : 'display:none;'; ?>" role="tabpanel" aria-hidden="<?php echo $active_tab === 'aips-generated-posts' ? 'false' : 'true'; ?>">
					<div class="aips-content-panel">
						<?php include AIPS_PLUGIN_DIR . 'templates/admin/tab-generated-posts.php'; ?>
					</div>
				</div>

				<!-- Tab 2: Partial Generations -->
				<div id="aips-partial-generations-tab" class="aips-tab-content<?php echo $active_tab === 'aips-partial-generations' ? ' active' : ''; ?>" style="<?php echo $active_tab === 'aips-partial-generations' ? '' : 'display:none;'; ?>" role="tabpanel" aria-hidden="<?php echo $active_tab === 'aips-partial-generations' ? 'false' : 'true'; ?>">
					<div class="aips-content-panel">
						<?php include AIPS_PLUGIN_DIR . 'templates/admin/tab-partial-generations.php'; ?>
					</div>
				</div>

				<!-- Tab 3: Pending Review -->
				<div id="aips-pending-review-tab" class="aips-tab-content<?php echo $active_tab === 'aips-pending-review' ? ' active' : ''; ?>" style="<?php echo $active_tab === 'aips-pending-review' ? '' : 'display:none;'; ?>" role="tabpanel" aria-hidden="<?php echo $active_tab === 'aips-pending-review' ? 'false' : 'true'; ?>">
					<div class="aips-content-panel">
						<?php include AIPS_PLUGIN_DIR . 'templates/admin/tab-pending-review.php'; ?>
					</div>
				</div>

				<!-- Tab 4: Content Indexer -->
				<div id="aips-content-indexer-tab" class="aips-tab-content<?php echo $active_tab === 'aips-content-indexer' ? ' active' : ''; ?>" style="<?php echo $active_tab === 'aips-content-indexer' ? '' : 'display:none;'; ?>" role="tabpanel" aria-hidden="<?php echo $active_tab === 'aips-content-indexer' ? 'false' : 'true'; ?>">
					<?php
					$indexer_controller = new AIPS_Content_Indexer_Controller();
					$indexer_controller->render_page();
					?>
				</div>
			</main>
		</div><!-- /.aips-rail-layout -->
	</div>
</div>

<?php
// Include the Post Preview modal partial
include AIPS_PLUGIN_DIR . 'templates/partials/post-preview-modal.php';

// Include the View Session modal partial
include AIPS_PLUGIN_DIR . 'templates/partials/view-session-modal.php';

// Include the AI Edit modal partial
include AIPS_PLUGIN_DIR . 'templates/partials/ai-edit-modal.php';
?>
