/**
 * Setup wizard - step navigation and actions.
 * Follows the same AJAX fragment-loading pattern used throughout wp-admin.js.
 */

/**
 * Load a wizard step's body via AJAX and reflect it in the progress nav.
 */
function wpsc_onboarding_load_step(stepId) {
  jQuery(".wpsc-onboarding-step-nav").removeClass("active");
  jQuery(".wpsc-onboarding-step-nav." + stepId).addClass("active");

  window.history.replaceState(
    {},
    null,
    "admin.php?page=wpsc-onboarding&step=" + stepId
  );

  jQuery(".wpsc-onboarding-body").html(supportcandy.loader_html);
  wpsc_scroll_top();

  var data = { action: "wpsc_get_onboarding_step", step: stepId };
  jQuery.post(supportcandy.ajax_url, data, function (response) {
    jQuery(".wpsc-onboarding-body").html(response);
    wpsc_reset_responsive_style();
  });
}

/**
 * Move to the next step in order, or leave the wizard if this was the last one.
 */
function wpsc_onboarding_next_step(currentStepId) {
  var steps = supportcandy.onboarding_steps || [];
  var idx = steps.indexOf(currentStepId);
  if (idx > -1 && idx < steps.length - 1) {
    wpsc_onboarding_load_step(steps[idx + 1]);
  } else {
    window.location.href = "admin.php?page=wpsc-tickets";
  }
}

/**
 * Reflect a step's completed state in the progress nav without a reload.
 */
function wpsc_onboarding_mark_step(stepId, status) {
  jQuery(".wpsc-onboarding-step-nav." + stepId)
    .removeClass("pending completed")
    .addClass(status);
}

/**
 * Mark a step complete and move on. Setup must be completed, so a step can
 * only be marked done, never skipped - the server enforces the same rule
 * (e.g. the Support Page requirement on step 1) regardless of what the UI did.
 *
 * The progress nav (see wpsc_onboarding_load_step) lets an admin jump straight
 * to any step, so this can be rejected because an earlier step was never
 * completed - the server then sends back which step to go complete first
 * (redirect_step). Not every step has a ".wpsc-onboarding-inline-message" in
 * its markup to show that in, so this is surfaced with an alert instead and
 * the admin is sent back to that step.
 */
function wpsc_onboarding_complete_step(stepId, nonce) {
  var data = { action: "wpsc_onboarding_complete_step", step: stepId, _ajax_nonce: nonce };
  jQuery
    .post(supportcandy.ajax_url, data, function () {
      wpsc_onboarding_mark_step(stepId, "completed");
      wpsc_onboarding_next_step(stepId);
    })
    .fail(function (xhr) {
      var msg = supportcandy.translations.something_wrong;
      var redirectStep = null;
      try {
        var res = JSON.parse(xhr.responseText);
        if (res && res.data) {
          msg = typeof res.data === "string" ? res.data : res.data.message || msg;
          redirectStep = res.data && res.data.redirect_step ? res.data.redirect_step : null;
        }
      } catch (e) {
        // ignore - fall back to generic message.
      }
      if (redirectStep) {
        alert(msg);
        wpsc_onboarding_load_step(redirectStep);
      } else {
        jQuery(".wpsc-onboarding-inline-message").text(msg).addClass("error");
      }
    });
}

/**
 * Step 1: create the Support/Open Ticket page in one click, then reload the step.
 */
function wpsc_onboarding_create_page(type, el, nonce) {
  jQuery(el).text(supportcandy.translations.please_wait);
  var data = { action: "wpsc_onboarding_create_page", type: type, _ajax_nonce: nonce };
  jQuery.post(supportcandy.ajax_url, data, function () {
    wpsc_onboarding_load_step("support-pages");
  });
}

/**
 * Step 1: the Support Page is required - block Continue and show a notice
 * until it's been created, then save guest-ticket / OTP settings and mark
 * the step complete.
 */
function wpsc_onboarding_save_pages_step(el, completeNonce) {
  var configured = jQuery(".wpsc-onboarding-support-page-configured").val() === "1";
  if (!configured) {
    jQuery(".wpsc-onboarding-support-page-notice").show();
    return;
  }
  jQuery(".wpsc-onboarding-support-page-notice").hide();

  var form = jQuery(".wpsc-frm-onboarding-ticket-access")[0];
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
    .done(function () {
      wpsc_onboarding_complete_step("support-pages", completeNonce);
    });
}

/**
 * Step 2: save the AI provider/API key via the existing save endpoint, then
 * reload the step to reflect the new connected/error state.
 */
function wpsc_onboarding_save_ai(el) {
  var $btn = jQuery(el);
  var originalText = $btn.text();
  var form = jQuery(".wpsc-frm-onboarding-ai")[0];
  var dataform = new FormData(form);

  jQuery(".wpsc-onboarding-inline-message").text("").removeClass("error");
  $btn.prop("disabled", true).text(supportcandy.translations.please_wait);

  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: "POST",
      data: dataform,
      processData: false,
      contentType: false,
    })
    .done(function () {
      wpsc_onboarding_load_step("ai-assistant");
    })
    .fail(function (xhr) {
      var msg = supportcandy.translations.something_wrong;
      try {
        var res = JSON.parse(xhr.responseText);
        if (res && res.data) {
          msg = typeof res.data === "string" ? res.data : res.data.message || msg;
        }
      } catch (e) {
        // ignore - fall back to generic message.
      }
      jQuery(".wpsc-onboarding-inline-message").text(msg).addClass("error");
      $btn.prop("disabled", false).text(originalText);
    });
}

/**
 * Step 3: add a category via the existing save endpoint, then reload the step.
 */
function wpsc_onboarding_add_category(el, nonce) {
  var input = jQuery(".wpsc-frm-onboarding-add-category input[name='label']");
  var label = input.val().trim();
  if (!label) {
    return;
  }

  jQuery(el).prop("disabled", true).text(supportcandy.translations.please_wait);
  var data = { action: "wpsc_set_add_category", label: label, _ajax_nonce: nonce };
  jQuery.post(supportcandy.ajax_url, data, function () {
    wpsc_onboarding_load_step("categories");
  });
}

/**
 * Step 7: save From Name / From Email onto the existing email-notifications
 * general settings (merged there server-side, not overwritten - including its
 * From Name / From Email required + valid-email validation), then mark the
 * step complete.
 */
function wpsc_onboarding_save_email_step(el, completeNonce) {
  var $btn = jQuery(el);
  var originalText = $btn.text();
  var form = jQuery(".wpsc-frm-onboarding-email-general")[0];

  // The fields are marked required/type="email" in markup - reportValidity()
  // surfaces the browser's native bubble for those before we even hit the
  // server, which still re-checks everything itself regardless.
  if (!form.reportValidity()) {
    return;
  }

  var dataform = new FormData(form);

  jQuery(".wpsc-onboarding-inline-message").text("").removeClass("error");
  $btn.prop("disabled", true).text(supportcandy.translations.please_wait);

  jQuery
    .ajax({
      url: supportcandy.ajax_url,
      type: "POST",
      data: dataform,
      processData: false,
      contentType: false,
    })
    .done(function () {
      wpsc_onboarding_complete_step("emails", completeNonce);
    })
    .fail(function (xhr) {
      var msg = supportcandy.translations.something_wrong;
      try {
        var res = JSON.parse(xhr.responseText);
        if (res && res.data) {
          msg = typeof res.data === "string" ? res.data : res.data.message || msg;
        }
      } catch (e) {
        // ignore - fall back to generic message.
      }
      jQuery(".wpsc-onboarding-inline-message").text(msg).addClass("error");
      $btn.prop("disabled", false).text(originalText);
    });
}
