/**
 * Timer handle for the in-flight sync progress poll, so a finished/failed
 * poll loop can cancel any pending retry.
 */
let wpscAitPollTimer = null;

/**
 * Every button that starts a mutually-exclusive action against a training
 * source's sync job (Update, Sync Posts, Sync Missing Posts, Delete All Posts).
 * Only one of these may run at a time - interleaving two (e.g. a rapid
 * click on "Sync Posts" then "Delete All Posts" before the first request
 * even lands) would race the same stored sync-job state and produce a
 * meaningless mix of the two actions, so all four are disabled the instant
 * any one of them is clicked, not just once its request comes back.
 */
const WPSC_AIT_ACTION_BUTTONS_SELECTOR = '#wpsc-update-source-btn, #wpsc-sync-posts-btn, #wpsc-sync-missing-posts-btn, #wpsc-delete-all-posts-btn';

/**
 * Disable every mutually-exclusive training-source action button. Called
 * synchronously on click, before any AJAX request goes out, so the other
 * buttons can never be clicked while one action is already in flight.
 */
function wpsc_disable_ait_action_buttons() {
	jQuery( WPSC_AIT_ACTION_BUTTONS_SELECTOR ).prop( 'disabled', true );
}

/**
 * Re-enable every mutually-exclusive training-source action button.
 */
function wpsc_enable_ait_action_buttons() {
	jQuery( WPSC_AIT_ACTION_BUTTONS_SELECTOR ).prop( 'disabled', false );
}

/**
 * Show (or re-hide) the "Update" button based on whether any post type has
 * been fetched via "Get Post Types" - regardless of which, if any, are
 * currently checked. There is nothing for Update to persist without this.
 *
 * Does NOT touch the "Data Synchronization & Actions" section - that only
 * ever appears once a post type is actually enabled and saved, which happens
 * exclusively through a successful "Update" (see
 * wpsc_ait_show_data_sync_section()), not from checking a box.
 *
 * Safe to call at any time (checkboxes not rendered yet, etc.) - the selector
 * no-ops gracefully when empty.
 */
function wpsc_ait_refresh_action_buttons() {

	const hasPostTypes = jQuery( '.wpsc-ait-wordpress-sync-response input[name="ait-post-types[]"]' ).length > 0;
	jQuery( '.wpsc-ait-update-container' ).toggle( hasPostTypes );
}

/**
 * Reveal the "Data Synchronization & Actions" section (hr, heading, and the
 * Sync Posts/Sync Missing Posts/Delete All Posts buttons) and hide the
 * "enable a post type" hint. Called once "Update" succeeds with at least one
 * post type enabled - see wpsc_update_edit_ai_training_source().
 */
function wpsc_ait_show_data_sync_section() {
	jQuery( '.wpsc-ait-data-sync-container' ).show();
	jQuery( '.wpsc-tt-data-sync-setting .wpsc-input-group.options' ).show();
	jQuery( '.wpsc-ait-no-sync-hint' ).hide();
}

/**
 * Hide the "Data Synchronization & Actions" section entirely. Called when
 * "Update" succeeds but leaves no post type enabled - there is nothing left
 * for Sync Posts/Sync Missing Posts/Delete All Posts to act on.
 */
function wpsc_ait_hide_data_sync_section() {
	jQuery( '.wpsc-ait-data-sync-container' ).hide();
}

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
 * Render the per-post-type breakdown list (done / failed / processing / pending),
 * so post types that finish within a single poll interval (small counts) still
 * show up as completed instead of looking like they were skipped, and a post
 * type that failed permanently (exhausted retries) is visibly distinct from one
 * that actually finished - instead of both showing as plain "done".
 *
 * @param {Array} postTypes List of { name, status, page, total_pages, error }.
 */
function wpsc_render_sync_post_types( postTypes ) {

	const list = jQuery( '.wpsc-ait-sync-post-types' );
	if ( ! list.length ) {
		return;
	}

	list.empty();

	( Array.isArray( postTypes ) ? postTypes : [] ).forEach( function( postType ) {
		const isFailed = 'failed' === postType.status;
		const item = jQuery( '<li></li>' )
			.addClass( postType.status || 'pending' )
			.text( postType.name + ' (' + postType.page + '/' + postType.total_pages + ')' + ( isFailed ? ' - ' + ( supportcandy.translations.failed || 'failed' ) : '' ) );

		if ( isFailed && postType.error ) {
			item.attr( 'title', postType.error );
		}

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
 * Load AI Training Data tab ui
 */
function wpsc_get_aia_training_data_setting() {
  supportcandy.current_tab = "ai-training-data";
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

  var data = { action: "wpsc_get_aia_training_data_setting" };
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
			wpsc_ait_refresh_action_buttons();
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

				// Post types were just fetched (and rendered checked/unchecked per their
				// current saved status) - recompute which of Update/the Data Synchronization
				// & Actions section/its individual actions should now be visible, even
				// though the page itself was rendered before this fetch happened.
				if ( ragTypesHtml.trim() !== '' ) {
					wpsc_ait_refresh_action_buttons();
				}
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

	// Prevent duplicate submission, and block every other mutually-exclusive
	// action button too - not just this one - until this request settles.
	const button = jQuery( el );
	const buttonOriginalText = button.text();
	wpsc_disable_ait_action_buttons();
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
			button.text( buttonOriginalText );

			if ( response.success && response.data.is_sync ) {
				// At least one post type is enabled and saved - reveal the "Data
				// Synchronization & Actions" section (its Sync Posts action's progress bar
				// wrapper included) before a background sync kicks off, so
				// wpsc_poll_ait_sync_progress() shows/updates it correctly. Every action
				// button stays disabled; it re-enables them once the sync finishes.
				wpsc_ait_show_data_sync_section();
				wpsc_poll_ait_sync_progress( slug );
				return;
			}

			if ( response.success ) {
				// Nothing enabled (any post type was unchecked before this Update) - there
				// is nothing left for the sync actions to act on.
				wpsc_ait_hide_data_sync_section();
			}

			wpsc_enable_ait_action_buttons();
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
			button.text( buttonOriginalText );
			wpsc_enable_ait_action_buttons();
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

	wpsc_disable_ait_action_buttons();

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
				wpsc_enable_ait_action_buttons();
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
			wpsc_enable_ait_action_buttons();
		}
	);

}

/**
 * Sync only the posts missing a local training record for an AI training
 * source's enabled post types - existing records (even if the remote post
 * has since changed) are left untouched. Use wpsc_sync_posts_for_ai_training()
 * ("Sync Posts") to also refresh changed content.
 *
 * Shares the same background job/progress mechanism as "Sync Posts" - only
 * the AJAX action differs, which is what tags the job as 'missing'-mode
 * server-side (see sync_missing_posts_for_ai_training() in
 * class-wpsc-ps-ai-setting-ai-training-actions.php).
 *
 * @param {HTMLElement} el    Sync Missing Posts button.
 * @param {string}      nonce Nonce for the wpsc_sync_missing_posts_for_ai_training action.
 * @param {string}      slug  Training source slug.
 */
function wpsc_sync_missing_posts_for_ai_training( el, nonce, slug ) {

	wpsc_disable_ait_action_buttons();

	jQuery.post(
		supportcandy.ajax_url,
		{
			action: 'wpsc_sync_missing_posts_for_ai_training',
			_ajax_nonce: nonce,
			slug: slug || ''
		}
	)
	.done(
		function( response ) {
			if ( ! response.success ) {
				alert( response.data?.message || supportcandy.translations.something_wrong );
				wpsc_enable_ait_action_buttons();
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
			wpsc_enable_ait_action_buttons();
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

	const buttons = jQuery( WPSC_AIT_ACTION_BUTTONS_SELECTOR );
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
					// A post type can fail permanently (exhausted retries) while the rest of
					// the sync still finishes normally - the job as a whole is "completed" and
					// the DB updates from every other post type are real, so this still runs
					// the normal completed flow (refresh, stale-deleted notice), but the
					// failure itself must not pass silently as if nothing went wrong.
					if ( data.message ) {
						alert( data.message );
					}
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

	wpsc_disable_ait_action_buttons();

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
					wpsc_enable_ait_action_buttons();
					return;
				}
				// Re-renders the whole tab with fresh markup (including these buttons),
				// so there is nothing to explicitly re-enable on this path.
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
				wpsc_enable_ait_action_buttons();
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