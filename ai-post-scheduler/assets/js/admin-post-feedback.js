(function ($) {
	'use strict';
	var cfg = window.aipsPostFeedback || {};
	var pendingReaction = '';
	function notice(message, type) {
		if (window.AIPS && AIPS.Utilities && AIPS.Utilities.showToast) { AIPS.Utilities.showToast(message, type || 'success'); }
	}
	function reasons(reaction) {
		var labels = (cfg.reasons && cfg.reasons[reaction]) || {};
		return '<option value="">' + (cfg.noReason || 'No reason') + '</option>' + Object.keys(labels).map(function (key) { return '<option value="' + key + '">' + labels[key] + '</option>'; }).join('');
	}
	function setState($root, reaction) {
		$root.attr('data-reaction', reaction || '');
		$root.find('.aips-post-feedback-reaction').each(function () { $(this).attr('aria-pressed', $(this).data('reaction') === reaction ? 'true' : 'false'); });
		$root.find('.aips-post-feedback-clear').prop('hidden', !reaction);
		$root.find('.aips-post-feedback-dialog').prop('hidden', true);
	}
	function request(data) { return $.post(cfg.ajaxUrl, $.extend({ nonce: cfg.nonce }, data)); }
	$(document).on('click', '.aips-post-feedback-reaction', function () {
		var $root = $(this).closest('.aips-post-feedback-controls');
		pendingReaction = $(this).data('reaction');
		$root.find('.aips-post-feedback-reason').html(reasons(pendingReaction));
		$root.find('.aips-post-feedback-dialog').prop('hidden', false).find('select').trigger('focus');
	});
	$(document).on('click', '.aips-post-feedback-save', function () {
		var $root = $(this).closest('.aips-post-feedback-controls');
		$root.addClass('is-loading').find('button,select,textarea').prop('disabled', true);
		request({ action: 'aips_post_feedback_set', post_id: $root.data('post-id'), reaction: pendingReaction, reason_category: $root.find('.aips-post-feedback-reason').val(), comment: $root.find('.aips-post-feedback-comment').val() })
			.done(function (response) { if (response.success) { setState($root, pendingReaction); notice(cfg.saved || 'Feedback saved.'); } else { notice(response.data.message || cfg.error, 'error'); } })
			.fail(function () { notice(cfg.error || 'Could not save feedback.', 'error'); })
			.always(function () { $root.removeClass('is-loading').find('button,select,textarea').prop('disabled', false); });
	});
	$(document).on('click', '.aips-post-feedback-clear', function () {
		var $root = $(this).closest('.aips-post-feedback-controls');
		request({ action: 'aips_post_feedback_clear', post_id: $root.data('post-id') }).done(function (response) { if (response.success) { setState($root, ''); notice(cfg.cleared || 'Feedback cleared.'); } });
	});
	$(document).on('click', '.aips-post-feedback-cancel', function () { $(this).closest('.aips-post-feedback-dialog').prop('hidden', true); });
	$(document).on('keydown', '.aips-post-feedback-dialog', function (event) { if (event.key === 'Escape') { $(this).prop('hidden', true); } });
	$(document).on('click', '.aips-feedback-overrides-toggle', function () { var $panel = $(this).siblings('.aips-feedback-overrides'); var open = $panel.prop('hidden'); $panel.prop('hidden', !open); $(this).attr('aria-expanded', open ? 'true' : 'false'); });
	$(document).on('click', '#aips-post-feedback-bulk-apply', function () {
		var ids = $('.aips-generated-post-checkbox:checked').map(function () { return $(this).val(); }).get();
		var reaction = $('#aips-post-feedback-bulk-action').val();
		if (!ids.length || !reaction) { notice(cfg.selectPosts || 'Select posts and an action.', 'warning'); return; }
		request({ action: 'aips_post_feedback_bulk', post_ids: ids, reaction: reaction }).done(function (response) {
			if (!response.success) { notice(response.data.message || cfg.error, 'error'); return; }
			(response.data.succeeded || []).forEach(function (id) { setState($('.aips-post-feedback-controls[data-post-id="' + id + '"]'), reaction === 'cleared' ? '' : reaction); });
			var failed = Object.keys(response.data.failed || {}).length;
			notice(failed ? (cfg.partial || 'Some posts could not be updated.') : (cfg.saved || 'Feedback saved.'), failed ? 'warning' : 'success');
		});
	});
	$(document).on('change', '#aips-generated-posts-select-all', function () { $('.aips-generated-post-checkbox').prop('checked', this.checked); });
})(jQuery);
