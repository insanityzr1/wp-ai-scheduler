/**
 * Third-Party Plugin Integrations (bridge) admin UI.
 *
 * Lives inside the Template editor's "Third-Party Plugin Integrations"
 * disclosure panel: lets an admin pick a detected plugin integration (e.g.
 * ACF), pick one of its schema groups (e.g. an ACF field group), and choose
 * which fields AIPS should generate content for — with an optional per-field
 * custom prompt.
 *
 * @since 2.10.0
 */
(function ($) {
	'use strict';

	window.AIPS = window.AIPS || {};
	var AIPS = window.AIPS;

	// Shapes AIPS_Integration_Manager currently knows how to generate for.
	// Keep in sync with AIPS_Integration_Manager::$generatable_shapes.
	var GENERATABLE_SHAPES = ['short_text', 'long_text', 'html', 'choice'];

	AIPS.Integrations = {

		/** @type {Object<string, Object>} Saved mappings for the current template, keyed by field_key. */
		_savedMappings: {},

		init: function () {
			this.bindEvents();
		},

		bindEvents: function () {
			$(document).on('toggle', '.aips-integrations-panel details', this.onPanelToggle.bind(this));
			$(document).on('change', '#aips-integration-select', this.onIntegrationChange.bind(this));
			$(document).on('change', '#aips-integration-group-select', this.onGroupChange.bind(this));
			$(document).on('click', '#aips-save-integration-mappings', this.onSaveClick.bind(this));
		},

		onPanelToggle: function (e) {
			if (!e.target.open) {
				return;
			}

			var templateId = $('#template_id').val();
			var $panel = $('#aips-integrations-panel-body');

			if (!templateId) {
				$panel.find('.aips-integrations-unsaved-notice').show();
				$panel.find('.aips-integrations-config').hide();
				return;
			}

			$panel.find('.aips-integrations-unsaved-notice').hide();
			$panel.find('.aips-integrations-config').show();

			this.loadIntegrations();
			this.loadSavedMappings(templateId);
		},

		loadIntegrations: function () {
			var $select = $('#aips-integration-select');

			$.post(aipsAjax.ajaxUrl, {
				action: 'aips_get_available_integrations',
				nonce: aipsAjax.nonce
			}, function (response) {
				if (!response.success) {
					AIPS.Utilities.showToast(response.data.message, 'error');
					return;
				}

				var integrations = response.data.integrations || [];
				$select.empty();

				if (!integrations.length) {
					$select.append($('<option>', { value: '', text: aipsIntegrationsL10n.noneAvailable }));
					return;
				}

				$select.append($('<option>', { value: '', text: aipsIntegrationsL10n.selectIntegration }));
				integrations.forEach(function (integration) {
					$select.append($('<option>', { value: integration.id, text: integration.label }));
				});

				// Re-select whatever was previously mapped for this template, if any.
				var previousIntegrationId = AIPS.Integrations._firstSavedValue('integration_id');
				if (previousIntegrationId) {
					$select.val(previousIntegrationId).trigger('change');
				}
			}).fail(function () {
				AIPS.Utilities.showToast(aipsAdminL10n.errorTryAgain, 'error');
			});
		},

		loadSavedMappings: function (templateId) {
			var self = this;

			$.post(aipsAjax.ajaxUrl, {
				action: 'aips_get_field_mappings',
				nonce: aipsAjax.nonce,
				template_id: templateId
			}, function (response) {
				self._savedMappings = {};

				if (!response.success) {
					return;
				}

				(response.data.mappings || []).forEach(function (mapping) {
					self._savedMappings[mapping.field_key] = mapping;
				});
			});
		},

		_firstSavedValue: function (key) {
			for (var fieldKey in this._savedMappings) {
				if (Object.prototype.hasOwnProperty.call(this._savedMappings, fieldKey)) {
					return this._savedMappings[fieldKey][key];
				}
			}
			return '';
		},

		onIntegrationChange: function () {
			var integrationId = $('#aips-integration-select').val();
			var $groupSelect = $('#aips-integration-group-select');

			$('#aips-integration-fields-tbody').empty();

			if (!integrationId) {
				$groupSelect.prop('disabled', true).empty()
					.append($('<option>', { value: '', text: aipsIntegrationsL10n.selectIntegrationFirst }));
				return;
			}

			$.post(aipsAjax.ajaxUrl, {
				action: 'aips_get_integration_schema',
				nonce: aipsAjax.nonce,
				integration_id: integrationId,
				post_type: $('#template_post_type').val()
			}, function (response) {
				if (!response.success) {
					AIPS.Utilities.showToast(response.data.message, 'error');
					return;
				}

				var groups = response.data.field_groups || [];
				$groupSelect.prop('disabled', false).empty();

				if (!groups.length) {
					$groupSelect.append($('<option>', { value: '', text: aipsIntegrationsL10n.noGroupsFound }));
					return;
				}

				$groupSelect.append($('<option>', { value: '', text: aipsIntegrationsL10n.selectFieldGroup }));
				groups.forEach(function (group) {
					$groupSelect.append($('<option>', { value: group.id, text: group.label }));
				});

				var previousGroupId = AIPS.Integrations._firstSavedValue('source_key');
				if (previousGroupId) {
					$groupSelect.val(previousGroupId).trigger('change');
				}
			}).fail(function () {
				AIPS.Utilities.showToast(aipsAdminL10n.errorTryAgain, 'error');
			});
		},

		onGroupChange: function () {
			var integrationId = $('#aips-integration-select').val();
			var groupId = $('#aips-integration-group-select').val();
			var $tbody = $('#aips-integration-fields-tbody');

			$tbody.empty();

			if (!integrationId || !groupId) {
				return;
			}

			var self = this;

			$.post(aipsAjax.ajaxUrl, {
				action: 'aips_get_integration_schema',
				nonce: aipsAjax.nonce,
				integration_id: integrationId,
				group_id: groupId
			}, function (response) {
				if (!response.success) {
					AIPS.Utilities.showToast(response.data.message, 'error');
					return;
				}

				var fields = response.data.fields || [];
				var rows = fields.map(function (field) {
					return self._renderFieldRow(field);
				});

				$tbody.html(rows.join(''));
			}).fail(function () {
				AIPS.Utilities.showToast(aipsAdminL10n.errorTryAgain, 'error');
			});
		},

		_renderFieldRow: function (field) {
			var saved = this._savedMappings[field.key] || {};
			var supported = GENERATABLE_SHAPES.indexOf(field.shape) !== -1;
			var checked = supported && (saved.is_active === undefined ? false : !!parseInt(saved.is_active, 10));

			return AIPS.Templates.render('aips-tmpl-integration-field-row', {
				field_key: field.key,
				label: field.label,
				native_type: field.native_type,
				checked_attr: checked ? 'checked' : '',
				disabled_attr: supported ? '' : 'disabled',
				prompt_value: saved.custom_prompt || field.instructions || '',
				prompt_placeholder: aipsIntegrationsL10n.promptPlaceholder,
				unsupported_class: supported ? '' : 'aips-integration-field-unsupported',
				unsupported_note: supported ? '' : '<p class="description">' + aipsIntegrationsL10n.unsupportedFieldType + '</p>'
			});
		},

		onSaveClick: function () {
			var templateId = $('#template_id').val();
			var integrationId = $('#aips-integration-select').val();
			var groupId = $('#aips-integration-group-select').val();

			if (!templateId || !integrationId || !groupId) {
				AIPS.Utilities.showToast(aipsIntegrationsL10n.selectGroupFirst, 'warning');
				return;
			}

			var mappings = [];
			$('#aips-integration-fields-tbody .aips-integration-field-row').each(function () {
				var $row = $(this);
				mappings.push({
					integration_id: integrationId,
					source_key: groupId,
					field_key: $row.data('field-key'),
					field_label: $row.find('td').eq(0).text(),
					field_type: $row.data('native-type'),
					custom_prompt: $row.find('.aips-integration-field-prompt').val(),
					is_active: $row.find('.aips-integration-field-enabled').is(':checked')
				});
			});

			var self = this;

			$.post(aipsAjax.ajaxUrl, {
				action: 'aips_save_field_mappings',
				nonce: aipsAjax.nonce,
				template_id: templateId,
				mappings: JSON.stringify(mappings)
			}, function (response) {
				if (!response.success) {
					AIPS.Utilities.showToast(response.data.message, 'error');
					return;
				}

				AIPS.Utilities.showToast(response.data.message, 'success');
				self.loadSavedMappings(templateId);
			}).fail(function () {
				AIPS.Utilities.showToast(aipsAdminL10n.errorTryAgain, 'error');
			});
		}
	};

	$(document).ready(function () {
		AIPS.Integrations.init();
	});
})(jQuery);
