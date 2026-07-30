window.WPSC_AI_Chatbot = window.WPSC_AI_Chatbot || {};

document.addEventListener(
	'DOMContentLoaded',
	function () {
		window.WPSC_AI_Chatbot.init();
	}
);

// Get chatbot HTML template.
window.WPSC_AI_Chatbot.init =

	function () {
		const host = document.getElementById( 'wpsc-chatbot-root' );
		if (!host) {
			return;
		}

		this.isSending = false;
		this.isLimitReached = false;
		this.isCreatingTicket = false;

		this.shadowRoot = host.shadowRoot || host.attachShadow( { mode: 'open' } );
		this.render();
		this.cacheElements();
		this.bindEvents();

		if ( ! this.nonceRefreshStarted ) {
			this.nonceRefreshStarted = true;

			// Keep chat input disabled until the first nonce refresh resolves, so a
			// guest can't send a message on a stale, cached-page nonce.
			this.disableChatInput( 'Preparing chat...' );
			this.refreshNonce( true );
		}
	};

// Periodically fetch a fresh ajax nonce so guests served a long-lived
// full-page-cached copy of this page don't keep an expired nonce forever.
window.WPSC_AI_Chatbot.refreshNonce =

	function( isInitial ) {
		const self = this;

		jQuery.post(
			wpsc_ai_chatbot.ajax_url, {
				action: 'wpsc_chatbot_get_nonce',
			}
		).done(
			function( response ) {
				if ( response && response.success && response.data && response.data.nonce ) {
					wpsc_ai_chatbot.nonce = response.data.nonce;
				}
			}
		).always(
			function() {
				if ( isInitial ) {
					self.enableChatInput();
				}

				setTimeout(
					function() {
						window.WPSC_AI_Chatbot.refreshNonce();
					},
					60000
				);
			}
		);
	};

// Get chatbot HTML template.
window.WPSC_AI_Chatbot.render =

	function () {
		/*
		 * Inject CSS into Shadow DOM
		 */
		const style = document.createElement( 'style' );
		style.textContent = window.WPSC_AI_Chatbot_Config.css + window.WPSC_AI_Chatbot_Config.modal_css + window.WPSC_AI_Chatbot_Config.ticket_form_css;
		this.shadowRoot.appendChild( style );

		/*
		 * Inject HTML Template
		 */
		const wrapper = document.createElement( 'div' );
		wrapper.innerHTML = this.getTemplate() + this.getModalTemplate();
		this.shadowRoot.appendChild( wrapper );
	};

// Cache frequently accessed chatbot elements.
window.WPSC_AI_Chatbot.cacheElements =

	function() {
		if ( ! this.shadowRoot ) {
			return;
		}

		this.elements = {
			body: this.shadowRoot.querySelector( '.wpsc-chatbot__body' ),
			input: this.shadowRoot.querySelector( '#wpsc-chatbot-input' ),
			sendBtn: this.shadowRoot.querySelector( '.wpsc-chatbot__send' )
		};
	};

// Get chatbot HTML template.
window.WPSC_AI_Chatbot.bindEvents =

	function () {
		const self = this;
		self.cacheElements();
		const body = self.elements?.body;
		const launcher = this.shadowRoot.querySelector( '.wpsc-chatbot-launcher' );
		const sessionId = launcher?.getAttribute( 'data-sessionid' ) || '';
		const chatbot = this.shadowRoot.querySelector( '.wpsc-chatbot' );
		const closeBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__close' );
        const expandBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__expand' );
        const compressBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__compress' );
		const dropdownBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__dropdown' );
		const drawer = this.shadowRoot.querySelector( '.wpsc-chatbot__drawer' );
        const textarea = this.shadowRoot.querySelector( '.wpsc-chatbot__input' );
		const sendBtn = self.elements?.sendBtn;
		const input = self.elements?.input;
		const inputGroup = this.shadowRoot.querySelector( '.wpsc-chatbot__input-group' );
		const footer = this.shadowRoot.querySelector( '.wpsc-chatbot__footer' );
		const modal = this.shadowRoot.querySelector( '.wpsc-chatbot__modal' );
		const modalCancelBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__modal-cancel' );
		const modalReactionBtns = this.shadowRoot.querySelectorAll( '.wpsc-chatbot__modal-reaction' );
		const modalFooterAskMeLater = this.shadowRoot.querySelector( '.wpsc-chatbot__modal-footer-ask-me-later' );

		if (!launcher || !chatbot) {
			return;
		}

		chatbot?.classList.add( 'wpsc-chatbot--active' );
		launcher?.classList.add( 'wpsc-chatbot-launcher--hidden' );

		if ( sessionId ) {

			// Load previous messages if session exists.
			self.getPreviousMessages();
			chatbot?.classList.add( 'wpsc-chatbot--active' );
			launcher?.classList.add( 'wpsc-chatbot-launcher--hidden' );
		} else {
			chatbot?.classList.remove( 'wpsc-chatbot--active' );
			launcher?.classList.remove( 'wpsc-chatbot-launcher--hidden' );
		}

		// Open chatbot
		launcher?.addEventListener(
			'click',
			function () {
				footer.querySelector( '.wpsc-chatbot__input-conversation-end' )?.remove();
				chatbot?.classList.add( 'wpsc-chatbot--active' );
				launcher?.classList.add( 'wpsc-chatbot-launcher--hidden' );
				inputGroup?.classList.remove( 'wpsc-chatbot__input-group--hidden' );
				self.enableChatInput();
				/* self.getPreviousMessages(); */
				body.innerHTML = self.getWelcomeMessageTemplate();
			}
		);

		// Close chatbot
		if (closeBtn) {

			closeBtn.addEventListener(
				'click',
				function () {
					const buttonSessionId = closeBtn?.getAttribute( 'data-sessionid' ) || '';
					if ( ! buttonSessionId ) {
						chatbot?.classList.remove( 'wpsc-chatbot--active' );
						launcher?.classList.remove( 'wpsc-chatbot-launcher--hidden' );
					}  else {
						self.openModal();
					}
				}
			);
		}

		// Expand chatbot
        if (expandBtn) {

            expandBtn.addEventListener(
                'click',
                function () {
                    chatbot?.classList.toggle( 'wpsc-chatbot--fullscreen' );
                    chatbot?.classList.add( 'wpsc-chatbot--expanded' );
                }
            );
        }

		// On small screens, clicking chatbot container should open it in fullscreen expanded mode.
		chatbot?.addEventListener(
			'click',
			function () {
				if ( ! window.matchMedia( '(max-width: 768px)' ).matches ) {
					return;
				}

				chatbot?.classList.add( 'wpsc-chatbot--fullscreen' );
				chatbot?.classList.add( 'wpsc-chatbot--expanded' );
			}
		);

		// Compress chatbot
        if (compressBtn) {

            compressBtn.addEventListener(
                'click',
                function () {
                    chatbot?.classList.toggle( 'wpsc-chatbot--fullscreen' );
                    chatbot?.classList.remove( 'wpsc-chatbot--expanded' );
                }
            );
        }

		// Toggle drawer
		if (dropdownBtn && drawer) {

			dropdownBtn.addEventListener(
				'click',
				function () {
					drawer?.classList.toggle( 'wpsc-chatbot__drawer--active' );
				}
			);
		}

		// Textarea auto-resize
        if (textarea) {

            textarea.addEventListener(
                'input',
                function () {
                   	const maxHeight = 120;
					this.style.height = 'auto';
					this.style.height = Math.min( this.scrollHeight, maxHeight ) + 'px';
					this.style.overflowY = this.scrollHeight > maxHeight ? 'auto' : 'hidden';
                }
            );
        }

		// Send message on button click.
		if ( sendBtn ) {

			sendBtn.addEventListener(
				'click',
				function() {
					self.sendMessage();
				}
			);
		}

		// Send message on Enter key press without Shift.
		if ( input ) {

			input.addEventListener(
				'keydown',
				function( e ) {
					if (
						e.key === 'Enter' &&
						! e.shiftKey
					) {
						e.preventDefault();
						self.sendMessage();
					}
				}
			);
		}

		// Modal confirm cancel button.
		if ( modalCancelBtn ) {

			modalCancelBtn.addEventListener(
				'click',
				() => {
					self.closeModal();
				}
			);
		}

		if ( modalFooterAskMeLater ) {
			modalFooterAskMeLater.addEventListener(
				'click',
				() => {
					const sessionId = modalFooterAskMeLater?.getAttribute( 'data-sessionid' ) || '';
					self.askMeLater( sessionId );
				}
			);
		}

		// Modal confirm confirm button.
		if ( modalReactionBtns && modalReactionBtns.length > 0 ) {

			modalReactionBtns.forEach(
				(button) => {
					button.addEventListener(
						'click',
						(event) => {
							event.preventDefault();
							const reaction = event.currentTarget?.dataset?.reaction || '';
							const sessionId = event.currentTarget?.dataset?.sessionid || '';
							self.saveChatReaction( reaction, sessionId );
						}
					);
				}
			);
		}



		// Delegate click event for dynamically added ticket submit button.
		this.shadowRoot.addEventListener(
			'click',
			(event) => {

				const ticketBtn = event.target.closest( '.wpsc-chatbot__ticket-submit' );
				if ( ticketBtn ) {
					event.preventDefault();
					const sessionId = ticketBtn?.dataset?.sessionid || '';
					this.createTicket( true, ticketBtn?.dataset?.source || '', sessionId );
				}

				const cancelBtn = event.target.closest( '.wpsc-chatbot__ticket-cancel' );
				if ( cancelBtn ) {
					event.preventDefault();
					const sessionId = cancelBtn?.dataset?.sessionid || '';
					this.closeTicketModal( 2, cancelBtn?.dataset?.source || '', sessionId );
				}

				const formCancelBtn = event.target.closest( '.wpsc-chatbot__ticket-form-cancel' );
				if ( formCancelBtn ) {
					event.preventDefault();
					self.openModal();
				}
			}
		);
	};

// Get previous messages if session exists.
window.WPSC_AI_Chatbot.getPreviousMessages =

	function() {
		const self = this;
		self.cacheElements();
		const body = self.elements?.body;

		jQuery.post(
			wpsc_ai_chatbot.ajax_url, {
				action: 'wpsc_chatbot_get_previous_messages',
				_ajax_nonce: wpsc_ai_chatbot.nonce,
			}
		).done(
			function( response ) {
				if ( ! response.success ) {
					return;
				}
				if ( ! body ) {
					return;
				}
				body.innerHTML = self.getWelcomeMessageTemplate();
				response.data.forEach( ( message ) => {
					self.appendMessage( message.role, message.content );
				} );
			} 
		).fail(
			function() {
				return;
			}
		);
	};

// Send message to server and get AI response.
window.WPSC_AI_Chatbot.sendMessage =

	function() {
		const self = this;
		self.cacheElements();

		const input = self.elements?.input;
		const sendBtn = self.elements?.sendBtn;
		if ( this.isSending || this.isLimitReached || ! input || input.disabled ) {
			return;
		}

		const message = input.value.trim();
		if ( ! message ) {
			return;
		}

		input.value = '';
		self.appendMessage( 'user', message );
		self.showTyping();
		this.isSending = true;
		input.disabled = true;
		if ( sendBtn ) {
			sendBtn.disabled = true;
		}

		const launcher = this.shadowRoot.querySelector( '.wpsc-chatbot-launcher' );
		const closeBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__close' );
		const modalFooterAskMeLater = this.shadowRoot.querySelector( '.wpsc-chatbot__modal-footer-ask-me-later' );
		const modalReactionBtns = this.shadowRoot.querySelectorAll( '.wpsc-chatbot__modal-reaction' );
		const submitBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-submit' );
		const cancelBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-cancel' );

		jQuery.post(
			wpsc_ai_chatbot.ajax_url, {
				action: 'wpsc_chatbot_send_message',
				_ajax_nonce: wpsc_ai_chatbot.nonce,
				message: message
			}
		).done(
			function( response ) {
				self.isSending = false;
				if ( ! response || ! response.data ) {
					self.hideTyping();
					self.enableChatInput();
					return;
				}

				if ( ! response.success ) {
					self.hideTyping();
					if ( ! self.isLimitReached ) {
						self.enableChatInput();
					}
					return;
				}

				if ( response.data.session_id ) {
					launcher?.setAttribute( 'data-sessionid', response.data.session_id );
					closeBtn?.setAttribute( 'data-sessionid', response.data.session_id );
					modalFooterAskMeLater?.setAttribute( 'data-sessionid', response.data.session_id );
					modalReactionBtns.forEach( ( btn ) => {
						btn?.setAttribute( 'data-sessionid', response.data.session_id );
					} );
					submitBtn?.setAttribute( 'data-sessionid', response.data.session_id );
					cancelBtn?.setAttribute( 'data-sessionid', response.data.session_id );
				}

				const {
					limit_reached,
					create_ticket,
					session_expired,
					chat_end_message,
					ai_response,
					disable_input_message,
				} = response.data;

				// Handle ticket creation / limit reached / session expiration.
				if ( limit_reached || create_ticket || session_expired ) {

					if ( chat_end_message ) {
						self.hideTyping();
						self.handleTicketCreated( {
							chat_end_message,
							message: ai_response,
						} );
						return;
					}

					self.isLimitReached = true;
					self.appendMessage( 'assistant', ai_response );
					// self.showTicketForm( disable_input_message );
					self.hideTyping();

					return;
				}

				// Handle chat end.
				if ( chat_end_message ) {
					self.hideTyping();
					self.handleTicketCreated( {
						chat_end_message,
						message: ai_response,
					} );
					return;
				}
				
				self.appendMessage( 'assistant', response.data.ai_response );
				self.hideTyping();
				self.enableChatInput();
			} 
		).fail(
			function() {
				self.isSending = false;
				self.hideTyping();
				if ( ! self.isLimitReached ) {
					self.enableChatInput();
				}
			}
		);
	};

// Append message to chat body.
window.WPSC_AI_Chatbot.appendMessage =

	function( type, message ) {
		this.cacheElements();
		const body = this.elements?.body;
		if ( ! body ) {
			return;
		}

		const wrapper = document.createElement( 'div' );
		const currentTime = new Date().toLocaleTimeString( [], {
					hour: 'numeric',
					minute: '2-digit'
				}
			);

		let sender = 'Assistant';
		let className = 'wpsc-chatbot__system__message';

		if ( type === 'user' ) {
			sender = 'You';
			className = 'wpsc-chatbot__user__message';
		}

		wrapper.className = className;
		// Assistant output is rendered as trusted HTML; sanitize it on the server before returning.
		const safeMessage = ( type === 'user' ) ? this.escapeHtml( String( message ) ) : String( message );
		const formattedMessage = safeMessage
            // Preserve HTML flow by removing line breaks that only separate tags (e.g. </p>\n<ol>).
            .replace( />\s*\n+\s*</g, '><' )
            .replace( /\n/g, '<br>' );

		wrapper.innerHTML =
			`
			<div class="wpsc-chatbot__message-content">
				${formattedMessage}
			</div>
			<div class="wpsc-chatbot__message-meta">
				<span> ${sender} </span>
				<span> ${currentTime} </span>
			</div>
			`;

		body.appendChild( wrapper );
		body.scrollTop = body.scrollHeight;
	};

// Escape HTML to prevent XSS attacks.
window.WPSC_AI_Chatbot.escapeHtml =

	function( text ) {
		const div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	};

// Show typing till get response from AI
window.WPSC_AI_Chatbot.showTyping =

	function() {
		const existing = this.shadowRoot.querySelector( '#wpsc-chatbot-typing' );
		if ( existing ) {
			return;
		}

		this.cacheElements();
		const body = this.elements?.body;
		if ( ! body ) {
			return;
		}
		const typing = document.createElement( 'div' );

		typing.className = 'wpsc-chatbot__typing';
		typing.id = 'wpsc-chatbot-typing';
		typing.innerHTML = '<span></span>\
			<span></span>\
			<span></span>';

		body.appendChild( typing );
		body.scrollTop = body.scrollHeight;
	};

// Hide typing after getting response from AI
window.WPSC_AI_Chatbot.hideTyping =

	function() {
		const typing = this.shadowRoot.querySelector( '#wpsc-chatbot-typing' );
		if ( typing ) {
			typing.remove();
		}
	};

// Open exit chat confirmation modal.
window.WPSC_AI_Chatbot.openModal =

	function() {
		const modal = this.shadowRoot.querySelector( '.wpsc-chatbot__modal' );
		const chatbot = this.shadowRoot.querySelector( '.wpsc-chatbot' );
		const modalDialog = this.shadowRoot.querySelector( '.wpsc-chatbot__modal-dialog' );
		const ticketModal = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-modal' );
		const negativeReaction = this.shadowRoot.querySelector( '.wpsc-chatbot__modal-reaction--negative' );

		negativeReaction?.classList.remove( 'wpsc-chatbot__modal-reaction--negative--selected' );
		ticketModal?.remove();

		chatbot?.classList.add( 'wpsc-chatbot--active' );
		if ( window.matchMedia( '(max-width: 768px)' ).matches ) {
			chatbot?.classList.add( 'wpsc-chatbot--fullscreen' );
			chatbot?.classList.add( 'wpsc-chatbot--expanded' );
		}

		chatbot?.classList.add( 'wpsc-chatbot--disabled' );
		chatbot?.classList.add( 'wpsc-chatbot__modal__open' );
		if ( modal ) {
			modal?.classList.add( 'wpsc-chatbot__modal--active' );
		}
	};

// Close exit chat confirmation modal.
window.WPSC_AI_Chatbot.closeModal =

	function() {
		const chatbot = this.shadowRoot.querySelector( '.wpsc-chatbot' );
		const modal = this.shadowRoot.querySelector( '.wpsc-chatbot__modal' );
		const textarea = this.shadowRoot.querySelector( '.wpsc-chatbot__input' );
		
		if ( modal ) {
			modal?.classList.remove( 'wpsc-chatbot__modal--active' );
		}
		
		chatbot?.classList.add( 'wpsc-chatbot--active' );
		if ( chatbot ) {
			chatbot?.classList.remove( 'wpsc-chatbot__modal__open' );
			chatbot?.classList.remove( 'wpsc-chatbot--disabled' );
		}
		
		// Restore focus to the input field after closing the modal and clear textarea content.
		if ( textarea ) {
			textarea.value = '';
			textarea.disabled = false;
			textarea.classList.remove( 'wpsc-chatbot__input--readonly' );
			textarea.placeholder = 'Type your message...';
			textarea.focus();
		}
	};

// Close exit chat confirmation modal.
window.WPSC_AI_Chatbot.askMeLater =

	function( sessionId ) {
		const self = this;
		const body = self.elements?.body;
		const modal = self.shadowRoot.querySelector( '.wpsc-chatbot__modal' );
		const launcher = self.shadowRoot.querySelector( '.wpsc-chatbot-launcher' );
		const chatbot = self.shadowRoot.querySelector( '.wpsc-chatbot' );
        const textarea = this.shadowRoot.querySelector( '.wpsc-chatbot__input' );

		if ( modal ) {
			modal?.classList.remove( 'wpsc-chatbot__modal--active' );
		}

		chatbot?.classList.add( 'wpsc-chatbot--active' );
		if ( chatbot ) {
			chatbot?.classList.remove( 'wpsc-chatbot__modal__open' );
			chatbot?.classList.remove( 'wpsc-chatbot--disabled' );
			chatbot?.classList.remove( 'wpsc-chatbot--active' );
		}

		if ( body ) {
			body.innerHTML = self.getWelcomeMessageTemplate();
		}
		launcher?.classList.remove( 'wpsc-chatbot-launcher--hidden' );
		
		// Restore focus to the input field after closing the modal and clear textarea content.
		if ( textarea ) {
			textarea.value = '';
			textarea.disabled = false;
			textarea.classList.remove( 'wpsc-chatbot__input--readonly' );
			textarea.placeholder = 'Type your message...';
			textarea.focus();
		}

		// mark session closed on the server, then remove cookie from browser.
		self.skipFeedback( sessionId );
	};

// Mark session as closed (skipped feedback) on server.
window.WPSC_AI_Chatbot.skipFeedback =

	function( sessionId ) {
		const self = this;

		jQuery.post(
			wpsc_ai_chatbot.ajax_url, {
				action: 'wpsc_chatbot_skip_feedback',
				_ajax_nonce: wpsc_ai_chatbot.nonce,
				session_id: sessionId
			}
		).always(
			function() {
				self.removeSessionCookie( sessionId );
			}
		);
	};

// Remove chatbot session cookie from server.
window.WPSC_AI_Chatbot.removeSessionCookie =
				
	function( sessionId ) {

		// Remove session id from all elements that have it.
		const launcher = this.shadowRoot.querySelector( '.wpsc-chatbot-launcher' );
		const closeBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__close' );
		const modalFooterAskMeLater = this.shadowRoot.querySelector( '.wpsc-chatbot__modal-footer-ask-me-later' );
		const modalReactionBtns = this.shadowRoot.querySelectorAll( '.wpsc-chatbot__modal-reaction' );
		const submitBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-submit' );
		const cancelBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-cancel' );

		jQuery.post(
			wpsc_ai_chatbot.ajax_url, {
				action: 'wpsc_chatbot_remove_session_cookie',
				_ajax_nonce: wpsc_ai_chatbot.nonce,
				session_id: sessionId
			}
		).done(
			function( response ) {
				if ( ! response.success ) {
					return;
				}

				launcher?.removeAttribute( 'data-sessionid' );
				closeBtn?.removeAttribute( 'data-sessionid' );
				modalFooterAskMeLater?.removeAttribute( 'data-sessionid' );
				modalReactionBtns?.forEach( btn => btn.removeAttribute( 'data-sessionid' ) );
				submitBtn?.removeAttribute( 'data-sessionid' );
				cancelBtn?.removeAttribute( 'data-sessionid' );
			}
		).fail(
			function() {
				return;
			}
		);
	};

// Close ticket modal.
window.WPSC_AI_Chatbot.closeTicketModal =

	function( reaction = null, source = '', sessionId = null ) {
		const self = this;
		self.isLimitReached = false;
		self.isSending = false;
		self.enableChatInput();
		
		const modal = this.shadowRoot.querySelector( '.wpsc-chatbot__modal' );
		const dialog = this.shadowRoot.querySelector( '.wpsc-chatbot__modal-dialog' );
		const chatbot = this.shadowRoot.querySelector( '.wpsc-chatbot' );
		const launcher = this.shadowRoot.querySelector( '.wpsc-chatbot-launcher' );
		const negativeReaction = this.shadowRoot.querySelector( '.wpsc-chatbot__modal-reaction--negative' );
		const textarea = this.shadowRoot.querySelector( '.wpsc-chatbot__input' );
		
		modal?.classList.remove( 'wpsc-chatbot__modal--active' );
		chatbot?.classList.remove( 'wpsc-chatbot--active' );
		chatbot?.classList.remove( 'wpsc-chatbot--expanded' );
		chatbot?.classList.remove( 'wpsc-chatbot--disabled' );
		launcher?.classList.remove( 'wpsc-chatbot-launcher--hidden' );
		negativeReaction?.classList.remove( 'wpsc-chatbot__modal-reaction--negative--selected' );

		if ( ! sessionId ) {
			return;
		}

		jQuery.post(
			wpsc_ai_chatbot.ajax_url, {
				action: 'wpsc_chatbot_cancel_ticket_escalation',
				_ajax_nonce: wpsc_ai_chatbot.nonce,
				reaction: reaction,
				session_id: sessionId
			}
		).done(
			function( response ) {
				if ( ! response.success ) {
					return;
				}
				self.isLimitReached = false;
				self.cacheElements();
				const body = self.elements?.body;
				if ( ! body ) {
					return;
				}
			} 
		).fail(
			function() {
				return;
			}
		).always(
			function() {

				// Restore focus to the input field after closing the modal and clear textarea content.
				if ( textarea ) {
					textarea.value = '';
					textarea.disabled = false;
					textarea.classList.remove( 'wpsc-chatbot__input--readonly' );
					textarea.placeholder = 'Type your message...';
					textarea.focus();
				}
			}
		);
	};

// Confirm end conversation.
window.WPSC_AI_Chatbot.saveChatReaction =

	function( reaction, sessionId ) {
		const self = this;
		const chatbot = this.shadowRoot.querySelector( '.wpsc-chatbot' );
		self.cacheElements();
		const body = self.elements?.body;
		const launcher = this.shadowRoot.querySelector( '.wpsc-chatbot-launcher' );
		const textarea = this.shadowRoot.querySelector( '.wpsc-chatbot__input' );
		const ticketCreated = this.isCreatingTicket;
		if ( ! sessionId ) {
			return;
		}

		if ( reaction === '2' && ! ticketCreated ) {
			self.showTicketEscalation( reaction, sessionId );
			return;
		}

		self.closeModal();
		chatbot?.classList.remove( 'wpsc-chatbot--active' );
		chatbot?.classList.remove( 'wpsc-chatbot--expanded' );
		launcher?.classList.remove( 'wpsc-chatbot-launcher--hidden' );

		jQuery.post(
			wpsc_ai_chatbot.ajax_url, {
				action: 'wpsc_chatbot_end_conversation',
				_ajax_nonce: wpsc_ai_chatbot.nonce,
				reaction: reaction,
				session_id: sessionId
			}
		).done(
			function( response ) {

				self.isLimitReached = false;
				if ( ! body ) {
					return;
				}
				self.enableChatInput();
			} 
		).fail(
			function() {
				return;
			}
		).always(
			function() {
				self.removeSessionCookie( sessionId );
		
				// Restore focus to the input field after closing the modal and clear textarea content.
				if ( textarea ) {
					textarea.value = '';
					textarea.disabled = false;
					textarea.classList.remove( 'wpsc-chatbot__input--readonly' );
					textarea.placeholder = 'Type your message...';
					textarea.focus();
				}
			}
		);
	};

window.WPSC_AI_Chatbot.showTicketEscalation =

	function( reaction, sessionId ) {

		// Check if ticket modal already exists.
		const existingTicketModal = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-modal' );
		if ( existingTicketModal ) {
			return;
		}

		// Remove ticket form if it exists.
		const modalCancelBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__modal-cancel' );
		modalCancelBtn?.remove();

		// Remove negative reaction selection if it exists.
		const negativeReaction = this.shadowRoot.querySelector( '.wpsc-chatbot__modal-reaction--negative' );
		negativeReaction?.classList.add( 'wpsc-chatbot__modal-reaction--negative--selected' );

		// Append ticket modal to modal dialog.
		const modalDialog = this.shadowRoot.querySelector( '.wpsc-chatbot__modal-dialog' );
		modalDialog?.insertAdjacentHTML( 'beforeend', this.getTicketModalTemplate() );

		// The template markup is rendered server-side at page load and may carry a stale/empty
		// session id, so stamp the live session id onto the freshly inserted buttons here.
		const ticketSubmitBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-submit' );
		const ticketCancelBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-cancel' );
		ticketSubmitBtn?.setAttribute( 'data-sessionid', sessionId );
		ticketCancelBtn?.setAttribute( 'data-sessionid', sessionId );

		// Prefill name/email for a logged-in visitor so they can submit right away.
		this.prefillTicketContactFields( '.wpsc-chatbot__ticket-modal-name', '.wpsc-chatbot__ticket-modal-email' );

		// Restore focus to the input field after closing the modal and clear textarea content.
		const textarea = this.shadowRoot.querySelector( '.wpsc-chatbot__input' );
		if ( textarea ) {
			textarea.value = '';
			textarea.disabled = false;
			textarea.classList.remove( 'wpsc-chatbot__input--readonly' );
			textarea.placeholder = 'Type your message...';
			textarea.focus();
		}
	};

// Create ticket after chat limit is reached.
window.WPSC_AI_Chatbot.createTicket =

	function( ticketEscalation = false, source = '', sessionId = null ) {
		const self = this;
		if ( this.isCreatingTicket ) {
			return;
		}

		if ( ! sessionId ) {
			alert( 'We could not retrieve your session. Please try again.' );
			this.isCreatingTicket = false;
			return;
		}

		this.isCreatingTicket = true;
		if ( source === 'ticket-form' ) {
			var name = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-form-name' );
			var email = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-form-email' );
		} else if ( source === 'ticket-modal' ) {
			var name = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-modal-name' );
			var email = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-modal-email' );
		}

		const ticketName = name ? name.value.trim() : '';
		if ( name && ! ticketName ) {
			alert( 'Please enter your name.' );
			this.isCreatingTicket = false;
			return;
		}

		const ticketEmail = email ? email.value.trim() : '';
		if ( email && ( ! ticketEmail || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( ticketEmail ) ) ) {
			alert( 'Please enter a valid email.' );
			this.isCreatingTicket = false;
			return;
		}

		const submitBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-submit' );
		if ( submitBtn ) {
			submitBtn.disabled = true;
			submitBtn.textContent = 'Creating Ticket...';
			this.isCreatingTicket = true;
		}

		const modal = this.shadowRoot.querySelector( '.wpsc-chatbot__modal' );
		const chatbot = this.shadowRoot.querySelector( '.wpsc-chatbot' );

		// Make AJAX request to create ticket.
		jQuery.post(
			wpsc_ai_chatbot.ajax_url, {
				action: 'wpsc_chatbot_create_ticket',
				_ajax_nonce: wpsc_ai_chatbot.nonce,
				ticketEscalation: ticketEscalation,
				user_name: ticketName,
				user_email: ticketEmail
			} 
		).done(
			function( response ) {
				if ( ! response.success ) {
					self.showError( response.data?.message );
					return;
				}
				self.handleTicketCreated( response.data, source, sessionId );
			}
		)
		.fail(
			function( jqXHR, textStatus, errorThrown ) {
				self.showError( jqXHR.responseJSON?.data?.message );
			}
		)
		.always(
			function() {
				self.isCreatingTicket = false;
				if ( submitBtn ) {
					submitBtn.disabled = false;
					submitBtn.textContent = 'Create Ticket';
				}

				if ( modal ) {
					modal?.classList.remove( 'wpsc-chatbot__modal--active' );
				}

				chatbot?.classList.add( 'wpsc-chatbot--active' );
				if ( chatbot ) {
					chatbot?.classList.remove( 'wpsc-chatbot__modal__open' );
					chatbot?.classList.remove( 'wpsc-chatbot--disabled' );
				}
		
				// Restore focus to the input field after closing the modal and clear textarea content.
				const textarea = this.shadowRoot.querySelector( '.wpsc-chatbot__input' );
				if ( textarea ) {
					textarea.value = '';
					textarea.disabled = false;
					textarea.classList.remove( 'wpsc-chatbot__input--readonly' );
					textarea.placeholder = 'Type your message...';
					textarea.focus();
				}
			}
		);
	};

// handle create ticket success response.
window.WPSC_AI_Chatbot.handleTicketCreated =

	function( data, source = '', sessionId = null ) {
		const self = this;
		this.cacheElements();
		// Remove ticket form after successful ticket creation.
		const form = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-form' );
		if ( form ) {
			form.remove();
		}

		// Remove ticket form after successful ticket creation.
		const inputGroup = this.shadowRoot.querySelector( '.wpsc-chatbot__input-group' );
		if ( inputGroup ) {
			inputGroup?.classList.add( 'wpsc-chatbot__input-group--hidden' );
		}

		// Show success message with ticket link.
		this.appendMessage( 'assistant', data.message );
		
		const footer = this.shadowRoot.querySelector( '.wpsc-chatbot__body' );
		if ( footer ) {
			if ( data.chat_end_message ) {
				footer.insertAdjacentHTML( 'beforeend', '<div class="wpsc-chatbot__input-conversation-end">' + data.chat_end_message + '</div>' );
			}
		}

		// Scroll to bottom after appending message.
		const body = this.elements?.body;
		if ( ! body ) {
			return;
		}
		body.scrollTop = body.scrollHeight;

		this.isSending = false;
		this.isLimitReached = false;
		this.isCreatingTicket = true;

		if ( source === 'ticket-modal' ) {

			// Close the ticket escalation modal now that the ticket has been created.
			const ticketModal = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-modal' );
			ticketModal?.remove();

			// Destroy the now-handed-off session client-side (server already ended it on ticket creation).
			if ( sessionId ) {
				this.removeSessionCookie( sessionId );
			}

			// Give the visitor a moment to read the confirmation, then reload the widget for a fresh conversation.
			setTimeout(
				function() {
					self.resetChatbotWidget();
				},
				5000
			);
		}
};

// Reset the widget back to its fresh, pre-conversation state (used after a ticket
// is created via the exit-feedback ticket modal, once the session has ended).
window.WPSC_AI_Chatbot.resetChatbotWidget =

	function() {
		this.cacheElements();

		const chatbot = this.shadowRoot.querySelector( '.wpsc-chatbot' );
		const launcher = this.shadowRoot.querySelector( '.wpsc-chatbot-launcher' );
		const modal = this.shadowRoot.querySelector( '.wpsc-chatbot__modal' );
		const ticketModal = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-modal' );
		const ticketFormWrapper = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-form-wrapper' );
		const textarea = this.shadowRoot.querySelector( '.wpsc-chatbot__input' );
		const inputGroup = this.shadowRoot.querySelector( '.wpsc-chatbot__input-group' );
		const body = this.elements?.body;

		ticketModal?.remove();
		ticketFormWrapper?.remove();

		if ( modal ) {
			modal.classList.remove( 'wpsc-chatbot__modal--active' );
		}

		if ( chatbot ) {
			chatbot.classList.remove( 'wpsc-chatbot--active' );
			chatbot.classList.remove( 'wpsc-chatbot--expanded' );
			chatbot.classList.remove( 'wpsc-chatbot--fullscreen' );
			chatbot.classList.remove( 'wpsc-chatbot--disabled' );
			chatbot.classList.remove( 'wpsc-chatbot__modal__open' );
		}
		launcher?.classList.remove( 'wpsc-chatbot-launcher--hidden' );
		inputGroup?.classList.remove( 'wpsc-chatbot__input-group--hidden' );

		if ( body ) {
			body.innerHTML = this.getWelcomeMessageTemplate();
		}

		this.enableChatInput();
		if ( textarea ) {
			textarea.value = '';
			textarea.disabled = false;
			textarea.classList.remove( 'wpsc-chatbot__input--readonly' );
			textarea.placeholder = 'Type your message...';
		}

		this.isSending = false;
		this.isLimitReached = false;
		this.isCreatingTicket = false;
	};

// Show error message in chatbot.
window.WPSC_AI_Chatbot.showError =

	function( message ) {
		this.cacheElements();
		// Remove ticket form if exists.
		const form = this.shadowRoot.querySelector( '.wpsc-chatbot__ticket-form' );
		if ( form ) {
			form.remove();
		}
		
		// Append error message.
		this.appendMessage( 'assistant', message );

		// Show error message.
		const body = this.elements?.body;
		if ( ! body ) {
			return;
		}
		body.scrollTop = body.scrollHeight;
		this.isCreatingTicket = false;
	};

// Get chatbot HTML template.
window.WPSC_AI_Chatbot.showTicketForm =

	function( disableInputMessage ) {
		this.cacheElements();
		// Check if ticket form already exists.
		const body = this.elements?.body;
		if ( ! body ) {
			return;
		}

		if ( body.querySelector( '.wpsc-chatbot__ticket-form-wrapper' ) ) {
			return;
		}
		body.scrollTop = body.scrollHeight;

		// Disable chat input when showing ticket form.
		this.disableChatInput( disableInputMessage );

		// Append ticket form template.
		const wrapper = document.createElement( 'div' );
		wrapper.className = 'wpsc-chatbot__ticket-form-wrapper';
		wrapper.innerHTML = this.getTicketFormTemplate();
		body.appendChild( wrapper );

		// Prefill name/email for a logged-in visitor so they can submit right away.
		this.prefillTicketContactFields( '.wpsc-chatbot__ticket-form-name', '.wpsc-chatbot__ticket-form-email' );
	};

// Prefill a ticket contact form's name/email inputs for a logged-in visitor.
window.WPSC_AI_Chatbot.prefillTicketContactFields =

	function( nameSelector, emailSelector ) {
		const nameInput = this.shadowRoot.querySelector( nameSelector );
		const emailInput = this.shadowRoot.querySelector( emailSelector );

		if ( nameInput && ! nameInput.value && wpsc_ai_chatbot?.current_user_name ) {
			nameInput.value = wpsc_ai_chatbot.current_user_name;
		}

		if ( emailInput && ! emailInput.value && wpsc_ai_chatbot?.current_user_email ) {
			emailInput.value = wpsc_ai_chatbot.current_user_email;
		}
	};

// Disable chat input when chat limit is reached.
window.WPSC_AI_Chatbot.disableChatInput =

	function( disableInputMessage ) {
		this.cacheElements();
		// Disable input and send button.
		const input = this.elements?.input;
		const sendBtn = this.elements?.sendBtn;
		if ( input ) {
			input.disabled = true;
			input.placeholder = disableInputMessage;
			input.classList.add( 'wpsc-chatbot__input--readonly' );
		}
		if ( sendBtn ) {
			sendBtn.disabled = true;
		}
	};

// Re-enable chat input after successful non-limit responses.
window.WPSC_AI_Chatbot.enableChatInput =

	function() {
		this.cacheElements();
		// Enable input and send button.
		const input = this.elements?.input;
		const sendBtn = this.elements?.sendBtn;
		if ( input ) {
			input.disabled = false;
			input.classList.remove( 'wpsc-chatbot__input--readonly' );
			input.placeholder = 'Type your message...';

			// Keep typing flow smooth by restoring focus after input is re-enabled.
			window.requestAnimationFrame(
				() => {
					if ( input.disabled ) {
						return;
					}

					try {
						input.focus( { preventScroll: true } );
					} catch ( error ) {
						input.focus();
					}

					const length = input.value ? input.value.length : 0;
					if ( typeof input.setSelectionRange === 'function' ) {
						input.setSelectionRange( length, length );
					}
				}
			);
		}
		if ( sendBtn ) {
			sendBtn.disabled = false;
		}
	};