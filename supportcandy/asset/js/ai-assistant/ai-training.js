/**
 * Timer handle for the in-flight sync progress poll, so a finished/failed
 * poll loop can cancel any pending retry.
 */
let wpscAitPollTimer = null;

/**
 * Update the training sync progress bar.
 *
 * @param {number} percent Overall completion percentage (0-100).
 * @param {string} label   Progress label to display alongside the bar.
 */
function wpsc_update_sync_progress( percent, label ) {

	const container = jQuery( '.wpsc-ait-sync-progress' );
	if ( ! container.length ) {
		return;
	}

	const clampedPercent = Math.max( 0, Math.min( 100, percent ) );

	container.show();
	container.find( '.wpsc-ait-sync-progress-fill' ).css( 'width', clampedPercent + '%' );
	container.find( '.wpsc-ait-sync-progress-label' ).text( label || ( Math.round( clampedPercent ) + '%' ) );
}

/**
 * Hide the training sync progress bar.
 */
function wpsc_hide_sync_progress() {
	jQuery( '.wpsc-ait-sync-progress' ).hide();
}

/**
 * Render the per-post-type breakdown list (done / processing / pending), so
 * post types that finish within a single poll interval (small counts) still
 * show up as completed instead of looking like they were skipped.
 *
 * @param {Array} postTypes List of { name, status, page, total_pages }.
 */
function wpsc_render_sync_post_types( postTypes ) {

	const list = jQuery( '.wpsc-ait-sync-post-types' );
	if ( ! list.length ) {
		return;
	}

	list.empty();

	( Array.isArray( postTypes ) ? postTypes : [] ).forEach( function( postType ) {
		const item = jQuery( '<li></li>' )
			.addClass( postType.status || 'pending' )
			.text( postType.name + ' (' + postType.page + '/' + postType.total_pages + ')' );
		list.append( item );
	} );
}

/**
 * Load Website tab ui (training sources list)
 */
function wpsc_get_aia_website_setting() {
  supportcandy.current_tab = "website";
  jQuery(".wpsc-setting-tab-container button").removeClass("active");
  jQuery(
    ".wpsc-setting-tab-container button." + supportcandy.current_tab
  ).addClass("active");

  window.history.replaceState(
    {},
    null,
    "admin.php?page=wpsc-settings&section=" +
      supportcandy.current_section +
      "&tab=" +
      supportcandy.current_tab
  );
  jQuery(".wpsc-setting-section-body").html(supportcandy.loader_html);

  wpsc_scroll_top();

  var data = { action: "wpsc_get_aia_website_setting" };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-setting-section-body").html(response);
    wpsc_reset_responsive_style();
  });
}

/**
 * Load File Upload tab ui
 */
function wpsc_get_aia_file_upload_setting() {
  supportcandy.current_tab = "file-upload";
  jQuery(".wpsc-setting-tab-container button").removeClass("active");
  jQuery(
    ".wpsc-setting-tab-container button." + supportcandy.current_tab
  ).addClass("active");

  window.history.replaceState(
    {},
    null,
    "admin.php?page=wpsc-settings&section=" +
      supportcandy.current_section +
      "&tab=" +
      supportcandy.current_tab
  );
  jQuery(".wpsc-setting-section-body").html(supportcandy.loader_html);

  wpsc_scroll_top();

  var data = { action: "wpsc_get_aia_file_upload_setting" };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-setting-section-body").html(response);
    wpsc_reset_responsive_style();
  });
}

/**
 * Get AI training source form (add/edit).
 */
function wpsc_add_ai_training_source(nonce) {

	const data = {
		action: 'wpsc_add_ai_training_source',
		_ajax_nonce: nonce
	};

	jQuery('.wpsc-setting-section-body').html(supportcandy.loader_html);
	jQuery.post(
		supportcandy.ajax_url,
		data,
		function (response) {
			jQuery('.wpsc-setting-section-body').html(response);
			wpsc_reset_responsive_style();
		}
	);
}

/**
 * Get AI training source form (add/edit).
 */
function wpsc_edit_ai_training_source(slug, nonce) {

	const data = {
		action: 'wpsc_edit_ai_training_source',
		slug: slug || '',
		_ajax_nonce: nonce
	};

	jQuery('.wpsc-setting-section-body').html(supportcandy.loader_html);
	jQuery.post(
		supportcandy.ajax_url,
		data,
		function (response) {
			jQuery('.wpsc-setting-section-body').html(response);
			wpsc_reset_responsive_style();
		}
	);
}

/**
 * Delete AI training source.
 */
function wpsc_get_delete_ai_training(slug, nonce) {

	if ( ! confirm( supportcandy.translations.confirm ) ) {
		return;
	}

	const data = {
		action: 'wpsc_get_delete_ai_training',
		slug: slug || '',
		_ajax_nonce: nonce
	};

	jQuery.post(
		supportcandy.ajax_url,
		data,
		function () {
			wpsc_get_aia_website_setting();
		}
	);
}

/**
 * Fetch data from WordPress endpoint entered in source form.
 */
function wpsc_fetch_wordpress_endpoints_posts(el, nonce) {

	var form = jQuery( '.wpsc-frm-add-ai-training-source, .wpsc-frm-edit-ai-training-source' )[0];
	if ( ! form ) {
		return;
	}

	const responseWrap = jQuery( '.wpsc-ait-wordpress-sync-response' );
	const renderResponse = function( message, isSuccess ) {
		if ( ! responseWrap.length ) {
			return;
		}

		responseWrap
			.stop( true, true )
			.removeAttr( 'class' )
			.addClass( 'wpsc-ait-wordpress-sync-response' )
			.addClass( isSuccess ? 'info' : 'error' )
			.html( message )
			.show();
	};

	var dataform = new FormData( form );
	const endpoint = (dataform.get( 'ait-wp-endpoint' ) || '').trim();

	if ( endpoint == '' ) {
		renderResponse( supportcandy.translations.req_fields_missing, false );
		return;
	}

	responseWrap
		.stop( true, true )
		.removeAttr( 'class' )
		.addClass( 'wpsc-ait-wordpress-sync-response' )
		.html( supportcandy.loader_html )
		.show();

	const buttonText = jQuery( el ).text();
	jQuery( el ).prop( 'disabled', true ).text( supportcandy.translations.please_wait );

	dataform.set( 'action', 'wpsc_fetch_wordpress_endpoints_posts' );
	dataform.set( '_ajax_nonce', nonce );

	jQuery.ajax(
		{
			url: supportcandy.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: dataform,
			processData: false,
			contentType: false
		}
	).done(
		function (res) {		
			if ( res && res.success ) {
				const message = ( res.data && res.data.message ) ? res.data.message : supportcandy.translations.something_wrong;
				const ragTypesHtml = ( res.data && typeof res.data.rag_types_html === 'string' ) ? res.data.rag_types_html : '';
				renderResponse( '<div class="label-container"><label>' + message + '</label></div>' + ragTypesHtml, true );
			} else {
				renderResponse( ( res && res.data && res.data.message ) ? res.data.message : supportcandy.translations.something_wrong, false );
			}
		}
	).fail(
		function (xhr) {
			let message = supportcandy.translations.something_wrong;
			if (
				xhr &&
				xhr.responseJSON &&
				xhr.responseJSON.data &&
				typeof xhr.responseJSON.data.message === 'string' &&
				xhr.responseJSON.data.message.trim() !== ''
			) {
				message = xhr.responseJSON.data.message;
			} else if (
				xhr &&
				xhr.responseJSON &&
				typeof xhr.responseJSON.data === 'string' &&
				xhr.responseJSON.data.trim() !== ''
			) {
				message = xhr.responseJSON.data;
			}
			renderResponse( message, false );
		}
	).always(
		function () {
			jQuery( el ).prop( 'disabled', false ).text( buttonText );
		}
	);
}

/**
 * Collect checked/unchecked post types from the currently rendered post types list
 * and return them as an array of { slug, name, status } objects.
 *
 * @return {Array<Object>} Collected post types data.
 */
function wpsc_collect_ait_post_types_data() {

	const postTypesData = [];
	jQuery( '.wpsc-ait-wordpress-sync-response input[name="ait-post-types[]"]' ).each(
		function () {
			const checkbox = jQuery( this );
			const slug = ( checkbox.val() || '' ).trim();

			if ( slug === '' ) {
				return;
			}

			const label = checkbox.closest( '.checkbox-container' ).find( 'label' ).first();
			postTypesData.push( {
					slug: slug,
					name: ( label.text() || '' ).trim(),
					status: checkbox.is( ':checked' ) ? 1 : 0
				}
			);
		}
	);

	return postTypesData;
}

/**
 * Save AI Training Source (add or edit) and start importing newly added post types.
 *
 * @param {HTMLElement} el Submit button.
 * @param {string} action Action to perform.
 */
function wpsc_set_add_ai_training_source( el ) {

	const form = jQuery( '.wpsc-frm-add-ai-training-source' )[0];
	if ( ! form ) {
		return;
	}

	const dataform = new FormData( form );
	const name = ( dataform.get( 'ait-name' ) || '' ).trim();
	const endpoint = ( dataform.get( 'ait-wp-endpoint' ) || '' ).trim();

	if ( name === '' || endpoint === '' ) {
		alert( supportcandy.translations.req_fields_missing );
		return;
	}

	// Prevent duplicate submission.
	const button = jQuery( el );
	const buttonOriginalText = button.text();
	button.prop( 'disabled', true );

	const buttons = jQuery( '.wpsc-modal-footer button' );
	buttons.prop( 'disabled', true );

	button.text( supportcandy.translations.please_wait );

	// Save training source.
	jQuery.ajax( {
			url: supportcandy.ajax_url,
			type: 'POST',
			data: dataform,
			processData: false,
			contentType: false
		}
	)
	.done(
		function( response ) {
			if ( ! response.success ) {
				alert( response.data?.message || supportcandy.translations.something_wrong );
				button.prop( 'disabled', false );
				buttons.prop( 'disabled', false );
				button.text( buttonOriginalText );
				return;
			}

			const sourceSlug = response.data.source_slug || '';
			const editNonce = response.data.edit_nonce || '';

			// Open the edit screen for further operations (post type sync, resync, delete, etc.).
			wpsc_edit_ai_training_source( sourceSlug, editNonce );
		}
	)
	.fail(
		function( xhr ) {
			let message = supportcandy.translations.something_wrong;
			if (
				xhr.responseJSON &&
				xhr.responseJSON.data &&
				xhr.responseJSON.data.message
			) {
				message = xhr.responseJSON.data.message;
			}
			alert( message );
			button.prop( 'disabled', false );
			buttons.prop( 'disabled', false );
			button.text( buttonOriginalText );
		}
	);

}

/**
 * Update an existing AI training source's name and post types.
 *
 * This persists the source's own data and, when the update leaves at least
 * one post type enabled, the server also kicks off a background sync - this
 * just starts polling its progress (see wpsc_poll_ait_sync_progress()).
 *
 * @param {HTMLElement} el Update button.
 */
function wpsc_update_edit_ai_training_source( el ) {

	const form = jQuery( '.wpsc-frm-edit-ai-training-source' )[0];
	if ( ! form ) {
		return;
	}

	const dataform = new FormData( form );
	const name = ( dataform.get( 'ait-name' ) || '' ).trim();
	const postTypeCheckboxes = jQuery( '.wpsc-ait-wordpress-sync-response input[name="ait-post-types[]"]' );
	const slug = dataform.get( 'ait-training-type' ) || '';

	if ( name === '' || postTypeCheckboxes.length === 0 ) {
		alert( supportcandy.translations.req_fields_missing );
		return;
	}

	dataform.set( 'ait-post-types-data', JSON.stringify( wpsc_collect_ait_post_types_data() ) );
	dataform.set( 'action', 'wpsc_update_edit_ai_training_source' );
	dataform.set( '_ajax_nonce', dataform.get( 'wpsc_update_ai_training_source_nonce' ) || '' );

	// Prevent duplicate submission.
	const button = jQuery( el );
	const buttonOriginalText = button.text();
	button.prop( 'disabled', true );
	button.text( supportcandy.translations.please_wait );

	jQuery.ajax( {
			url: supportcandy.ajax_url,
			type: 'POST',
			data: dataform,
			processData: false,
			contentType: false
		}
	)
	.done(
		function( response ) {
			button.prop( 'disabled', false );
			button.text( buttonOriginalText );

			if ( response.success && response.data.is_sync ) {
				wpsc_poll_ait_sync_progress( slug );
			}
		}
	)
	.fail(
		function( xhr ) {
			let message = supportcandy.translations.something_wrong;
			if (
				xhr.responseJSON &&
				xhr.responseJSON.data &&
				xhr.responseJSON.data.message
			) {
				message = xhr.responseJSON.data.message;
			}
			alert( message );
			button.prop( 'disabled', false );
			button.text( buttonOriginalText );
		}
	);

}

/**
 * Sync posts for an AI training source.
 *
 * Kicks off a background sync job on the server and starts polling its
 * progress. The server does all the paging/importing (see run_sync_tick()
 * in class-wpsc-ps-ai-setting-ai-training-actions.php); this just reports it.
 *
 * @param {HTMLElement} el    Sync button.
 * @param {string}      nonce Nonce for the wpsc_sync_posts_for_ai_training action.
 * @param {string}      slug  Training source slug.
 */
function wpsc_sync_posts_for_ai_training( el, nonce, slug ) {

	jQuery.post(
		supportcandy.ajax_url,
		{
			action: 'wpsc_sync_posts_for_ai_training',
			_ajax_nonce: nonce,
			slug: slug || ''
		}
	)
	.done(
		function( response ) {
			if ( ! response.success ) {
				alert( response.data?.message || supportcandy.translations.something_wrong );
				return;
			}
			wpsc_poll_ait_sync_progress( response.data.source_slug || slug || '' );
		}
	)
	.fail(
		function( xhr ) {
			let message = supportcandy.translations.something_wrong;
			if (
				xhr.responseJSON &&
				xhr.responseJSON.data &&
				xhr.responseJSON.data.message
			) {
				message = xhr.responseJSON.data.message;
			}
			alert( message );
		}
	);

}

/**
 * Poll the background sync job for a training source and drive the progress
 * bar until it completes or fails. Safe to call both right after starting a
 * sync and on page load to resume watching one that's already running.
 *
 * @param {string} slug Training source slug.
 */
function wpsc_poll_ait_sync_progress( slug ) {

	if ( ! slug ) {
		return;
	}

	const form = jQuery( '.wpsc-frm-edit-ai-training-source' )[0];
	const nonce = form ? ( new FormData( form ) ).get( 'wpsc_get_ait_sync_progress_nonce' ) : '';
	const editNonce = form ? ( new FormData( form ) ).get( 'wpsc-ait-edit-refresh-nonce' ) : '';

	if ( ! nonce ) {
		return;
	}

	const buttons = jQuery( '#wpsc-update-source-btn, #wpsc-sync-posts-btn, #wpsc-delete-all-posts-btn' );
	const tabButtons = jQuery( '.wpsc-setting-tab-container button' );
	buttons.prop( 'disabled', true );
	tabButtons.prop( 'disabled', true );

	window.addEventListener( 'beforeunload', wpsc_ait_confirm_unload );

	wpsc_update_sync_progress( 0, supportcandy.translations.please_wait );
	clearTimeout( wpscAitPollTimer );

	// A poll request can fail transiently, so a few retries are worth it - but
	// retrying forever (an expired nonce, a permission failure, a server error)
	// leaves the progress bar up and the form locked with nothing ever reported.
	const maxPollFailures = 5;
	let pollFailures = 0;

	const poll = function() {
		jQuery.post(
			supportcandy.ajax_url,
			{
				action: 'wpsc_get_ait_sync_progress',
				_ajax_nonce: nonce,
				slug: slug
			}
		)
		.done(
			function( response ) {

				pollFailures = 0;

				if ( ! response.success ) {
					wpsc_finish_ait_sync_progress( buttons, tabButtons, response.data?.message || supportcandy.translations.something_wrong );
					return;
				}

				const data = response.data || {};

				if ( 'idle' === data.status ) {
					buttons.prop( 'disabled', false );
					tabButtons.prop( 'disabled', false );
					window.removeEventListener( 'beforeunload', wpsc_ait_confirm_unload );
					wpsc_hide_sync_progress();
					return;
				}

				wpsc_update_sync_progress( data.percent || 0, data.label || '' );
				wpsc_render_sync_post_types( data.post_types );

				if ( 'completed' === data.status ) {
					wpsc_finish_ait_sync_progress( buttons, tabButtons, '', data.deleted || 0, slug, editNonce );
					return;
				}

				if ( 'failed' === data.status ) {
					wpsc_finish_ait_sync_progress( buttons, tabButtons, data.message || supportcandy.translations.something_wrong );
					return;
				}

				wpscAitPollTimer = setTimeout( poll, 2000 );
			}
		)
		.fail(
			function( xhr ) {

				pollFailures++;
				if ( pollFailures < maxPollFailures ) {
					wpscAitPollTimer = setTimeout( poll, 2000 );
					return;
				}

				let message = supportcandy.translations.something_wrong;
				if ( xhr.responseJSON && xhr.responseJSON.data ) {
					message = xhr.responseJSON.data.message || xhr.responseJSON.data || message;
				}
				wpsc_finish_ait_sync_progress( buttons, tabButtons, message );
			}
		);
	};

	poll();
}

/**
 * beforeunload handler shown while a sync is in progress, warning the agent
 * not to refresh/close the tab. The sync itself is cron-driven and will keep
 * running regardless - this only protects the polling UI from being confused
 * for the actual work being lost.
 *
 * @param {Event} event beforeunload event.
 * @return {string} Confirmation message (also required for some browsers to show a prompt).
 */
function wpsc_ait_confirm_unload( event ) {
	const message = supportcandy.translations.ait_sync_in_progress || 'A sync is in progress. Are you sure you want to leave?';
	event.preventDefault();
	event.returnValue = message;
	return message;
}

/**
 * Stop polling, re-enable the form's and tab-switching buttons, drop the
 * unload guard and hide the progress bar. On a clean completion, refreshes
 * the edit screen in place to show the new record counts; on failure, alerts
 * the error instead.
 *
 * @param {jQuery} buttons      Buttons to re-enable.
 * @param {jQuery} tabButtons   Settings tab-switcher buttons to re-enable.
 * @param {string} errorMessage Error message, if the sync failed.
 * @param {number} deleted      Number of stale records removed (completed only).
 * @param {string} slug         Training source slug, used to refresh the edit screen.
 * @param {string} editNonce    Nonce for the wpsc_edit_ai_training_source action.
 */
function wpsc_finish_ait_sync_progress( buttons, tabButtons, errorMessage, deleted, slug, editNonce ) {

	clearTimeout( wpscAitPollTimer );
	buttons.prop( 'disabled', false );
	tabButtons.prop( 'disabled', false );
	window.removeEventListener( 'beforeunload', wpsc_ait_confirm_unload );
	wpsc_hide_sync_progress();

	if ( errorMessage ) {
		alert( errorMessage );
		return;
	}

	if ( deleted > 0 ) {
		alert( deleted + ' ' + ( supportcandy.translations.ait_stale_records_removed || 'record(s) no longer available at the source were removed.' ) );
	}

	if ( slug && editNonce ) {
		wpsc_edit_ai_training_source( slug, editNonce );
	}
}

/**
 * Delete all posts (training data) synced for a training source.
 *
 * @param {HTMLElement} el    Delete button.
 * @param {string}      nonce Nonce for security.
 * @param {string}      slug  Training source slug.
 */
function wpsc_delete_all_ait_posts( el, nonce, slug ) {

	if ( ! confirm( supportcandy.translations.delete_all_posts ) ) {
		return;
	}

	const data = {
		action: 'wpsc_delete_all_ait_posts',
		_ajax_nonce: nonce,
		slug: slug || ''
	};

	jQuery.post( supportcandy.ajax_url, data )
		.done(
			function( response ) {
				if ( ! response.success ) {
					alert( response.data?.message || supportcandy.translations.something_wrong );
					return;
				}
				wpsc_get_aia_website_setting();
			}
		)
		.fail(
			function( xhr ) {
				let message = supportcandy.translations.something_wrong;
				if (
					xhr.responseJSON &&
					xhr.responseJSON.data &&
					xhr.responseJSON.data.message
				) {
					message = xhr.responseJSON.data.message;
				}
				alert( message );
			}
		);
}

/**
 * Manually schedule the wpsc_ai_training_upload cron from the "Retry Upload"
 * link shown next to the In Queue count when there are queued records but the
 * cron isn't scheduled (see edit_ai_training_source() in
 * class-wpsc-ps-ai-setting-ai-training.php). The link is hidden immediately on
 * click so it can't be clicked twice while the request is in flight; on
 * success, refreshing the edit screen in place removes it for good (the cron
 * is now scheduled). On failure it's shown again so the admin can retry.
 *
 * @param {HTMLElement} el        The clicked link.
 * @param {string}      nonce     Nonce for the wpsc_schedule_ai_training_upload action.
 * @param {string}      slug      Training source slug, used to refresh the edit screen.
 * @param {string}      editNonce Nonce for the wpsc_edit_ai_training_source action.
 */
function wpsc_schedule_ai_training_upload( el, nonce, slug, editNonce ) {

	const link = jQuery( el ).hide();

	const data = {
		action: 'wpsc_schedule_ai_training_upload',
		_ajax_nonce: nonce
	};

	jQuery.post( supportcandy.ajax_url, data )
		.done(
			function( response ) {
				if ( ! response.success ) {
					alert( response.data?.message || supportcandy.translations.something_wrong );
					link.show();
					return;
				}
				// Refreshing the edit screen replaces this link entirely, so it's not shown again here.
				wpsc_edit_ai_training_source( slug, editNonce );
			}
		)
		.fail(
			function( xhr ) {
				let message = supportcandy.translations.something_wrong;
				if (
					xhr.responseJSON &&
					xhr.responseJSON.data &&
					xhr.responseJSON.data.message
				) {
					message = xhr.responseJSON.data.message;
				}
				alert( message );
				link.show();
			}
		);
}