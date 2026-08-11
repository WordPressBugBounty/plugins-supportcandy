/*
 * AI chatbot setting js
 */
function wpsc_ai_chatbot_settings(is_humbargar = false) {
  if (is_humbargar) {
    wpsc_toggle_humbargar();
  }

  jQuery(".wpsc-setting-nav, .wpsc-humbargar-menu-item").removeClass("active");
  jQuery(
    ".wpsc-setting-nav.ai-chatbot-setting, .wpsc-humbargar-menu-item.ai-chatbot-setting",
  ).addClass("active");
  jQuery(".wpsc-humbargar-title").html(
    supportcandy.humbargar_titles.ai_chatbot_setting,
  );

  if (supportcandy.current_section !== "ai-chatbot-setting") {
    supportcandy.current_section = "ai-chatbot-setting";
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
    action: "wpsc_ai_chatbot_settings",
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
function wpsc_get_acb_general_setting() {
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

  const data = { action: "wpsc_get_acb_general_setting" };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-setting-section-body").html(response);
    wpsc_reset_responsive_style();
    wpsc_acb_toggle_dependent_settings();
    jQuery("#wpsc-acb-service-status").on(
      "change",
      wpsc_acb_toggle_dependent_settings,
    );
    jQuery("#wpsc-acb-sessions-popup-delay-status").on(
      "change",
      wpsc_acb_toggle_dependent_settings,
    );
  });
}

/**
 * Show/hide the AI chatbot general settings that only make sense while their
 * governing status select is set to "Enable" - settings tied to AI Chatbot
 * Status (session retention, footer branding, sessions per page) and
 * settings tied to Popup Delay Status (Popup Delay, Popup Display Limit).
 */
function wpsc_acb_toggle_dependent_settings() {
  jQuery(".wpsc-acb-status-dependent").toggle(
    jQuery("#wpsc-acb-service-status").val() === "1",
  );
  jQuery(".wpsc-acb-popup-delay-dependent").toggle(
    jQuery("#wpsc-acb-sessions-popup-delay-status").val() === "1",
  );
}

/**
 * Save setting
 */
function wpsc_set_acb_settings(el) {
  var form = jQuery(".wpsc-frm-acb-settings")[0];
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
      wpsc_get_acb_general_setting();
    });
}

/*
 * Reset settings
 */
function wpsc_reset_acb_settings(el, nonce) {
  var data = {
    action: "wpsc_reset_acb_settings",
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

/**
 * Get chatbot appearance settings
 */
function wpsc_get_acb_appearance_setting() {
  supportcandy.current_tab = "acb-appearance";
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
  var data = { action: "wpsc_get_acb_appearance_setting" };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-setting-section-body").html(response);
    wpsc_reset_responsive_style();
  });
}

/**
 * Set chatbot appearance settings
 */
function wpsc_set_acb_appearance_setting(el) {
  var form = jQuery(".wpsc-frm-acb-appearance-general")[0];
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
      wpsc_get_acb_appearance_setting();
    });
}

/**
 * Reset chatbot appearance settings
 */
function wpsc_reset_acb_appearance_setting(el, nonce) {
  jQuery(el).text(supportcandy.translations.please_wait);
  var data = { action: "wpsc_reset_acb_appearance_setting", _ajax_nonce: nonce };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    wpsc_get_acb_appearance_setting();
  });
}


/**
 * Get session detailed info
 *
 * @param {int} session_id
 */
function wpsc_view_session_detailed_info(session_id, nonce) {
	var url = new URL(window.location.href);
	url.searchParams.set("session_id", session_id);
	window.history.replaceState({}, null, url.toString());

  jQuery(".wpsc-setting-section-body").html(supportcandy.loader_html);
  var data = {
    action: "wpsc_view_session_detailed_info",
    session_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    jQuery(".wpsc-setting-section-body").html(res);
  });
}