jQuery(function () {
	'use strict';

	let deactivateUrl = '';
	let currentPlugin = null;
	let sending = false;

	const modal = jQuery('#psm-dfr-modal');

	/**
	 * Open feedback modal.
	 *
	 * @param {Object} plugin Plugin configuration.
	 * @param {string} url Deactivate URL.
	 */
	function openModal(plugin, url) {
		currentPlugin = plugin;
		deactivateUrl = url;

		jQuery('#psm-dfr-title').text('Quick Feedback');

		jQuery('#psm-dfr-reasons').empty();

		jQuery('#psm-dfr-note').val('');

		jQuery('#psm-dfr-submit')
			.prop('disabled', false)
			.text('Submit & Deactivate');

		jQuery.each(plugin.reasons, function (value, label) {
			jQuery('#psm-dfr-reasons').append(
				'<div>' +
					'<label>' +
					'<input type="radio" name="psm_dfr_reason" value="' +
					value +
					'"> ' +
					label +
					'</label>' +
					'</div>'
			);
		});

		jQuery('#psm-dfr-modal').fadeIn(150);
	}

	/**
	 * Close modal.
	 */
	function closeModal() {
		jQuery('#psm-dfr-modal').fadeOut(150);

		jQuery('#psm-dfr-note').val('');

		jQuery('#psm-dfr-reasons').empty();

		sending = false;
	}

	/**
	 * Attach deactivate links.
	 */
	jQuery.each(window.PSM_DFR.plugins, function (index, plugin) {
		jQuery(document).on(
			'click',
			'tr[data-plugin="' + plugin.plugin_file + '"] .deactivate a',
			function (event) {
				event.preventDefault();

				openModal(plugin, jQuery(this).attr('href'));
			}
		);
	});

	/**
	 * Cancel.
	 */
	jQuery(document).on('click', '#psm-dfr-cancel', function () {
		closeModal();
	});

	/**
	 * Overlay click.
	 */
	jQuery(document).on('click', '.psm-dfr-overlay', function () {
		closeModal();
	});

	/**
	 * Skip feedback.
	 */
	jQuery(document).on('click', '#psm-dfr-skip', function () {
		window.location.href = deactivateUrl;
	});

	/**
	 * Submit feedback.
	 */
	jQuery(document).on('click', '#psm-dfr-submit', function () {
		if (sending) {
			return;
		}

		const reason =
			jQuery('input[name="psm_dfr_reason"]:checked').val() || '';

		const note = jQuery('#psm-dfr-note').val();

		if ('' === reason) {
			window.alert('Please select a reason.');

			return;
		}

		sending = true;

		jQuery(this).prop('disabled', true);

		jQuery.ajax({
			url: window.PSM_DFR.ajax_url,

			type: 'POST',

			timeout: 5000,

			data: {
				action: 'psm_dfr_submit',

				nonce: window.PSM_DFR.nonce,

				plugin_file: currentPlugin.plugin_file,

				reason: reason,

				note: note,
			},

			success: function () {
				window.location.href = deactivateUrl;
			},

			error: function () {
				window.location.href = deactivateUrl;
			},
		});
	});
});
