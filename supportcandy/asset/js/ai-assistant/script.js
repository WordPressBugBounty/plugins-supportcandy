/**
 * Appends a message to the chat UI.
 * @param {string} role - The role of the message sender (e.g., 'user', 'assistant').
 * @param {string} content - The message content to display.
 */
function appendMessageToChat(role, content) {
  var $model_body = jQuery(".wpsc-modal-body");
  var $action_buttons = jQuery(".wpsc-ai-action-buttons");
  var messageClass =
    role === "user" ? "wpsc-customer-reply-message" : "wpsc-ai-reply-message";
  var div = jQuery("<div>")
  .addClass("wpsc-ai-message " + messageClass)
  .html(content);

  $model_body.append(div);

  if (role === "user") {
    var loaderDiv = jQuery("<div>").addClass("wpsc-loading-ai-response").html(supportcandy.ai_loader_html);
    $model_body.append(loaderDiv);
    $action_buttons.hide();
  }

  if (role === "assistant") {
    if ($action_buttons.length) {
      $action_buttons.appendTo($model_body).show();
    }
  }

  // Smooth scroll to bottom
  $model_body.scrollTop($model_body[0].scrollHeight);
}

/**
 * Ensures AI conversation history exists and is an array.
 * @returns {Array}
 */
function ensureAIConversation() {
  if (!Array.isArray(window.wpsc_ai_conversation)) {
    window.wpsc_ai_conversation = [];
  }
  return window.wpsc_ai_conversation;
}

/**
 * Calls the AI assistant and gets an improved reply.
 * @param {HTMLElement} el - The element triggering the request.
 * @param {string|number} ticket_id - The ticket ID.
 * @param {string} nonce - The security nonce.
 */
function wpsc_polish_reply_with_ai(el, ticket_id, nonce) {
  
  window.wpsc_ai_conversation = [
    {
      role: "system",
      content:
        "You are a customer support assistant. Improve the given reply to make it clear, professional, concise, and helpful. Preserve the original meaning and intent. Do not add new information or assumptions. Fix grammar, tone, and structure. Use a polite and friendly tone. Keep the response easy to read. Return only the improved reply, without any explanations or extra text.",
    },
  ];
  
  var is_tinymce =
    typeof tinyMCE != "undefined" &&
    tinyMCE.activeEditor &&
    !tinyMCE.activeEditor.isHidden();
  var description =
    is_tinymce && tinymce.get("description")
      ? tinyMCE.get("description").getContent()
      : jQuery("#description").val().trim();

  if (!description) {
    alert(supportcandy.translations.empty_desc_warning);
    return;
  }

  jQuery
    .post({
      url: supportcandy.ajax_url,
      data: {
        action: "wpsc_refine_ticket_reply_with_ai",
        ticket_id,
        _ajax_nonce: nonce,
      },
    })
    .done(function (res) {
      jQuery(".wpsc-modal-header").html(res.title);
      jQuery(".wpsc-modal-body").html(res.body);
      jQuery(".wpsc-modal-footer").html(res.footer);

      wpsc_show_modal();
      wpsc_show_modal_inner_container();
      var $model_body = jQuery(".wpsc-modal-body");
      var loaderDiv = jQuery("<div>").addClass("wpsc-loading-ai-response").html(supportcandy.ai_loader_html);
      $model_body.append(loaderDiv);  
      jQuery(".wpsc-modal-footer").hide();
      fetchAndAppendAIReply(ticket_id, description, nonce);
    });
}

/**
 * Fetches the AI-generated reply and appends it to the chat UI.
 * @param {string|number} ticket_id - The ticket ID.
 * @param {string} description - The ticket description.
 * @param {string} nonce - The security nonce.
 */
function fetchAndAppendAIReply(ticket_id, description, nonce) {
  jQuery
    .post({
      url: supportcandy.ajax_url,
      data: {
        action: "wpsc_generate_ai_reply",
        ticket_id: ticket_id,
        is_system_call: true,
        description: description,
        _ajax_nonce: nonce,
      },
    })
    .done(function (ai) {
      jQuery(".wpsc-loading-ai-response").html("");
      jQuery(".wpsc-modal-footer").show();

      if (!ai.success) {
        appendMessageToChat("assistant", "Failed to generate AI reply.");
        return;
      }

      var $model_body = jQuery(".wpsc-modal-body");
      var lastMsg = $model_body.find(".wpsc-ai-reply-message").last().text();
      if (lastMsg !== ai.data.reply) {
        ensureAIConversation().push({
          role: "assistant",
          content: ai.data.reply,
        });
        appendMessageToChat("assistant", ai.data.reply);
      }
    });
}

/**
 * Handles user prompt submission and AI response.
 * @param {string|number} ticket_id - The ticket ID.
 * @param {string} prompt - The user's prompt.
 * @param {string} nonce - The security nonce.
 * @param {function} callback - The callback function to execute after processing.
 * @param {string} type - The type of AI request.
 */
function wpsc_refine_reply_using_agent_prompt(ticket_id, prompt, nonce, callback, type) {
  var conversation = ensureAIConversation();
  conversation.push({ role: "user", content: prompt });
  jQuery(".wpsc-modal-footer").hide();
  // Append to UI
  appendMessageToChat("user", prompt);

  // If type is auto_draft_reply, and callback is wpsc_generate_ai_reply replace and action as wpsc_generate_ai_reply otherwise keep action as callback
  var action = "wpsc_generate_ai_reply";
  if (type === "auto_draft_reply" && callback === "wpsc_generate_ai_reply") {
    action = callback;
  } else if (callback) {
    action = callback;
  }

  // get data from class wpsc-auto-draft-message > span
  var draftReply = jQuery(".wpsc-auto-draft-message > span").text().trim();
  if (draftReply) {
    // Only add draftReply if not already present in conversation
    var alreadySeeded = conversation.some(function(msg) {
      return msg.role === "assistant" && msg.content === draftReply;
    });
    if (!alreadySeeded) {
      conversation.push({ role: "assistant", content: draftReply });
    }
  }

  // Call AI
  jQuery
    .post({
      url: supportcandy.ajax_url,
      data: {
        action: action,
        ticket_id,
        is_system_call: false,
        description: JSON.stringify(conversation),
        _ajax_nonce: nonce,
      },
    })
    .done(function (ai) {
      jQuery(".wpsc-loading-ai-response").html("");
      jQuery(".wpsc-modal-footer").show();
      if (ai.success) {
        // Add AI reply to conversation and UI
        ensureAIConversation().push({
          role: "assistant",
          content: ai.data.reply,
        });
        appendMessageToChat("assistant", ai.data.reply);
      } else {
        appendMessageToChat("assistant", "Failed to generate AI reply.");
      }
    });
}

/*
 * Handles Enter key press in the AI chat textarea to submit user prompt.
 */
jQuery(document).on("keydown", ".wpsc-improve-auto-draft-reply", function (e) {
  if (e.key === "Enter" && !e.shiftKey) {
    var conversation = ensureAIConversation();
    
    e.preventDefault();
    var prompt = jQuery(this).val().trim();
    if (prompt) {
      var pre_content = jQuery(".wpsc-auto-draft-message > span").text().trim();
      
      if (pre_content) {
        // Add the draft reply as an assistant message to the conversation history
        conversation.push({ role: 'assistant', content: pre_content });
      }
      var nonce = jQuery(this).data("nonce");
      var ticket_id = jQuery(this).data("ticket-id");
      var callback = jQuery(this).data("callback");
      wpsc_refine_reply_using_agent_prompt(ticket_id, prompt, nonce, callback, "auto_draft_reply");
      jQuery(this).val(""); // clear input
    }
  }
});

/*
 * Handles Enter key press in the AI chat textarea to submit user prompt.
 */
jQuery(document).on("keydown", ".wpsc-ai-chat-textarea", function (e) {
  if (e.key === "Enter" && !e.shiftKey) {
    e.preventDefault();
    var prompt = jQuery(this).val().trim();
    if (prompt) {
      var nonce = jQuery(this).data("nonce");
      var ticket_id = jQuery(this).data("ticket-id");
      var callback = jQuery(this).data("callback");
      wpsc_refine_reply_using_agent_prompt(ticket_id, prompt, nonce, callback, "polish_reply");
      jQuery(this).val(""); // clear input
    }
  }
});

/**
 * Appends improved reply to the editor when "Append" button is clicked.
 */
jQuery(document).off("click", ".wpsc-ai-append").on("click", ".wpsc-ai-append", function () {
  // Get the improved reply from the modal (adjust selector as needed)
  var improvedReply = jQuery(".wpsc-ai-reply-message").last().html() || "";

  // TinyMCE editor
  var is_tinymce =
    typeof tinyMCE != "undefined" &&
    tinyMCE.activeEditor &&
    !tinyMCE.activeEditor.isHidden();
  if (is_tinymce) {
    var currentContent = tinymce.activeEditor.getContent({ format: "html" });
    tinymce.activeEditor.setContent(currentContent + "<br>" + improvedReply);
  } else {
    // Plain textarea: convert <br> to newlines
    var improvedReplyText = improvedReply.replace(/<br\s*\/?>(\r?\n)?/gi, "\n");
    var $txt = jQuery(".wpsc_textarea");
    var textAreaTxt = $txt.val();
    $txt.val(textAreaTxt + "\n\n" + improvedReplyText);
  }
  wpsc_close_modal();
});

/**
 * Replaces editor content with improved reply when "Replace" button is clicked.
 */
jQuery(document).off("click", ".wpsc-ai-replace").on("click", ".wpsc-ai-replace", function () {
  // Get the improved reply from the modal (adjust selector as needed)
  var improvedReply = jQuery(".wpsc-ai-reply-message").last().html() || "";

  // TinyMCE editor
  var is_tinymce =
    typeof tinyMCE != "undefined" &&
    tinyMCE.activeEditor &&
    !tinyMCE.activeEditor.isHidden();
  if (is_tinymce) {
    tinymce.activeEditor.setContent(improvedReply);
  } else {
    // Plain textarea: convert <br> to newlines
    var improvedReplyText = improvedReply.replace(/<br\s*\/?>(\r?\n)?/gi, "\n");
    jQuery(".wpsc_textarea").val(improvedReplyText);
  }
  wpsc_close_modal();
});

/**
 * Fetches and displays AI-generated ticket summary.
 * @param {HTMLElement} el - The element triggering the request.
 * @param {string|number} ticket_id - The ticket ID.
 * @param {string} nonce - The security nonce.
 */
function wpsc_generate_ticket_summary(el, ticket_id, nonce) {
  var summary_container = jQuery(".wpsc-ticket-summary-container");

  summary_container.html(supportcandy.loader_html);
  jQuery.ajax({
    url: supportcandy.ajax_url,
    type: "POST",
    data: {
      action: "wpsc_get_ticket_summary",
      ticket_id: ticket_id,
      _ajax_nonce: nonce,
    },
    success: function (response) {
      if (response.success) {
        summary_container.html(response.data.summary);
      } else {
        summary_container.text("Failed to fetch ticket summary.");
      }
    },
    error: function () {
      summary_container.text("Error fetching ticket summary.");
    },
  });
}

/**
 * Calls the AI assistant and gets an improved reply.
 * @param {HTMLElement} el - The element triggering the request.
 * @param {string|number} id - The ticket ID.
 * @param {string} nonce - The security nonce.
 */
function wpsc_handle_ai_auto_draft(el, id, nonce) {
  
  jQuery.post({
    url: supportcandy.ajax_url,
    data: {
      action: "wpsc_auto_draft_ticket_reply_with_ai",
      id,
      _ajax_nonce: nonce,
    },
  }).done(function (res) {

    jQuery(".wpsc-modal-header").html(res.title);
    jQuery(".wpsc-modal-body").html(res.body);
    jQuery(".wpsc-modal-footer").html(res.footer);

    wpsc_show_modal();
    wpsc_show_modal_inner_container();
    var $model_body = jQuery(".wpsc-modal-body");
    var loaderDiv = jQuery("<div>").addClass("wpsc-loading-ai-response").html(supportcandy.ai_loader_html);
    $model_body.append(loaderDiv);  
    jQuery(".wpsc-modal-footer").hide();
    fetchAndAppendAIDraft(id, nonce);
  });
}

/**
 * Fetches the AI-generated reply and appends it to the chat UI.
 * @param {string|number} id - The ticket ID.
 * @param {string} nonce - The security nonce.
 */
function fetchAndAppendAIDraft(id, nonce) {
  jQuery
    .post({
      url: supportcandy.ajax_url,
      data: {
        action: "wpsc_generate_ai_auto_draft",
        id: id,
        _ajax_nonce: nonce,
      },
    })
    .done(function (ai) {
      jQuery(".wpsc-loading-ai-response").html("");
      jQuery(".wpsc-modal-footer").show();

      if (!ai.success) {
        appendMessageToChat("assistant", "Failed to generate AI reply.");
        return;
      }

      var $model_body = jQuery(".wpsc-modal-body");
      var lastMsg = $model_body.find(".wpsc-ai-reply-message").last().text();
      if (lastMsg !== ai.data.draft_reply) {
        ensureAIConversation().push({
          role: "assistant",
          content: ai.data.draft_reply,
        });
        appendMessageToChat("assistant", ai.data.draft_reply);
      }
    });
}