/*
 * AI Assistant setting js
 */
function wpsc_ai_assistant_setting(is_humbargar = false) {
  if (is_humbargar) {
    wpsc_toggle_humbargar();
  }

  jQuery(".wpsc-setting-nav, .wpsc-humbargar-menu-item").removeClass("active");
  jQuery(
    ".wpsc-setting-nav.ai-assistant, .wpsc-humbargar-menu-item.ai-assistant",
  ).addClass("active");
  jQuery(".wpsc-humbargar-title").html(
    supportcandy.humbargar_titles.ai_assistant,
  );

  if (supportcandy.current_section !== "ai-assistant") {
    supportcandy.current_section = "ai-assistant";
    supportcandy.current_tab = "general";
  }

  window.history.replaceState(
    {},
    null,
    "admin.php?page=wpsc-settings&section=" + supportcandy.current_section,
  );
  jQuery(".wpsc-setting-body").html(supportcandy.loader_html);

  wpsc_scroll_top();
  var data = {
    action: "wpsc_ai_assistant_setting",
    tab: supportcandy.current_tab,
  };

  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-setting-body").html(response);
    wpsc_reset_responsive_style();
    jQuery(
      ".wpsc-setting-tab-container button." + supportcandy.current_tab,
    ).trigger("click");
  });
}

/**
 * Load general setting tab ui
 */
function wpsc_get_aia_general_setting() {
  supportcandy.current_tab = "general";
  jQuery(".wpsc-setting-tab-container button").removeClass("active");
  jQuery(
    ".wpsc-setting-tab-container button." + supportcandy.current_tab,
  ).addClass("active");

  window.history.replaceState(
    {},
    null,
    "admin.php?page=wpsc-settings&section=" +
      supportcandy.current_section +
      "&tab=" +
      supportcandy.current_tab,
  );
  jQuery(".wpsc-setting-section-body").html(supportcandy.loader_html);

  wpsc_scroll_top();

  const data = { action: "wpsc_get_aia_general_setting" };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-setting-section-body").html(response);
    wpsc_reset_responsive_style();
  });
}

/**
 * Load AI assistant tab ui
 */
function wpsc_get_aia_assistant_setting() {
  supportcandy.current_tab = "assistant";
  jQuery(".wpsc-setting-tab-container button").removeClass("active");
  jQuery(
    ".wpsc-setting-tab-container button." + supportcandy.current_tab,
  ).addClass("active");

  window.history.replaceState(
    {},
    null,
    "admin.php?page=wpsc-settings&section=" +
      supportcandy.current_section +
      "&tab=" +
      supportcandy.current_tab,
  );
  jQuery(".wpsc-setting-section-body").html(supportcandy.loader_html);

  wpsc_scroll_top();

  const data = { action: "wpsc_get_aia_assistant_setting" };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-setting-section-body").html(response);
    wpsc_reset_responsive_style();
  });
}

/**
 * Load AI logs tab ui
 */
function wpsc_get_aia_logs_setting() {

  supportcandy.current_tab = "ai-logs";
  jQuery(".wpsc-setting-tab-container button").removeClass("active");
  jQuery(".wpsc-setting-tab-container button." + supportcandy.current_tab).addClass("active");

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

  const data = {
    action: "wpsc_get_aia_logs_setting"
  };

  /* check if page opened from onclick */
  const filter = sessionStorage.getItem("wpsc_ai_logs_filter");
  if (filter) {
    data.filter = JSON.parse(filter);
    /* remove after using */
    sessionStorage.removeItem("wpsc_ai_logs_filter");
  }

  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-setting-section-body").html(response);
    wpsc_reset_responsive_style();
  });
}

/**
 * Get edit category modal
 */
function wpsc_get_edit_ai_category(id, nonce) {

	wpsc_show_modal();
	var data = { action: 'wpsc_get_edit_ai_category', id, _ajax_nonce: nonce };
	jQuery.post(
		supportcandy.ajax_url,
		data,
		function (res) {

			// Set to modal.
			jQuery( '.wpsc-modal-header' ).text( res.title );
			jQuery( '.wpsc-modal-body' ).html( res.body );
			jQuery( '.wpsc-modal-footer' ).html( res.footer );
			// Display modal.
			wpsc_show_modal_inner_container();
		}
	);
}

/**
 * Save setting
 */
function wpsc_set_ai_settings(el) {
  var form = jQuery(".wpsc-frm-ai-settings")[0];
  var dataform = new FormData(form);

  if (dataform.get("wpsc-ai-api-key").trim() == "") {
    alert(supportcandy.translations.req_fields_missing);
    return;
  }

  jQuery(el).text(supportcandy.translations.please_wait);
  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: "POST",
      data: dataform,
      processData: false,
      contentType: false,
    })
    .done(function (res) {
      window.location.reload();
    });
}

/**
 * Save AI assistant tab settings
 */
function wpsc_set_ai_assistant_settings(el) {
  var form = jQuery(".wpsc-frm-ai-assistant-settings")[0];
  var dataform = new FormData(form);

  jQuery(el).text(supportcandy.translations.please_wait);
  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: "POST",
      data: dataform,
      processData: false,
      contentType: false,
    })
    .done(function (res) {
      window.location.reload();
    });
}

/*
 * Reset settings
 */
function wpsc_reset_ai_settings(el, nonce) {
  var data = {
    action: "wpsc_reset_ai_settings",
    _ajax_nonce: nonce,
  };

  jQuery(el).text(supportcandy.translations.please_wait);
  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: "POST",
      data: data,
    })
    .done(function (res) {
      window.location.reload();
    });
}

/*
 * onclick load respective card ticket list based on agent id
 */
function wpsc_filter_ai_logs_by_agent(agent_id, date_range) {

  if (!agent_id) {
    return;
  }
  const data = {
    agent_id: agent_id,
    date_range: date_range
  };

  sessionStorage.setItem(
    "wpsc_ai_logs_filter",
    JSON.stringify(data)
  );

  window.location.href = "admin.php?page=wpsc-settings&section=ai-assistant&tab=ai-logs";
}

// AI Training

/**
 * Get add AI training item
 */
function wpsc_add_ai_training_item(el) {

	wpsc_show_modal();
	var data = { action: 'wpsc_add_ai_training_item' };
	jQuery.post(
		supportcandy.ajax_url,
		data,
		function (response) {

			// Set to modal.
			jQuery( '.wpsc-modal-header' ).text( response.title );
			jQuery( '.wpsc-modal-body' ).html( response.body );
			jQuery( '.wpsc-modal-footer' ).html( response.footer );
			// Display modal.
			wpsc_show_modal_inner_container();
		}
	);
}

/**
 * Delete all training data
 */
function wpsc_delete_all_training_data(el, nonce, type) {
  var flag = confirm(supportcandy.translations.confirm);
  if (!flag) {
    return;
  }

  var data = {
    action: "wpsc_delete_all_training_data_" + type,
    _ajax_nonce: nonce,
    type: type
  };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    window.location.reload();
  });
}

/**
 * Set an AI training item
 */
function wpsc_set_add_ai_training_item(el) {

  var form = jQuery('.wpsc-frm-add-ai-training-item')[0];
  var dataform = new FormData(form);

  // Validate required fields
  if ( dataform.get('wpsc_training_type').trim() == '' ) {
    alert(supportcandy.translations.req_fields_missing);
    return;
  }

  // If file type is selected, validate the file input
  if (dataform.get('wpsc_training_type').trim() === 'wpsc_ai_file_type') {
	const fileInput = dataform.getAll('wpsc_ai_file_input[]');
	if ( !fileInput || fileInput.length === 0 || (fileInput.length === 1 && (!fileInput[0] || !fileInput[0].name)) ) {
		alert(supportcandy.translations.req_fields_missing);
		return;
	}
}

  // If URL type is selected, validate the URLs
  if (dataform.get('wpsc_training_type').trim() == 'wpsc_ai_url_type') {
    var urlInput = dataform.get('wpsc_ai_url_input');
    if (!urlInput || urlInput.trim() === '') {
      alert(supportcandy.translations.req_fields_missing);
      return;
    }

    // Split by newlines and validate each line as a URL
    var urls = urlInput.split(/\r?\n/).map(function(line) { return line.trim(); }).filter(Boolean);
    var urlPattern = /^(https?:\/\/)[^\s/$.?#].[^\s]*$/i;
    var allValid = urls.every(function(url) { return urlPattern.test(url); });
    if (!allValid) {
      alert(supportcandy.translations.valid_url);
      return;
    }
  }

  jQuery('.wpsc-modal-footer button').attr('disabled', true);
  jQuery(el).text(supportcandy.translations.please_wait);
  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: 'POST',
      data: dataform,
      processData: false,
      contentType: false,
    })
    .done(function (res) {
      wpsc_close_modal();
      if(trainingTable && trainingTable.ajax){
        trainingTable.ajax.reload(null, false);
      }
    });
}

/**
 * Delete an AI training item
 */
function wpsc_get_delete_ai_training_item(el, id, nonce) {
  var flag = confirm(supportcandy.translations.confirm);
  if (!flag) {
    return;
  }

  var data = {
    action: "wpsc_get_delete_ai_training_item",
    id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    if(trainingTable && trainingTable.ajax){
      trainingTable.ajax.reload(null, false);
    }
  });
}

/**
 * Download an AI training item
 */
function wpsc_download_ai_training_item(el, id, nonce) {
  var flag = confirm(supportcandy.translations.confirm);
  if (!flag) {
    return;
  }

  var data = {
    action: "wpsc_download_ai_training_item",
    id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    window.location.reload();
  });
}

/**
 * Get bulk archive ticket
 */
function wpsc_bulk_delete_training(nonce) {

	const training_ids = jQuery(".wpsc-bulk-select:checked")
		.map(function () {
			return this.value;
		})
		.get();

	jQuery(".wpsc-bulk-selector").prop( "checked", training_ids.length > 0 );

	if (!training_ids.length) {
		return;
	}

	if (!confirm(supportcandy.translations.confirm)) {
		return;
	}

	jQuery.post(
		supportcandy.ajax_url,
		{
			action: "wpsc_bulk_delete_training",
			training_ids: training_ids,
			_ajax_nonce: nonce
		},
		function () {
			window.location.reload();
		}
	);
}

