jQuery(document).ready(function () {
  wpsc_reset_responsive_style();
  jQuery(window).resize(function () {
    wpsc_reset_responsive_style(true);
  });
  wpsc_document_ready();

  // for check there is value in reply box.
  jQuery(window).on("popstate", function () {
    if (wpsc_is_description_text()) {
      if (!confirm(supportcandy.translations.warning_message)) {
        return;
      }
    }
  });

  // Change the element from h1 to h2
  if (jQuery('#link-modal-title').length > 0) {
    jQuery('#link-modal-title').replaceWith('<h2 id="link-modal-title">' + jQuery('#link-modal-title').html() + '</h2>');
  }
});

/**
 * Apply responsive styles based on container size
 */
function wpsc_reset_responsive_style(isWindowResize = false) {
  var lastContainerWidth = supportcandy.lastContainerWidth
    ? supportcandy.lastContainerWidth
    : 0;
  var containerWidth = jQuery("#wpsc-container").outerWidth();

  if (isWindowResize && lastContainerWidth === containerWidth) {
    return;
  }

  supportcandy.lastContainerWidth = containerWidth;

  jQuery("#wpsc-container").hide();
  var style = "xs";
  if (containerWidth <= 768) {
    style = "xs";
  } else if (containerWidth > 768 && containerWidth <= 992) {
    style = "sm";
  } else if (containerWidth > 992 && containerWidth <= 1200) {
    style = "md";
  } else {
    style = "lg";
  }
  supportcandy.responsiveStyle = style;
  var styleURL =
    supportcandy.plugin_url +
    "framework/responsive/" +
    style +
    ".css?version=" +
    supportcandy.version;
  var styleEl =
    '<link id="wpsc-responsive-css" rel="stylesheet" type="text/css" href="' +
    styleURL +
    '" />';
  jQuery("#wpsc-responsive-css").remove();
  jQuery("head").append(styleEl);
  wpsc_apply_responsive_styles();
  jQuery("#wpsc-container").show();
}

/**
 * Apply visible elements on screen resize
 */
function wpsc_el_reset_visible() {
  jQuery(
    ".wpsc-visible-xs,.wpsc-visible-sm,.wpsc-visible-md,.wpsc-visible-lg"
  ).hide();
  jQuery(".wpsc-visible-" + supportcandy.responsiveStyle).show();
}

/**
 * Apply hidden elements on screen resize
 */
function wpsc_el_reset_hidden() {
  jQuery(
    ".wpsc-hidden-xs,.wpsc-hidden-sm,.wpsc-hidden-md,.wpsc-hidden-lg"
  ).show();
  jQuery(".wpsc-hidden-" + supportcandy.responsiveStyle).hide();
}

/**
 * Toggle humbargar menu
 */
function wpsc_toggle_humbargar() {
  var flag = supportcandy.humbargar ? true : false;
  if (flag) {
    jQuery(".wpsc-humbargar-menu").hide(
      "slide",
      { direction: "right" },
      200,
      function () {
        jQuery(".wpsc-humbargar-overlay").hide();
      }
    );
    supportcandy.humbargar = false;
  } else {
    jQuery(".wpsc-humbargar-overlay").show();
    jQuery(".wpsc-humbargar-menu").show("slide", { direction: "right" }, 200);
    supportcandy.humbargar = true;
  }
}

/**
 * Close humbargar menu if open
 */
function wpsc_close_humbargar() {
  var flag = supportcandy.humbargar ? true : false;
  if (flag) {
    jQuery(".wpsc-humbargar-menu").hide();
    jQuery(".wpsc-humbargar-overlay").hide();
    supportcandy.humbargar = false;
  }
}

/**
 * Bulk selector change
 */
function wpsc_bulk_select_change() {
  var checked = jQuery(".wpsc-bulk-selector").is(":checked");
  jQuery(".wpsc-bulk-select").prop("checked", checked);
}

/**
 * Bulk item selector change
 */
function wpsc_bulk_item_select_change() {
  var items = jQuery(".wpsc-bulk-select:checked");
  var checked = items.length === 0 ? false : true;
  jQuery(".wpsc-bulk-selector").prop("checked", checked);
}

/**
 * Show modal with loading screen
 */
function wpsc_show_modal() {
  jQuery(".wpsc-modal .inner-container").hide();
  jQuery(".wpsc-modal .loader").show();
  jQuery(".wpsc-modal").show();
}

/**
 * Close modal
 */
function wpsc_show_modal_inner_container() {
  jQuery(".wpsc-modal .loader").hide();
  jQuery(".wpsc-modal .inner-container").show(
    "slide",
    { direction: "up" },
    200
  );
}

/**
 * Close modal
 */
function wpsc_close_modal() {
  jQuery(".wpsc-modal .inner-container").hide(
    "slide",
    { direction: "up" },
    200,
    function () {
      jQuery(".wpsc-modal").hide();
    }
  );
}

/**
 * OFF toggle button click
 */
function wpsc_toggle_off(el) {
  var off = jQuery(el);
  var on = jQuery(el).next();
  var input = on.next();

  if (input.val() === 0) {
    return;
  }

  input.val(0);
  on.removeClass("active");
  off.addClass("active");
}

/**
 * ON toggle button click
 */
function wpsc_toggle_on(el) {
  var on = jQuery(el);
  var off = jQuery(el).prev();
  var input = on.next();

  if (input.val() === 1) {
    return;
  }

  input.val(1);
  off.removeClass("active");
  on.addClass("active");
}

/**
 * Scroll to top of WPSC component
 */
function wpsc_scroll_top() {
  jQuery("html, body").animate(
    {
      scrollTop: jQuery("#wpsc-container").offset().top - 42,
    },
    500
  );
}

/**
 * Toggle individual widgets
 */
function wpsc_toggle_mob_it_widgets() {
  var status = parseInt(
    jQuery(".wpsc-it-mob-widgets-inner-container").data("status")
  );

  if (!status) {
    jQuery(".wpsc-it-mob-widgets-inner-container").slideDown();
    jQuery(".wpsc-it-mob-widget-trigger-btn .down").hide();
    jQuery(".wpsc-it-mob-widget-trigger-btn .up").show();
    jQuery(".wpsc-it-mob-widgets-inner-container").data("status", 1);
  } else {
    jQuery(".wpsc-it-mob-widgets-inner-container").slideUp();
    jQuery(".wpsc-it-mob-widget-trigger-btn .up").hide();
    jQuery(".wpsc-it-mob-widget-trigger-btn .down").show();
    jQuery(".wpsc-it-mob-widgets-inner-container").data("status", 0);
  }
}

/**
 *  Get refresh current ticket
 */
function wpsc_it_ab_refresh(ticket_id) {
  if (wpsc_is_description_text()) {
    if (confirm(supportcandy.translations.warning_message)) {
      wpsc_clear_saved_draft_reply(ticket_id);
    } else {
      return;
    }
  }

  wpsc_get_individual_ticket(ticket_id);
}

/**
 * Get close current ticket modal UI
 */
function wpsc_it_close_ticket(ticket_id, nonce) {
  if (wpsc_is_description_text()) {
    if (!confirm(supportcandy.translations.warning_message)) return;
  }

  var flag = confirm(supportcandy.translations.confirm);
  if (flag) {
    if (wpsc_is_description_text()) {
      wpsc_clear_saved_draft_reply(ticket_id);
    }
  } else {
    return;
  }

  var data = { action: "wpsc_it_close_ticket", ticket_id, _ajax_nonce: nonce };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    wpsc_run_ajax_background_process();
    wpsc_after_close_ticket(ticket_id);
  });
}

/**
 * Get duplicate ticket
 */
function wpsc_it_get_duplicate_ticket(ticket_id, nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_get_duplicate_ticket",
    ticket_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Set duplicate ticket
 */
function wpsc_it_set_duplicate_ticket(el, ticket_id) {
  if (wpsc_is_description_text()) {
    if (confirm(supportcandy.translations.warning_message)) {
      wpsc_clear_saved_draft_reply(ticket_id);
    } else {
      return;
    }
  }

  var form = jQuery("form.duplicate")[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
  jQuery(el).text(supportcandy.translations.please_wait);

  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: "POST",
      data: dataform,
      processData: false,
      contentType: false,
    })
    .done(function (response) {
      wpsc_close_modal();
      wpsc_get_individual_ticket(response.ticket_id);
    });
}

/**
 * Get delete ticket
 */
function wpsc_it_delete_ticket(ticket_id, nonce) {
  if (wpsc_is_description_text()) {
    if (!confirm(supportcandy.translations.warning_message)) {
      return;
    } else {
      var is_tinymce =
        typeof tinyMCE != "undefined" &&
        tinyMCE.activeEditor &&
        !tinyMCE.activeEditor.isHidden();
      if (is_tinymce && tinymce.get("description")) {
        var description = tinyMCE.get("description").setContent("");
      } else {
        var description = jQuery("#description").val("");
      }
      wpsc_clear_saved_draft_reply(ticket_id);
    }
  }

  var flag = confirm(supportcandy.translations.confirm);
  if (!flag) {
    return;
  }

  var data = { action: "wpsc_it_delete_ticket", ticket_id, _ajax_nonce: nonce };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    wpsc_get_ticket_list();
    wpsc_run_ajax_background_process();
  });
}

/**
 * Restore ticket
 */
function wpsc_it_ticket_restore(ticket_id, nonce) {
  var flag = confirm(supportcandy.translations.confirm);
  if (!flag) {
    return;
  }

  var data = {
    action: "wpsc_it_ticket_restore",
    ticket_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    wpsc_get_individual_ticket(ticket_id);
  });
}

/**
 * Delete permanently
 */
function wpsc_it_delete_permanently(ticket_id, nonce) {
  var flag = confirm(supportcandy.translations.confirm);
  if (!flag) {
    return;
  }

  var data = {
    action: "wpsc_it_delete_permanently",
    ticket_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    wpsc_get_ticket_list();
  });
}

/**
 *  Get edit ticket subject modal UI
 */
function wpsc_it_get_edit_subject(ticket_id) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_get_edit_subject",
    ticket_id,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 *  Set edit ticket subject
 */
function wpsc_it_set_edit_subject(el, ticket_id) {
  if (wpsc_is_description_text()) {
    if (!confirm(supportcandy.translations.warning_message)) {
      wpsc_clear_saved_draft_reply(ticket_id);
    } else {
      return;
    }
  }

  var form = jQuery("form.edit-subject")[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
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
      wpsc_close_modal();
      wpsc_get_individual_ticket(ticket_id);
    });
}

/**
 * Get change status modal UI
 */
function wpsc_it_get_edit_ticket_status(ticket_id, nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_get_edit_ticket_status",
    ticket_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    // Set to modal.
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    // Display modal.
    wpsc_show_modal_inner_container();
  });
}

/**
 * Update ticket status
 *
 * @param {*} el
 */
function wpsc_it_set_edit_ticket_status(el, ticket_id) {
  if (wpsc_is_description_text()) {
    if (confirm(supportcandy.translations.warning_message)) {
      wpsc_clear_saved_draft_reply(ticket_id);
    } else {
      return;
    }
  }

  var form = jQuery("form.frm-edit-ticket-status")[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
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
      wpsc_close_modal();
      wpsc_get_individual_ticket(ticket_id);
      wpsc_run_ajax_background_process();
    });
}

/**
 * Get additional recipients modal UI
 */
function wpsc_it_get_add_ar(ticket_id, nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_get_add_ar",
    ticket_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Update additional recipients
 *
 * @param {*} el
 */
function wpsc_it_set_add_ar(el, ticket_id) {
  if (wpsc_is_description_text()) {
    if (confirm(supportcandy.translations.warning_message)) {
      wpsc_clear_saved_draft_reply(ticket_id);
    } else {
      return;
    }
  }

  var form = jQuery("form.additional-recipients")[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
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
      wpsc_close_modal();
      wpsc_get_individual_ticket(ticket_id);
    });
}

/**
 * Get assigned agents modal UI
 */
function wpsc_it_get_edit_assigned_agents(ticket_id, nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_get_edit_assigned_agents",
    ticket_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Update assigned agents
 *
 * @param {*} el
 */
function wpsc_it_set_edit_assigned_agents(el, ticket_id, uniqueId) {
  if (wpsc_is_description_text()) {
    if (confirm(supportcandy.translations.warning_message)) {
      wpsc_clear_saved_draft_reply(ticket_id);
    } else {
      return;
    }
  }

  var form = jQuery("form.change-assignee." + uniqueId)[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
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
      wpsc_close_modal();
      if (res.viewPermission) {
        wpsc_get_individual_ticket(ticket_id);
      } else {
        wpsc_get_ticket_list();
      }
      wpsc_run_ajax_background_process();
    });
}

/**
 * Get raised by modal UI
 */
function wpsc_it_get_edit_raised_by(ticket_id, nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_get_edit_raised_by",
    ticket_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    // Set to modal.
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    // Display modal.
    wpsc_show_modal_inner_container();
  });
}

/**
 * Update raised by
 *
 * @param {*} el
 */
function wpsc_it_set_edit_raised_by(el, ticket_id, uniqueId) {
  if (wpsc_is_description_text()) {
    if (confirm(supportcandy.translations.warning_message)) {
      wpsc_clear_saved_draft_reply(ticket_id);
    } else {
      return;
    }
  }

  var type = jQuery(".type." + uniqueId).val();
  if (type == "new") {
    var name = jQuery(".name." + uniqueId)
      .val()
      .trim();
    var email = jQuery(".email." + uniqueId)
      .val()
      .trim();
    if (!name || !email) {
      return;
    }

    if (!validateEmail(email)) {
      alert(supportcandy.translations.raisedByEditWidget.invalidEmail);
      return;
    }
  }

  var form = jQuery("form.change-raised-by." + uniqueId)[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
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
      wpsc_close_modal();
      wpsc_get_individual_ticket(ticket_id);
    });
}

/**
 * Get ticket fields modal UI
 */
function wpsc_it_get_edit_ticket_fields(ticket_id, nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_get_edit_ticket_fields",
    ticket_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Update ticket fields
 *
 * @param {*} el
 */
function wpsc_it_set_edit_ticket_fields(el, ticket_id) {
  if (wpsc_is_description_text()) {
    if (confirm(supportcandy.translations.warning_message)) {
      wpsc_clear_saved_draft_reply(ticket_id);
    } else {
      return;
    }
  }

  var form = jQuery("form.change-ticket-fields")[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
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
      wpsc_close_modal();
      wpsc_get_individual_ticket(ticket_id);
    });
}

/**
 * Get agent only filds modal UI
 */
function wpsc_it_get_edit_agentonly_fields(ticket_id, nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_get_edit_agentonly_fields",
    ticket_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Update agent only fields
 *
 * @param {*} el
 */
function wpsc_it_set_edit_agentonly_fields(el, ticket_id) {
  if (wpsc_is_description_text()) {
    if (confirm(supportcandy.translations.warning_message)) {
      wpsc_clear_saved_draft_reply(ticket_id);
    } else {
      return;
    }
  }

  var form = jQuery("form.change-agentonly-fields")[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
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
      wpsc_close_modal();
      wpsc_get_individual_ticket(ticket_id);
    });
}

/**
 * Get edit thread
 */
function wpsc_it_get_edit_thread(el, ticket_id, thread_id, nonce) {
  var thread = jQuery(el).closest(".wpsc-thread");
  thread.html(supportcandy.loader_html);

  var data = {
    action: "wpsc_it_get_edit_thread",
    ticket_id,
    thread_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    thread.html(res);
  });
}

/**
 * Set edit thread
 */
function wpsc_it_set_edit_thread(el, uniqueId) {
  var form = jQuery("form.edit-thread")[0];
  var dataform = new FormData(form);

  var is_tinymce =
    typeof tinyMCE != "undefined" &&
    tinyMCE.activeEditor &&
    !tinyMCE.activeEditor.isHidden();
  var description =
    is_tinymce && tinymce.get(uniqueId)
      ? tinyMCE.get(uniqueId).getContent()
      : jQuery("#" + uniqueId)
          .val()
          .trim();
  if (!description) {
    return;
  }

  dataform.append("thread_content", description);

  var thread = jQuery(el).closest(".wpsc-thread");
  thread.html(supportcandy.loader_html);

  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: "POST",
      data: dataform,
      processData: false,
      contentType: false,
    })
    .done(function (res) {
      if (thread.next().length > 0) {
        thread.next().before(res);
      } else {
        thread.parent().append(res);
      }
      thread.remove();
    });
}

/**
 * Delete thread
 */
function wpsc_it_thread_delete(el, ticket_id, thread_id, nonce) {
  var flag = confirm(supportcandy.translations.confirm);
  if (!flag) {
    return;
  }

  var thread = jQuery(el).closest(".wpsc-thread");
  thread.html(supportcandy.loader_html);

  var data = {
    action: "wpsc_it_thread_delete",
    ticket_id,
    thread_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    if (res.trim()) {
      if (thread.next().length > 0) {
        thread.next().before(res);
      } else {
        thread.parent().append(res);
      }
    }
    thread.remove();
  });
}

/**
 * Get thread html
 */
function wpsc_it_get_thread(el, ticket_id, thread_id, _ajax_nonce) {
  var thread = jQuery(el).closest(".wpsc-thread");
  thread.html(supportcandy.loader_html);

  var data = {
    action: "wpsc_it_get_thread",
    ticket_id,
    thread_id,
    _ajax_nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    if (thread.next().length > 0) {
      thread.next().before(res);
    } else {
      thread.parent().append(res);
    }
    thread.remove();
  });
}

/**
 * View thread log
 */
function wpsc_it_view_thread_log(ticket_id, thread_id, log_id, _ajax_nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_view_thread_log",
    ticket_id,
    thread_id,
    log_id,
    _ajax_nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * View thread
 */
function wpsc_it_view_deleted_thread(ticket_id, thread_id, _ajax_nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_view_deleted_thread",
    ticket_id,
    thread_id,
    _ajax_nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Restore thread
 */
function wpsc_it_restore_thread(ticket_id, thread_id, _ajax_nonce) {
  var flag = confirm(supportcandy.translations.confirm);
  if (!flag) {
    return;
  }

  wpsc_close_modal();
  var thread = jQuery(".wpsc-thread." + thread_id);
  thread.html(supportcandy.loader_html);

  var data = {
    action: "wpsc_it_restore_thread",
    ticket_id,
    thread_id,
    _ajax_nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    if (thread.next().length > 0) {
      thread.next().before(res);
    } else {
      thread.parent().append(res);
    }
    thread.remove();
  });
}

/**
 * Restore thread
 */
function wpsc_it_thread_delete_permanently(ticket_id, thread_id, _ajax_nonce) {
  var flag = confirm(supportcandy.translations.confirm);
  if (!flag) {
    return;
  }

  wpsc_close_modal();
  var thread = jQuery(".wpsc-thread." + thread_id);
  thread.html(supportcandy.loader_html);

  var data = {
    action: "wpsc_it_thread_delete_permanently",
    ticket_id,
    thread_id,
    _ajax_nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    thread.remove();
  });
}

/**
 * Get macros modal UI
 */
function wpsc_get_macros() {
  wpsc_show_modal();
  var data = {
    action: "wpsc_get_macros",
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    // Set to modal.
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    // Display modal.
    wpsc_show_modal_inner_container();
    jQuery("input[type=text]").focus();
  });
}

/**
 * Add and condition item
 * @param {*} el
 * @param {*} uniqueId
 */
function wpsc_add_and_condition(el, uniqueId) {
  var container = jQuery(el)
    .closest(".wpsc-form-filter-container")
    .find(".and-container");
  container.append(jQuery(".and-template." + uniqueId).html());
  container = container.find(".and-item:last-child .or-container");
  container.append(jQuery(".or-template." + uniqueId).html());
  container.find("select").selectWoo();
}

/**
 * Add and condition item
 * @param {*} el
 * @param {*} uniqueId
 */
function wpsc_add_or_condition(el, uniqueId) {
  var container = jQuery(el).closest(".and-item").find(".or-container");
  container.append(jQuery(".or-template." + uniqueId).html());
  container.find(":last-child").find("select").selectWoo();
}

/**
 * Remove condition item
 * @param {*} el
 */
function wpsc_remove_condition_item(el) {
  var andItem = jQuery(el).closest(".and-item");
  jQuery(el).closest(".wpsc-form-filter-item").remove();
  if (andItem.find(".or-container").children().length === 0) {
    jQuery(andItem).remove();
  }
}

/**
 * Get JSON string for the condition input of given name
 * @param {*} name
 */
function wpsc_get_condition_json(name) {
  var conditions = [];
  jQuery(".wpsc-form-filter-container." + name + " .and-item").each(
    function () {
      var andCondition = [];
      jQuery(this)
        .find(".wpsc-form-filter-item")
        .each(function () {
          var slug = jQuery(this).find("select.filter").first().val();
          if (!slug) {
            return;
          }
          var operator = jQuery(this).find("select.operator").first().val();
          if (!operator) {
            return;
          }
          var operand_val_1 = jQuery(this).find(".operand_val_1").first().val();
          if (!operand_val_1) {
            return;
          }
          var filter = { slug, operator, operand_val_1 };
          if (operator === "BETWEEN") {
            var operand_val_2 = jQuery(this)
              .find(".operand_val_2")
              .first()
              .val();
            if (!operand_val_2) {
              return;
            }
            filter.operand_val_2 = operand_val_2;
          }
          andCondition.push(filter);
        });
      conditions.push(andCondition);
    }
  );
  return conditions;
}

/**
 * Get form filter oprators
 */
function wpsc_get_ticket_filter_operators(el, nonce) {
  var content = jQuery(el).closest(".content");
  var slug = jQuery(el).val().trim();

  content.find(".conditional, .wpsc-inline-loader").remove();
  if (!slug) {
    return;
  }

  content.append(supportcandy.inline_loader);
  var data = {
    action: "wpsc_get_ticket_filter_operators",
    slug: slug,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    content.find(".wpsc-inline-loader").remove();
    content.append(response);
  });
}

/**
 * Get ticket filter value input(s)
 */
function wpsc_get_ticket_filter_operands(el, id, nonce) {
  var content = jQuery(el).closest(".content");
  var operator = jQuery(el).val().trim();

  content.find(".conditional.operand, .wpsc-inline-loader").remove();
  if (!operator) {
    return;
  }

  content.append(supportcandy.inline_loader);
  var data = {
    action: "wpsc_get_ticket_filter_operands",
    operator,
    id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    content.find(".wpsc-inline-loader").remove();
    content.append(response);
  });
}

/**
 * Single file attachment upload handler
 */
function wpsc_set_attach_single(el, uniqueId, slug) {
  var fileAttachment = el.files[0];
  wpsc_file_upload(fileAttachment, uniqueId, slug, true);
  jQuery(el).val("");
}

/**
 * Myltiple file attachment upload handler
 */
function wpsc_set_attach_multiple(el, uniqueId, slug) {
  jQuery.each(el.files, function (index, fileAttachment) {
    wpsc_file_upload(fileAttachment, uniqueId, slug);
  });
  jQuery(el).val("");
}

/**
 * Trigger description attachments
 */
function wpsc_trigger_desc_attachments(uniqueId) {
  jQuery("input." + uniqueId).trigger("click");
}

/**
 * Remove an attachment
 */
function wpsc_remove_attachment(el) {
  var single = jQuery(el).data("single");
  var uniqueId = jQuery(el).data("uniqueid");
  jQuery(el).closest(".wpsc-editor-attachment").remove();
  if (single) {
    jQuery("input." + uniqueId).show();
  }
}

/**
 * Attachment upload progess update
 */
function wpscAttachmentUploadProgress(e) {
  if (e.lengthComputable) {
    var max = e.total;
    var current = e.loaded;
    var progrss = current / max;
    e.currentTarget.attachment
      .find(".attachment-waiting")
      .circleProgress("value", progrss);
  }
}

/**
 * Change raised by or create ticket as
 */
function wpsc_get_change_create_as(nonce) {
  wpsc_show_modal();
  var data = { action: "wpsc_get_change_create_as", _ajax_nonce: nonce };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    // Set to modal.
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    // Display modal.
    wpsc_show_modal_inner_container();
  });
}

/**
 * Change create as
 */
function wpsc_set_change_create_as(uniqueId, _ajax_nonce) {
  var name = jQuery("input.name." + uniqueId)
    .val()
    .trim();
  var email = jQuery("input.email." + uniqueId)
    .val()
    .trim();
  if (!name || !email) {
    alert(supportcandy.translations.req_fields_missing);
    return;
  }

  if (!validateEmail(email)) {
    alert(supportcandy.translations.invalidEmail);
    return;
  }

  var data = { action: "wpsc_add_new_create_as", name, email, _ajax_nonce };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery("input.name").val(response.name);
    jQuery("input.email").val(response.email);

    // add option and select.
    if (jQuery("select.create-as").val() != response.id) {
      var newOption = new Option(response.label, response.id, false, false);
      jQuery("select.create-as").append(newOption);
      jQuery("select.create-as").val(response.id).trigger("change");
    }

    wpsc_close_modal();
    wpsc_after_change_create_as();
  });
}

/**
 * Check visibility conditions
 */
function wpsc_check_tff_visibility() {
  var conditionalFields = jQuery(".wpsc-tff.conditional");
  if (conditionalFields.length === 0) {
    return;
  }

  jQuery('#wpsc-ct-submit').attr( 'disabled', true );
  jQuery('.wpsc-ct-loader').html( supportcandy.inline_loader );
  jQuery("select.create-as").attr( "disabled", true );

  var form = jQuery("form.wpsc-create-ticket")[0];
  var dataform = new FormData(form);

  var is_tinymce =
    typeof tinyMCE != "undefined" &&
    tinyMCE.activeEditor &&
    !tinyMCE.activeEditor.isHidden();
  if (is_tinymce && tinymce.get("description")) {
    var description = tinyMCE.get("description").getContent();
  } else {
    var description = jQuery("#description").val();
  }

  dataform.append("description", description);
  dataform.append("action", "wpsc_check_tff_visibility");
  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: "POST",
      data: dataform,
      processData: false,
      contentType: false,
    })
    .done(function (results) {
      jQuery.each(results, function (key, val) {
        if (parseInt(val) === 0) {
          jQuery(".wpsc-tff." + key).addClass("wpsc-hidden");
          jQuery(".wpsc-tff." + key).removeClass("wpsc-visible");
        } else {
          jQuery(".wpsc-tff." + key).removeClass("wpsc-hidden");
          jQuery(".wpsc-tff." + key).addClass("wpsc-visible");
        }
      });
      jQuery('#wpsc-ct-submit').removeAttr("disabled");
      jQuery('.wpsc-ct-loader').html('');
      jQuery("select.create-as").removeAttr( "disabled" );
    }).fail(function(xhr, status, error) {
      console.error('AJAX request failed:', status, error);  
      jQuery('#wpsc-ct-submit').removeAttr("disabled");
      jQuery('.wpsc-ct-loader').html('');
      jQuery("select.create-as").removeAttr( "disabled" );
    });
}

/**
 * Validate input for email address
 */
function validateEmail(email) {
  var re =
    /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
  return re.test(email);
}

/**
 * Validate input for URL
 */
function validateURL(url) {
  var re =
    /^(?:(?:https?|ftp):\/\/)?(?:(?!(?:10|127)(?:\.\d{1,3}){3})(?!(?:169\.254|192\.168)(?:\.\d{1,3}){2})(?!172\.(?:1[6-9]|2\d|3[0-1])(?:\.\d{1,3}){2})(?:[1-9]\d?|1\d\d|2[01]\d|22[0-3])(?:\.(?:1?\d{1,2}|2[0-4]\d|25[0-5])){2}(?:\.(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-4]))|(?:(?:[a-z\u00a1-\uffff0-9]-*)*[a-z\u00a1-\uffff0-9]+)(?:\.(?:[a-z\u00a1-\uffff0-9]-*)*[a-z\u00a1-\uffff0-9]+)*(?:\.(?:[a-z\u00a1-\uffff]{2,})))(?::\d{2,5})?(?:\/\S*)?$/;
  return re.test(url);
}

/**
 * Validate input for number
 */
function validateNumber(number) {
  var re = /^[0-9]+$/;
  return re.test(number);
}

/**
 * Change event for filter
 */
function wpsc_tl_filter_change(el) {
  supportcandy.prevFilter = supportcandy.ticketList.filters.filterSlug;
  var filterSlug = jQuery(el).val().trim();
  if (filterSlug != "custom") {
    var filters = { filterSlug };
    supportcandy.ticketList.filters = filters;
    wpsc_get_tickets();
  } else {
    wpsc_tl_get_custom_filter();
  }
}

/**
 * Change ticket list page
 */
function wpsc_tl_set_page(page) {
  switch (page) {
    case "first":
      if (supportcandy.ticketList.pagination.current_page == 1) {
        return;
      }
      supportcandy.ticketList.filters.page_no = 1;
      break;

    case "prev":
      if (supportcandy.ticketList.pagination.current_page < 2) {
        return;
      }
      supportcandy.ticketList.filters.page_no =
        supportcandy.ticketList.pagination.current_page - 1;
      break;

    case "next":
      if (!supportcandy.ticketList.pagination.has_next_page) {
        return;
      }
      supportcandy.ticketList.filters.page_no =
        supportcandy.ticketList.pagination.current_page + 1;
      break;

    case "last":
      if (
        supportcandy.ticketList.pagination.current_page ==
        supportcandy.ticketList.pagination.total_pages
      ) {
        return;
      }
      supportcandy.ticketList.filters.page_no =
        supportcandy.ticketList.pagination.total_pages;
      break;
  }
  wpsc_get_tickets();
}

/**
 * Reset ticket list filters
 */
function wpsc_tl_reset_filter() {
  var filters = { filterSlug: "" };
  supportcandy.ticketList.filters = filters;
  wpsc_get_tickets();
}

/**
 * Search input keyup
 */
function wpsc_tl_search_keyup(e, el) {
  if (e.keyCode !== 13) {
    return;
  }
  var search = jQuery(el).val().trim();
  supportcandy.ticketList.filters.search = search;
  supportcandy.ticketList.filters.page_no = 1;
  wpsc_get_tickets();
}

/**
 * Apply filters
 */
function wpsc_tl_apply_filter_btn_click() {
  supportcandy.ticketList.filters.search = jQuery("input.wpsc-search-input")
    .val()
    .trim();
  supportcandy.ticketList.filters.orderby = jQuery(
    "select.wpsc-input-sort-by"
  ).val();
  supportcandy.ticketList.filters.order = jQuery(
    "select.wpsc-input-sort-order"
  ).val();
  supportcandy.ticketList.filters.page_no = 1;
  wpsc_get_tickets();
}

/**
 * Get custom filter UI
 */
function wpsc_tl_get_custom_filter() {
  wpsc_show_modal();
  var data = {
    action: "wpsc_get_tl_custom_filter",
    _ajax_nonce: supportcandy.nonce,
  };
  if (supportcandy.ticketList.filters.filterSlug == "custom") {
    data.filters = supportcandy.ticketList.filters;
  }
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    // Set to modal.
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    // Display modal.
    wpsc_show_modal_inner_container();
  });
}

/**
 * Apply custom filter
 */
function wpsc_tl_apply_custom_filter(el) {
  var filters = wpsc_get_condition_json("custom_filters");
  if (
    filters.length === 0 ||
    (filters.length === 1 && filters[0].length === 0)
  ) {
    alert(supportcandy.translations.req_fields_missing);
    return;
  }

  filters = {
    filterSlug: "custom",
    "parent-filter": jQuery(
      ".wpsc-tl-custom-filter select[name=parent-filter]"
    ).val(),
    filters: JSON.stringify(filters),
    orderby: jQuery(".wpsc-tl-custom-filter select[name=sort-by]").val(),
    order: jQuery(".wpsc-tl-custom-filter select[name=sort-order]").val(),
    page_no: 1,
  };

  supportcandy.ticketList.filters = filters;
  wpsc_close_modal();
  wpsc_get_tickets();
}

/**
 * Edit ticket list filter
 */
function wpsc_tl_edit_filter() {
  if (supportcandy.ticketList.filters.filterSlug == "custom") {
    wpsc_tl_get_custom_filter();
  } else {
    wpsc_tl_get_edit_saved_filter();
  }
}

/**
 * Add saved filter
 */
function wpsc_tl_add_saved_filter() {
  var filters = wpsc_get_condition_json("custom_filters");
  if (
    filters.length === 0 ||
    (filters.length === 1 && filters[0].length === 0)
  ) {
    alert(supportcandy.translations.req_fields_missing);
    return;
  }

  filters = {
    "parent-filter": jQuery(
      ".wpsc-tl-custom-filter select[name=parent-filter]"
    ).val(),
    filters: JSON.stringify(filters),
    orderby: jQuery(".wpsc-tl-custom-filter select[name=sort-by]").val(),
    order: jQuery(".wpsc-tl-custom-filter select[name=sort-order]").val(),
  };

  supportcandy.tempFilters = filters;
  wpsc_close_modal();
  setTimeout(function () {
    wpsc_tl_get_add_saved_filter();
  }, 500);
}

/**
 * Get add saved filter ui
 */
function wpsc_tl_get_add_saved_filter() {
  wpsc_show_modal();
  var data = { action: "wpsc_tl_get_add_saved_filter" };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    // Set to modal.
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    // Display modal.
    wpsc_show_modal_inner_container();
  });
}

/**
 * Save and apply custom filter
 */
function wpsc_tl_set_add_saved_filter(el, nonce) {
  var label = jQuery("input.wpsc-cf-label").val().trim();
  if (!label) {
    alert(supportcandy.translations.req_fields_missing);
    return;
  }

  jQuery(".wpsc-modal-footer button").attr("disabled", true);
  jQuery(el).text(supportcandy.translations.please_wait);

  var data = {
    action: "wpsc_tl_set_add_saved_filter",
    filters: supportcandy.tempFilters,
    _ajax_nonce: nonce,
  };
  data.filters.label = label;

  jQuery.post(supportcandy.ajax_url, data, function (response) {
    supportcandy.ticketList.filters = { filterSlug: response.slug };
    wpsc_close_modal();
    wpsc_get_ticket_list();
  });
}

/**
 * Get edit saved filter modal
 */
function wpsc_tl_get_edit_saved_filter() {
  wpsc_show_modal();
  var data = {
    action: "wpsc_tl_get_edit_saved_filter",
    filterSlug: supportcandy.ticketList.filters.filterSlug,
    _ajax_nonce: supportcandy.nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    // Set to modal.
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    // Display modal.
    wpsc_show_modal_inner_container();
  });
}

/**
 * Set edit saved filter
 */
function wpsc_tl_set_edit_saved_filter(el) {
  var label = jQuery("input.wpsc-cf-label").val().trim();
  if (!label) {
    alert(supportcandy.translations.req_fields_missing);
    return;
  }

  var filters = wpsc_get_condition_json("custom_filters");
  if (
    filters.length === 0 ||
    (filters.length === 1 && filters[0].length === 0)
  ) {
    alert(supportcandy.translations.req_fields_missing);
    return;
  }

  jQuery(".wpsc-modal-footer button").attr("disabled", true);
  jQuery(el).text(supportcandy.translations.please_wait);

  var form = jQuery(".wpsc-tl-custom-filter")[0];
  var dataform = new FormData(form);
  dataform.append("filters", JSON.stringify(filters));

  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: "POST",
      data: dataform,
      processData: false,
      contentType: false,
    })
    .done(function (res) {
      wpsc_close_modal();
      wpsc_get_ticket_list();
    });
}

/**
 * Delete saved filter
 */
function wpsc_tl_delete_saved_filter() {
  var flag = confirm(supportcandy.translations.confirm);
  if (!flag) {
    return;
  }

  var data = {
    action: "wpsc_tl_delete_saved_filter",
    slug: supportcandy.ticketList.filters.filterSlug,
    _ajax_nonce: supportcandy.nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    supportcandy.ticketList.filters = { filterSlug: "" };
    wpsc_get_ticket_list();
  });
}

/**
 * Close custom filter modal
 */
function wpsc_tl_close_custom_filter_modal() {
  jQuery("select.wpsc-input-filter").val(supportcandy.prevFilter);
  wpsc_close_modal();
}

/**
 * Get form filter oprators
 */
function wpsc_tc_get_operators(el, nonce) {
  var content = jQuery(el).closest(".content");
  var slug = jQuery(el).val().trim();

  content.find(".conditional, .wpsc-inline-loader").remove();
  if (!slug) {
    return;
  }

  content.append(supportcandy.inline_loader);
  var data = {
    action: "wpsc_tc_get_operators",
    slug: slug,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    content.find(".wpsc-inline-loader").remove();
    content.append(response);
  });
}

/**
 * Get email notification condition operands
 */
function wpsc_tc_get_operand(el, slug, nonce) {
  var content = jQuery(el).closest(".content");
  var operator = jQuery(el).val().trim();

  content.find(".conditional.operand, .wpsc-inline-loader").remove();
  if (!operator) {
    return;
  }

  content.append(supportcandy.inline_loader);
  var data = {
    action: "wpsc_tc_get_operand",
    operator,
    slug,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    content.find(".wpsc-inline-loader").remove();
    content.append(response);
  });
}

/**
 * Add image to editor
 *
 * @param {*} editor
 */
function wpsc_add_custom_image_tinymce(editor, nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_add_custom_image_tinymce",
    editor_id: editor.id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    // Set to modal.
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    // Display modal.
    wpsc_show_modal_inner_container();
  });
}

/**
 * Check whether given url has image or not
 *
 * @param {*} url
 */
function isValidImageURL(url) {
  if (typeof url !== "string") {
    return false;
  }
  return !!url.match(/\w+\.(jpg|jpeg|gif|png|tiff|bmp)$/gi);
}

/**
 * Upload image to tinymce editor
 *
 * @param {*} el
 */
function wpsc_insert_editor_img(ed) {
  if (
    jQuery("#wpsc-tinymce-image-url").val().trim() === "" &&
    validateURL(jQuery("#wpsc-tinymce-image-url").val().trim()) &&
    isValidImageURL(jQuery("#wpsc-tinymce-image-url").val().trim())
  ) {
    alert(supportcandy.translations.req_fields_error);
    return;
  }

  if (jQuery("#wpsc-tinymce-image-width").val().trim() === "") {
    alert(supportcandy.translations.req_fields_error);
    return;
  }

  if (jQuery("#wpsc-tinymce-image-height").val().trim() === "") {
    alert(supportcandy.translations.req_fields_error);
    return;
  }

  var is_tinymce =
    typeof tinyMCE != "undefined" &&
    tinyMCE.activeEditor &&
    !tinyMCE.activeEditor.isHidden();
  if (!is_tinymce) {
    return;
  }

  wpsc_close_modal();

  var source = jQuery("#wpsc-tinymce-image-url").val().trim();
  var height = jQuery("#wpsc-tinymce-image-height").val().trim();
  var width = jQuery("#wpsc-tinymce-image-width").val().trim();
  tinymce
    .get(ed)
    .execCommand(
      "mceInsertContent",
      false,
      '<img height="' +
        height +
        '" width="' +
        width +
        '" src="' +
        source +
        '"/>'
    );
}

/**
 * Add image to editor
 *
 * @param {*} editor
 */
function wpsc_edit_custom_image_tinymce(editor, image_obj, nonce) {
  if (!(editor && image_obj)) {
    alert(supportcandy.translations.req_fields_error);
    return;
  }

  wpsc_show_modal();
  var data = {
    action: "wpsc_edit_custom_image_tinymce",
    editor_id: editor.id,
    height: image_obj.height,
    width: image_obj.width,
    src: image_obj.src,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    // Set to modal.
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    // Display modal.
    wpsc_show_modal_inner_container();
  });
}

/**
 * Get create ticket from thread
 */
function wpsc_it_thread_new_ticket(el, ticket_id, thread_id, nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_thread_new_ticket",
    ticket_id,
    thread_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Get thread info
 *
 * @param {*} thread_id
 */
function wpsc_it_thread_info(el, ticket_id, thread_id, nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_it_thread_info",
    thread_id,
    ticket_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Set thread new ticket
 */
function wpsc_it_set_thread_new_ticket(el, thread_id, nonce) {
  var form = jQuery("form.new_ticket_from_thread")[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
  jQuery(el).text(supportcandy.translations.please_wait);
  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: "POST",
      data: dataform,
      processData: false,
      contentType: false,
    })
    .done(function (response) {
      wpsc_close_modal();
      wpsc_get_individual_ticket(response.ticket_id);
    });
}

/**
 * Get customer other tickets
 */
function wpsc_get_rb_other_tickets(el, ticket_id, nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_get_rb_other_tickets",
    ticket_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Get raised by info
 */
function wpsc_get_rb_info(el, ticket_id, nonce) {
  wpsc_show_modal();
  var data = { action: "wpsc_get_rb_info", ticket_id, _ajax_nonce: nonce };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 *  Thread expander
 */
function wpsc_ticket_thread_expander_toggle(el) {
  var height = parseInt(jQuery(el).parent().find(".thread-text").height());
  if (height === 100) {
    jQuery(el).parent().find(".thread-text").height("auto");
    jQuery(el).text(supportcandy.translations.view_less);
  } else {
    jQuery(el).parent().find(".thread-text").height(100);
    jQuery(el).text(supportcandy.translations.view_more);
  }
}

/**
 * Get change bulk status
 */
function wpsc_bulk_change_status(nonce) {
  var items = jQuery(".wpsc-bulk-select:checked");
  var checked = items.length === 0 ? false : true;
  jQuery(".wpsc-bulk-selector").prop("checked", checked);
  if (items.length != 0) {
    var ticket_ids = jQuery(".wpsc-bulk-select:checked")
      .map(function () {
        return this.value;
      })
      .get();
    wpsc_show_modal();
    var data = {
      action: "wpsc_bulk_change_status",
      ticket_ids,
      _ajax_nonce: nonce,
    };
    jQuery.post(supportcandy.ajax_url, data, function (response) {
      // Set to modal.
      jQuery(".wpsc-modal-header").text(response.title);
      jQuery(".wpsc-modal-body").html(response.body);
      jQuery(".wpsc-modal-footer").html(response.footer);
      // Display modal.
      wpsc_show_modal_inner_container();
    });
  }
}

/**
 * Load older threads
 */
function wpsc_load_older_threads(el, ticket_id) {
  if (supportcandy.reply_form_position === "top") {
    jQuery(".wpsc-it-thread-section-container").append(
      supportcandy.loader_html
    );
  } else {
    jQuery(".wpsc-it-thread-section-container").prepend(
      supportcandy.loader_html
    );
  }

  var oldBtnContainer = jQuery(el).parent();
  oldBtnContainer.hide();

  var data = {
    action: "wpsc_load_older_threads",
    ticket_id,
    last_thread: supportcandy.threads.last_thread,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-it-thread-section-container").find(".wpsc-loader").remove();
    // add threads.
    if (supportcandy.reply_form_position === "top") {
      jQuery(".wpsc-it-thread-section-container").append(response.threads);
    } else {
      var scrollHeightBody = jQuery("body").prop("scrollHeight");
      var windowScrollTop = jQuery(window).scrollTop();
      jQuery(".wpsc-it-thread-section-container").prepend(response.threads);
      var scrollDiff = jQuery("body").prop("scrollHeight") - scrollHeightBody;
      jQuery(window).scrollTop(windowScrollTop + scrollDiff);
    }
    // show btn if there are more threads available.
    if (response.has_next_page) {
      supportcandy.threads.last_thread = response.last_thread;
      oldBtnContainer.show();
    }
  });
}

/**
 * Set change bulk status
 */
function wpsc_set_bulk_change_status(el) {
  var form = jQuery("form.frm-edit-bulk-status")[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
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
      wpsc_close_modal();
      wpsc_get_ticket_list();
      wpsc_run_ajax_background_process();
    });
}

/**
 * Get bulk assign agent
 */
function wpsc_bulk_assign_agents(nonce) {
  var items = jQuery(".wpsc-bulk-select:checked");
  var checked = items.length === 0 ? false : true;
  jQuery(".wpsc-bulk-selector").prop("checked", checked);
  if (items.length != 0) {
    var ticket_ids = jQuery(".wpsc-bulk-select:checked")
      .map(function () {
        return this.value;
      })
      .get();
    wpsc_show_modal();
    var data = {
      action: "wpsc_bulk_assign_agents",
      ticket_ids,
      _ajax_nonce: nonce,
    };
    jQuery.post(supportcandy.ajax_url, data, function (response) {
      // Set to modal.
      jQuery(".wpsc-modal-header").text(response.title);
      jQuery(".wpsc-modal-body").html(response.body);
      jQuery(".wpsc-modal-footer").html(response.footer);
      // Display modal.
      wpsc_show_modal_inner_container();
    });
  }
}

/**
 * Set change assign agent
 */
function wpsc_set_bulk_assign_agent(el) {
  var form = jQuery("form.frm-bulk-assign-agent")[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
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
      wpsc_close_modal();
      wpsc_get_ticket_list();
      wpsc_run_ajax_background_process();
    });
}

/**
 * Get bulk assign tags
 */
function wpsc_bulk_assign_tags(nonce) {
  var items = jQuery(".wpsc-bulk-select:checked");
  var checked = items.length === 0 ? false : true;
  jQuery(".wpsc-bulk-selector").prop("checked", checked);
  if (items.length != 0) {
    var ticket_ids = jQuery(".wpsc-bulk-select:checked")
      .map(function () {
        return this.value;
      })
      .get();
    wpsc_show_modal();
    var data = {
      action: "wpsc_bulk_assign_tags",
      ticket_ids,
      _ajax_nonce: nonce,
    };
    jQuery.post(supportcandy.ajax_url, data, function (response) {
      // Set to modal.
      jQuery(".wpsc-modal-header").text(response.title);
      jQuery(".wpsc-modal-body").html(response.body);
      jQuery(".wpsc-modal-footer").html(response.footer);
      // Display modal.
      wpsc_show_modal_inner_container();
    });
  }
}

/**
 * Set change assign tags
 */
function wpsc_set_bulk_assign_tag(el) {
  var form = jQuery("form.frm-bulk-assign-tags")[0];
  var dataform = new FormData(form);

  var tags = dataform.getAll("tags[]");
  if (!tags.length) {
    return;
  }

  jQuery(".wpsc-modal-footer button").attr("disabled", true);
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
      wpsc_close_modal();
      wpsc_get_ticket_list();
      wpsc_run_ajax_background_process();
    });
}

/**
 * Get bulk delete ticket
 */
function wpsc_bulk_delete_tickets(nonce) {
  var items = jQuery(".wpsc-bulk-select:checked");
  var checked = items.length === 0 ? false : true;
  jQuery(".wpsc-bulk-selector").prop("checked", checked);
  if (items.length != 0) {
    var ticket_ids = jQuery(".wpsc-bulk-select:checked")
      .map(function () {
        return this.value;
      })
      .get();
    var flag = confirm(supportcandy.translations.confirm);
    if (!flag) {
      return;
    }

    var data = {
      action: "wpsc_bulk_delete_tickets",
      ticket_ids,
      _ajax_nonce: nonce,
    };
    jQuery.post(supportcandy.ajax_url, data, function (response) {
      wpsc_get_ticket_list();
      wpsc_run_ajax_background_process();
    });
  }
}

/**
 * Set agent working hrs
 */
function wpsc_set_agent_wh_hrs(el) {
  const form = jQuery(".wpsc-frm-agent-wh")[0];
  const dataform = new FormData(form);
  jQuery(el).text(supportcandy.translations.please_wait);
  jQuery(".wpsc-section-container").html(supportcandy.loader_html);
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
 * Set add agent exception
 */
function wpsc_set_add_agent_wh_exception(el) {
  var title = jQuery("input[name=title]").val();
  var from_date = jQuery("input[name=exception_date]").val();
  if (!title || !from_date) {
    alert(supportcandy.translations.req_fields_missing);
    return;
  }
  const form = jQuery(".wpsc-frm-add-agent-exception")[0];
  const dataform = new FormData(form);
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
 * Update agent working hour exception
 */
function wpsc_set_edit_agent_wh_exception(el) {
  var title = jQuery("input[name=title]").val();
  var from_date = jQuery("input[name=exception_date]").val();
  if (!title || !from_date) {
    alert(supportcandy.translations.req_fields_missing);
    return;
  }

  const form = jQuery(".wpsc-frm-edit-agent-exception")[0];
  const dataform = new FormData(form);
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
 * Get agent profile holiday actions
 */
function wpsc_get_ap_leaves_actions(dateSelected, nonce) {
  supportcandy.temp.dateSelected = dateSelected;
  wpsc_show_modal();
  var data = {
    action: "wpsc_get_ap_leaves_actions",
    dateSelected,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    // Set to modal.
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    // Display modal.
    wpsc_show_modal_inner_container();
  });
}

/**
 * Set agent profile holiday actions
 */
function wpsc_set_ap_leaves_actions(el) {
  const form = jQuery(".wpsc-frm-ap-holiday-actions")[0];
  const dataform = new FormData(form);
  dataform.append("dateSelected", supportcandy.temp.dateSelected);
  jQuery(el).text(supportcandy.translations.please_wait);
  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: "POST",
      data: dataform,
      processData: false,
      contentType: false,
    })
    .done(function (response) {
      jQuery.each(supportcandy.temp.dateSelected, function (index, value) {
        if (response.action == "add" && response.is_recurring == 1) {
          jQuery("td")
            .find("[data-date=" + value + "]")
            .css({ "background-color": "#eb4d4b" });
        } else if (response.action == "add" && response.is_recurring == 0) {
          jQuery("td")
            .find("[data-date=" + value + "]")
            .css({ "background-color": "#f0932b" });
        } else {
          jQuery("td")
            .find("[data-date=" + value + "]")
            .css("background-color", "unset");
        }
      });
      supportcandy.temp.holidayList = response.holidayList;
      wpsc_close_modal();
    });
}

/**
 * Restore bulk tickets
 */
function wpsc_bulk_restore_tickets(nonce) {
  var items = jQuery(".wpsc-bulk-select:checked");
  var checked = items.length === 0 ? false : true;
  jQuery(".wpsc-bulk-selector").prop("checked", checked);
  if (items.length != 0) {
    var ticket_ids = jQuery(".wpsc-bulk-select:checked")
      .map(function () {
        return this.value;
      })
      .get();
    var flag = confirm(supportcandy.translations.confirm);
    if (!flag) {
      return;
    }
    var data = {
      action: "wpsc_bulk_restore_tickets",
      ticket_ids,
      _ajax_nonce: nonce,
    };
    jQuery.post(supportcandy.ajax_url, data, function (response) {
      wpsc_get_ticket_list();
    });
  }
}

/**
 * Deletes bulk ticket permanentaly
 */
function wpsc_bulk_delete_tickets_permanently(nonce) {
  var items = jQuery(".wpsc-bulk-select:checked");
  var checked = items.length === 0 ? false : true;
  jQuery(".wpsc-bulk-selector").prop("checked", checked);
  if (items.length != 0) {
    var ticket_ids = jQuery(".wpsc-bulk-select:checked")
      .map(function () {
        return this.value;
      })
      .get();
    var flag = confirm(supportcandy.translations.confirm);
    if (!flag) {
      return;
    }

    var data = {
      action: "wpsc_bulk_delete_tickets_permanently",
      ticket_ids,
      _ajax_nonce: nonce,
    };
    jQuery.post(supportcandy.ajax_url, data, function (response) {
      wpsc_get_ticket_list();
    });
  }
}

/**
 * Clear dates
 */
function wpsc_clear_date(el) {
  jQuery(el).prev().val("");

  var conditionalFields = jQuery(".wpsc-tff.conditional");
  if (conditionalFields.length !== 0) {
    wpsc_check_tff_visibility();
  }
}

/**
 * Check whether there is text in reply box
 */
function wpsc_is_description_text() {
  var is_tinymce =
    typeof tinyMCE != "undefined" &&
    tinyMCE.activeEditor &&
    !tinyMCE.activeEditor.isHidden();
  if (is_tinymce && tinymce.get("description")) {
    var description = tinyMCE.get("description").getContent();
  } else {
    var description = jQuery("#description").val();
  }

  var flag = false;
  if (description && description.length != 0) {
    flag = true;
  }

  return flag;
}

/**
 * Get Edit customer info
 *
 * @param {Int} ticket_id
 */
function wpsc_get_edit_rb_info(el, ticket_id, nonce) {
  wpsc_show_modal();
  var data = { action: "wpsc_get_edit_rb_info", ticket_id, _ajax_nonce: nonce };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Set edit customer info
 *
 * @param {object} el
 * @param {Int} ticket_id
 */
function wpsc_set_edit_rb_info(el, ticket_id, nonce) {
  var form = jQuery("form.frm-edit-rb-info")[0];
  var dataform = new FormData(form);
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
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
      wpsc_close_modal();
      setTimeout(function () {
        wpsc_get_rb_info(el, ticket_id, nonce);
      }, 500);
    });
}

/**
 * Get tickets based on filter set
 */
function wpsc_get_tickets() {
  jQuery(".wpsc-bulk-selector").prop("checked", false);
  jQuery(".wpsc-ticket-list").html(supportcandy.loader_html);

  var data = {
    action: "wpsc_get_tickets",
    _ajax_nonce: supportcandy.nonce,
    is_frontend : supportcandy.is_frontend
  };
  if (
    typeof supportcandy.ticketList != "undefined" &&
    typeof supportcandy.ticketList.filters != "undefined"
  ) {
    data.filters = supportcandy.ticketList.filters;
  }

  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-filter-actions").html(response.filter_actions);
    jQuery(".wpsc-ticket-bulk-actions").html(response.bulk_actions);
    jQuery(".wpsc-ticket-list").html(response.tickets);
    jQuery(".wpsc-pagination-txt").text(response.pagination_str);
    jQuery("select.wpsc-input-filter").val(response.filters.filterSlug);
    jQuery("select.wpsc-input-sort-by").val(response.filters.orderby);
    jQuery("select.wpsc-input-sort-order").val(response.filters.order);
    jQuery("input.wpsc-search-input").val(response.filters.search);
    if (response.pagination.total_pages > 1) {
      jQuery(".wpsc-pagination-btn").show();
    } else {
      jQuery(".wpsc-pagination-btn").hide();
    }
    supportcandy.ticketList = {
      filters: response.filters,
      pagination: response.pagination,
    };
  });
}

/**
 * Copy ticket url
 *
 * @param {int} ticket_id
 */
function wpsc_it_copy_url(ticket_id) {
  var temp = jQuery("<input>");
  jQuery("body").append(temp);
  temp.val(jQuery("#wpsc-ticket-url").text()).select();
  document.execCommand("copy");
  temp.remove();
  alert(supportcandy.translations.copy_url);
}

/**
 * Open auto-refresh toggle
 */
function wpsc_get_tl_auto_refresh() {
  wpsc_show_modal();

  // Get modal parts from static modals.
  var headerText = jQuery(
    ".wpsc-tl_snippets .auto-refresh .modal-header"
  ).text();
  var bodyHTML = jQuery(".wpsc-tl_snippets .auto-refresh .modal-body").html();
  var footerHTML = jQuery(
    ".wpsc-tl_snippets .auto-refresh .modal-footer"
  ).html();

  // Set them to modal.
  jQuery(".wpsc-modal-header").text(headerText);
  jQuery(".wpsc-modal-body").html(bodyHTML);
  jQuery(".wpsc-modal-footer").html(footerHTML);

  // Set current status.
  var status = supportcandy.tl_auto_refresh ? supportcandy.tl_auto_refresh : 0;
  var el = status
    ? jQuery(".wpsc-modal .toggle-on")
    : jQuery(".wpsc-modal .toggle-off");
  jQuery(".wpsc-modal-body input").val(status);
  status ? wpsc_toggle_on(el) : wpsc_toggle_off(el);

  // Display modal.
  wpsc_show_modal_inner_container();
}

/**
 * Set auto-refresh settings
 */
function wpsc_set_tl_auto_refresh(el) {
  jQuery(".wpsc-modal-footer button").attr("disabled", true);
  jQuery(el).text(supportcandy.translations.please_wait);
  var status = parseInt(jQuery(".wpsc-modal-body input").val());
  wpsc_close_modal();
  if (supportcandy.tl_auto_refresh !== status) {
    supportcandy.tl_auto_refresh = status;
    if (status === 1) {
      wpsc_tl_auto_refresh();
    }
  }
}

/**
 * Auto refresh
 */
function wpsc_tl_auto_refresh() {
  if (
    supportcandy.current_section === "ticket-list" &&
    !supportcandy.ticketListIsIndividual &&
    supportcandy.tl_auto_refresh === 1 &&
    !(
      jQuery(".wpsc-bulk-select:checked").length ||
      jQuery(".wpsc-search-input").is(":focus")
    )
  ) {
    wpsc_get_tickets();
    if (
      typeof supportcandy.tl_auto_refresh_schedule !== "undefined" &&
      supportcandy.tl_auto_refresh_schedule == true
    ) {
      return;
    }
    supportcandy.tl_auto_refresh_schedule = true;
    setTimeout(function () {
      supportcandy.tl_auto_refresh_schedule = false;
      wpsc_tl_auto_refresh();
    }, 60000);
  }
}

/**
 * User logout
 */
function wpsc_user_logout(el, nonce) {
  jQuery(el).text(supportcandy.translations.please_wait);
  var data = { action: "wpsc_user_logout", _ajax_nonce: nonce };
  jQuery.post(supportcandy.ajax_url, data, function (res) {
    window.location.reload();
  });
}

/**
 * Close model popup on escape key.
 */
jQuery(document).keydown(function (e) {
  if (e.keyCode == 27) {
    wpsc_close_modal();
  }
});

/**
 * Get customer info
 */
function wpsc_view_customer_info(customer_id, _ajax_nonce) {
  wpsc_show_modal();
  var data = { action: "wpsc_view_customer_info", customer_id, _ajax_nonce };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Get edit customer info
 */
function wpsc_get_edit_customer_info(customer_id, _ajax_nonce) {
  wpsc_show_modal();
  var data = {
    action: "wpsc_get_edit_customer_info",
    customer_id,
    _ajax_nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 * Set edit customer info
 */
function wpsc_set_edit_customer_info(el, id, nonce) {
  	var form = jQuery("form.frm-edit-customer-info")[0];
  	var dataform = new FormData(form);
    jQuery(".wpsc-modal-footer button").attr("disabled", true);
  	jQuery(el).text(supportcandy.translations.please_wait);

  	jQuery.ajax(
		{
			url: supportcandy.ajax_url,
			type: "POST",
			data: dataform,
			processData: false,
			contentType: false,
		}
	).done(function (res) {
    wpsc_close_modal();
		wpsc_scroll_top();
		wpsc_view_customer_detailed_info( res.customer_id, res.nonce );
    });
}

/**
 * Get customer logs
 */
function wpsc_view_customer_logs(customer_id, _ajax_nonce) {
  wpsc_show_modal();
  var data = { action: "wpsc_view_customer_logs", customer_id, _ajax_nonce };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-modal-header").text(response.title);
    jQuery(".wpsc-modal-body").html(response.body);
    jQuery(".wpsc-modal-footer").html(response.footer);
    wpsc_show_modal_inner_container();
  });
}

/**
 *
 * @param {*} ticket
 */
function wpsc_clear_saved_draft_reply(ticket_id) {
  var draft_replies = JSON.parse(localStorage.getItem("wpsc_auto_draft")) || {};
  var is_tinymce =
    typeof tinyMCE != "undefined" &&
    tinyMCE.activeEditor &&
    !tinyMCE.activeEditor.isHidden();
  if (is_tinymce && tinymce.get("description")) {
    tinyMCE.get("description").setContent("");
  }
  jQuery("#description").val("");
  if (!ticket_id) return;
  delete draft_replies[ticket_id];
  localStorage.setItem("wpsc_auto_draft", JSON.stringify(draft_replies));
}

/**
 * Run background processes.
 */
function wpsc_run_ajax_background_process() {
  var data = { action: "wpsc_run_ajax_background_process" };
  jQuery.post(supportcandy.ajax_url, data, function (response) {});
}

/**
 * Assign ticket to self
 */
function wpsc_self_assign_ticket(ticket_id, nonce) {
  var data = {
    action: "wpsc_self_assign_ticket",
    ticket_id,
    _ajax_nonce: nonce,
  };

  jQuery.post(supportcandy.ajax_url, data, function (response) {
    wpsc_get_individual_ticket(ticket_id);
  });
}

/**
 * Delete wpsc_auto_draft localStorage after 24 hr
 */
function wpsc_delete_auto_draft() {
  var draft_cron_time = localStorage.getItem("wpsc_draft_cron_time");
  if (!draft_cron_time) {
    localStorage.setItem("wpsc_draft_cron_time", new Date().getTime());
  }
  var diff = new Date().getTime() - draft_cron_time;
  if (diff < 3600000) {
    return;
  }
  var draft_replies = JSON.parse(localStorage.getItem("wpsc_auto_draft")) || {};
  Object.keys(draft_replies).forEach((ticket_id) => {
    var timeDifference =
      new Date() - new Date(draft_replies[ticket_id].date_updated);
    if (timeDifference > 86400000) {
      delete draft_replies[ticket_id];
    }
  });
  localStorage.setItem("wpsc_auto_draft", JSON.stringify(draft_replies));
  localStorage.setItem("wpsc_draft_cron_time", new Date().getTime());
}

/**
 * Delete customer
 */
function wpsc_delete_customer(customer_id, nonce) {
  if (!confirm(supportcandy.translations.customer_delete_warn)) {
    return;
  }

  var data = {
    action: "wpsc_delete_customer",
    customer_id,
    _ajax_nonce: nonce,
  };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    window.location.reload();
  });
}

/**
 * Dashboard - Get filter from and to dates based on duration slug
 */
function wpsc_db_set_filter_duration_dates(duration) {

  let dateStr = '';
	let date = new Date();
	let from_date, to_date;

	switch (duration) {

    case 'today':
      dateStr = date.toISOString().split('T')[0];
      date_from_to = {
        'from': dateStr,
        'to': dateStr
      };
      break;
    
    case 'yesterday':
      date.setDate(date.getDate() - 1);
      dateStr = date.toISOString().split('T')[0];
      date_from_to = {
        'from': dateStr,
        'to': dateStr
      };
      break;
    
    case 'this-week':
      let firstDayOfWeek = date.getDay() === 0 ? 6 : date.getDay() - 1;
      date.setDate(date.getDate() - firstDayOfWeek);
      from_date = date.toISOString().split('T')[0];
      date.setDate(date.getDate() + 6);
      to_date = date.toISOString().split('T')[0];
      date_from_to = {
        'from': from_date,
        'to': to_date
      };
      break;
    
    case 'last-week':
      let firstDayOfLastWeek = date.getDay() === 0 ? 7 : date.getDay();
      date.setDate(date.getDate() - firstDayOfLastWeek - 6);
      from_date = date.toISOString().split('T')[0];
      date.setDate(date.getDate() + 6);
      to_date = date.toISOString().split('T')[0];
      date_from_to = {
        'from': from_date,
        'to': to_date
      };
      break;
    
    case 'last-7':
      date.setDate(date.getDate() - 1); // Exclude today
      to_date = date.toISOString().split('T')[0];
      date.setDate(date.getDate() - 6); // Last 7 days from yesterday
      from_date = date.toISOString().split('T')[0];
      date_from_to = {
        'from': from_date,
        'to': to_date
      };
      break;
    
    case 'last-30-days':
      date.setDate(date.getDate() - 1); // Exclude today
      to_date = date.toISOString().split('T')[0];
      date.setDate(date.getDate() - 29); // Last 30 days from yesterday
      from_date = date.toISOString().split('T')[0];
      date_from_to = {
        'from': from_date,
        'to': to_date
      };
      break;
    
    case 'this-month':
      let this_month_starts = new Date(date.getFullYear(), date.getMonth(), 1);
      let this_month_ends = new Date(date.getFullYear(), date.getMonth() + 1, 0);
      date_from_to = {
        'from': `${this_month_starts.getFullYear()}-${(this_month_starts.getMonth() + 1).toString().padStart(2, '0')}-${this_month_starts.getDate().toString().padStart(2, '0')}`,
        'to': `${this_month_ends.getFullYear()}-${(this_month_ends.getMonth() + 1).toString().padStart(2, '0')}-${this_month_ends.getDate().toString().padStart(2, '0')}`
      };
      break;

    case 'last-month':
      date.setMonth(date.getMonth() - 1);
      let last_month_starts = new Date(date.getFullYear(), date.getMonth(), 1);
      let last_month_ends = new Date(date.getFullYear(), date.getMonth() + 1, 0);
      date_from_to = {
        'from': `${last_month_starts.getFullYear()}-${(last_month_starts.getMonth() + 1).toString().padStart(2, '0')}-${last_month_starts.getDate().toString().padStart(2, '0')}`,
        'to': `${last_month_ends.getFullYear()}-${(last_month_ends.getMonth() + 1).toString().padStart(2, '0')}-${last_month_ends.getDate().toString().padStart(2, '0')}`
      };
      break;

    case 'this-quarter':
      let this_quarter_month_starts = Math.floor(date.getMonth() / 3) * 3;
      let quarter_start = new Date(date.getFullYear(), this_quarter_month_starts, 1);
      let quarter_end = new Date(date.getFullYear(), this_quarter_month_starts + 3, 0);
      date_from_to = {
        'from': `${quarter_start.getFullYear()}-${(quarter_start.getMonth() + 1).toString().padStart(2, '0')}-${quarter_start.getDate().toString().padStart(2, '0')}`,
        'to': `${quarter_end.getFullYear()}-${(quarter_end.getMonth() + 1).toString().padStart(2, '0')}-${quarter_end.getDate().toString().padStart(2, '0')}`
      };
      break;
    
    case 'last-quarter':
      let last_quarter_month_ends = Math.floor(date.getMonth() / 3) * 3 - 1; // End month of last quarter
      let last_quarter_month_starts = last_quarter_month_ends - 2; // Start month of last quarter
      if (last_quarter_month_ends < 0) {
        last_quarter_month_ends += 12;
        last_quarter_month_starts += 12;
      }
      let last_quarter_start = new Date(date.getFullYear(), last_quarter_month_starts, 1);
      let last_quarter_end = new Date(date.getFullYear(), last_quarter_month_ends + 1, 0);
      date_from_to = {
        'from': `${last_quarter_start.getFullYear()}-${(last_quarter_start.getMonth() + 1).toString().padStart(2, '0')}-${last_quarter_start.getDate().toString().padStart(2, '0')}`,
        'to': `${last_quarter_end.getFullYear()}-${(last_quarter_end.getMonth() + 1).toString().padStart(2, '0')}-${last_quarter_end.getDate().toString().padStart(2, '0')}`
      };
      break;
    
    case 'this-year':
      date_from_to = {
        'from': `${date.getFullYear()}-01-01`,
        'to': `${date.getFullYear()}-12-31`
      };
      break;
    
    case 'last-year':
      date_from_to = {
        'from': `${date.getFullYear() - 1}-01-01`,
        'to': `${date.getFullYear() - 1}-12-31`
      };
      break;
  }
	return date_from_to;
}

/**
 * Get batches for async report loading
 */
function wpsc_rp_get_baches(fromDate, toDate) {

	let diffTime = Math.abs( toDate - fromDate );
	let diffDays = Math.ceil( diffTime / (1000 * 60 * 60 * 24) ) + 1;

	let batches   = [];
	let batchData = [];

	if (diffDays == 1) { // one day.

		for (var i = 0; i <= 23; i++) {

			var hr = String( i );
			hr     = hr.length == 1 ? '0' + hr : hr;
			batchData.push(
				{
					fromDate: fromDate.toISOString().split('T')[0] + ' ' + hr + ':00:00',
					toDate: fromDate.toISOString().split('T')[0] + ' ' + hr + ':59:59',
					durationType: 'day'
				}
			);
		}

	} else if (diffDays <= 31) { // days.

		do {
			batchData.push(
				{
					fromDate: fromDate.toISOString().split('T')[0] + ' 00:00:00',
					toDate: fromDate.toISOString().split('T')[0] + ' 23:59:59',
					durationType: 'days'
				}
			);
			fromDate.setDate( fromDate.getDate() + 1 );
		} while (fromDate <= toDate);

	} else if (diffDays <= 180) { // weeks.

		var from = fromDate.toISOString().split('T')[0];
		fromDate.setDate( fromDate.getDate() + (7 - fromDate.getDay()) );
		batchData.push(
			{
				fromDate: from + ' 00:00:00',
				toDate: fromDate.toISOString().split('T')[0] + ' 23:59:59',
				durationType: 'weeks'
			}
		);
		do {
			fromDate.setDate( fromDate.getDate() + 1 );
			var from = new Date( fromDate.toLocaleDateString( 'en-CA' ) );
			fromDate.setDate( fromDate.getDate() + 6 );
			var to = new Date( fromDate.toLocaleDateString( 'en-CA' ) );
			to     = toDate < to ? new Date( toDate.toLocaleDateString( 'en-CA' ) ) : to;
			batchData.push(
				{
					fromDate: from.toISOString().split('T')[0] + ' 00:00:00',
					toDate: to.toISOString().split('T')[0] + ' 23:59:59',
					durationType: 'weeks'
				}
			);
		} while (fromDate < toDate);

	} else if (diffDays <= 720) { // months.

		// convert toDate from local to UTC.
		toDate = new Date( toDate.getFullYear(), toDate.getMonth(), toDate.getDate() );

		var from = fromDate.toISOString().split('T')[0];
		if (fromDate.getMonth() == 11) {
			fromDate = new Date( fromDate.getFullYear(), 11, 31 );
		} else {
			fromDate = new Date( fromDate.getFullYear(), fromDate.getMonth() + 1, 0 );
		}
		batchData.push(
			{
				fromDate: from + ' 00:00:00',
				toDate: fromDate.toISOString().split('T')[0] + ' 23:59:59',
				durationType: 'months'
			}
		);
		do {
			fromDate.setDate( fromDate.getDate() + 1 );
			var from = new Date( fromDate.toLocaleDateString( 'en-CA' ) );
			if (fromDate.getMonth() == 11) {
				fromDate = new Date( fromDate.getFullYear(), 11, 31 );
			} else {
				fromDate = new Date( fromDate.getFullYear(), fromDate.getMonth() + 1, 0 );
			}
			var to = new Date( fromDate.toLocaleDateString( 'en-CA' ) );
			to     = toDate < to ? new Date( toDate.toLocaleDateString( 'en-CA' ) ) : to;
			batchData.push(
				{
					fromDate: from.toISOString().split('T')[0] + ' 00:00:00',
					toDate: to.toISOString().split('T')[0] + ' 23:59:59',
					durationType: 'months'
				}
			);
		} while (fromDate < toDate);

	} else { // years.

		// convert toDate from local to UTC.
		toDate = new Date( toDate.getFullYear(), toDate.getMonth(), toDate.getDate() );

		var from = fromDate.toISOString().split('T')[0];
		fromDate = new Date( fromDate.getFullYear(), 11, 31 );
		batchData.push(
			{
				fromDate: from + ' 00:00:00',
				toDate: fromDate.toISOString().split('T')[0] + ' 23:59:59',
				durationType: 'years'
			}
		);
		do {
			fromDate.setDate( fromDate.getDate() + 1 );
			var from = new Date( fromDate.toLocaleDateString( 'en-CA' ) );
			fromDate = new Date( fromDate.getFullYear(), 11, 31 );
			var to   = new Date( fromDate.toLocaleDateString( 'en-CA' ) );
			to       = toDate < to ? new Date( toDate.toLocaleDateString( 'en-CA' ) ) : to;
			batchData.push(
				{
					fromDate: from.toISOString().split('T')[0] + ' 00:00:00',
					toDate: to.toISOString().split('T')[0] + ' 23:59:59',
					durationType: 'years'
				}
			);
		} while (fromDate < toDate);
	}

	// devide into batches of 5.
	var batchLength = batchData.length;
	for (var i = 0; i < batchLength; i = i + 5) {
		var batch   = [];
		var counter = 0;
		do {
			var index = i + counter;
			if (index == batchLength) {
				break;
			}
			batch.push( batchData[index] );
			counter++;
		} while (counter < 5);
		batches.push( batch );
	}

	return batches;
}

function wpsc_generate_random_color() {
  var minLuminance = 0.7;

  do {
      var color = Math.floor(Math.random() * 16777216); // 0x000000 to 0xFFFFFF
      var red = (color >> 16) & 0xFF;
      var green = (color >> 8) & 0xFF;
      var blue = color & 0xFF;

      // Calculate luminance (brightness).
      var luminance = (0.299 * red + 0.587 * green + 0.114 * blue) / 255;

  } while (luminance < minLuminance);

  return '#' + color.toString(16).toUpperCase();
}

// onclick load respective card ticket list
function wpsc_get_dbc_ticket_list(element, card) {
	
	var count = parseInt( jQuery( element ).find('.wpsc-dbc-count').text(), 10);
	if ( count === 0 ) {
		return;
	}
	var data = { action: 'wpsc_dash_card_count_filter', card : card, view: supportcandy.is_frontend, _ajax_nonce: supportcandy.nonce };
	jQuery.post(
		supportcandy.ajax_url,
		data,
		function (response) {
			if( response.url ) {
				window.location = response.url;
			}
		}
	);
}

// onclick load respective card ticket list
function wpsc_get_agent_status_ticket_list(agent_id, status_id, nonce) {

	var data = { action: 'wpsc_get_agent_status_ticket_list', agent_id : agent_id, status_id : status_id, view: supportcandy.is_frontend, _ajax_nonce: nonce };
	jQuery.post(
		supportcandy.ajax_url,
		data,
		function (response) {
			if( response.url ) {
				window.location = response.url;
			}
		}
	);
}

/**
 * Refresh tags list
 */
function wpsc_it_refresh_tags(ticket_id) {

	jQuery( '.wpsc-it-tag-body' ).html( supportcandy.loader_html );

	var data = { action: 'wpsc_it_refresh_tags', ticket_id };
	jQuery.post(
		supportcandy.ajax_url,
		data,
		function (res) {
			jQuery( '.wpsc-it-tag-body' ).html( res );
		}
	);
}