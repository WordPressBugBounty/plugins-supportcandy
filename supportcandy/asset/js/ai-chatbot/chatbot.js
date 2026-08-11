window.WPSC_AI_Chatbot = window.WPSC_AI_Chatbot || {};

// Trace one hop of the send-message flow to the browser console. Silent
// unless the server is running with WP_DEBUG on (see wpsc_ai_chatbot.debug,
// localized in WPSC_ACB_Admin::enqueue_frontend_scripts()) - mirrors the
// server's WP_DEBUG-gated [WPSC ACB] error_log tracing so a full turn (type
// -> send -> backend -> render) can be followed from one console.
window.WPSC_AI_Chatbot.debugLog =

	function( step, message, data ) {
		if ( typeof wpsc_ai_chatbot === 'undefined' || ! wpsc_ai_chatbot.debug ) {
			return;
		}
		if ( arguments.length > 2 ) {
			console.debug( '[WPSC ACB DEBUG] ' + step + ' | ' + message, data );
		} else {
			console.debug( '[WPSC ACB DEBUG] ' + step + ' | ' + message );
		}
	};

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
			this.debugLog( 'INIT', '#wpsc-chatbot-root not found in DOM; widget will not render' );
			return;
		}

		this.isSending = false;
		this.isLimitReached = false;
		this.isCreatingTicket = false;

		// One-time cleanup of the pre-cross-tab auto-popup key (per-tab shown COUNT).
		// It's superseded by wpsc_acb_popup_shown_in_tab (a per-tab boolean flag) and
		// wpsc_acb_popup_state in localStorage (the cross-tab count) - see scheduleAutoPopup().
		try {
			window.sessionStorage.removeItem( 'wpsc_acb_popup_shown_count' );
		} catch ( error ) {
			// Ignore storage failures (e.g. private browsing with storage disabled).
		}

		this.shadowRoot = host.shadowRoot || host.attachShadow( { mode: 'open' } );
		this.render();
		this.cacheElements();
		this.bindEvents();
		this.scheduleAutoPopup();
		this.bindMobileKeyboardResize();
		this.debugLog( 'INIT', 'chatbot widget rendered and events bound' );

		if ( ! this.nonceRefreshStarted ) {
			this.nonceRefreshStarted = true;

			// Keep chat input disabled until the first nonce refresh resolves, so a
			// guest can't send a message on a stale, cached-page nonce.
			this.disableChatInput( 'Preparing chat...' );
			this.debugLog( 'INIT', 'chat input disabled pending initial nonce refresh' );
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
        const minimizeBtn = this.shadowRoot.querySelector( '.wpsc-chatbot__minimize' );
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
				if ( self.autoPopupTimer ) {
					clearTimeout( self.autoPopupTimer );
				}
				footer.querySelector( '.wpsc-chatbot__input-conversation-end' )?.remove();
				chatbot?.classList.add( 'wpsc-chatbot--active' );
				launcher?.classList.add( 'wpsc-chatbot-launcher--hidden' );
				inputGroup?.classList.remove( 'wpsc-chatbot__input-group--hidden' );
				self.enableChatInput();
				/* self.getPreviousMessages(); */
				const activeSessionId = launcher?.getAttribute( 'data-sessionid' ) || '';
				if ( ! activeSessionId ) {
					body.innerHTML = self.getWelcomeMessageTemplate();
				}
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
						self.scheduleAutoPopup();
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

		// Minimize chatbot
        if (minimizeBtn) {

            minimizeBtn.addEventListener(
                'click',
                function () {
                    chatbot?.classList.remove( 'wpsc-chatbot--active' );
                    launcher?.classList.remove( 'wpsc-chatbot-launcher--hidden' );
                    self.scheduleAutoPopup();
                }
            );
        }

		// On small screens, tapping the conversation area should open it in fullscreen expanded mode.
		chatbot?.addEventListener(
			'click',
			function ( event ) {
				if ( ! window.matchMedia( '(max-width: 768px)' ).matches ) {
					return;
				}

				// Header (expand/minimize/compress/close) and footer (message input/send)
				// manage their own state or must stay interactive without forcing
				// fullscreen - tapping to focus the textarea is a click too, and
				// shouldn't flip the widget into expanded mode along with the keyboard.
				if ( event.target.closest( '.wpsc-chatbot__header, .wpsc-chatbot__footer' ) ) {
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
					self.debugLog( 'STEP 1: USER_ACTION', 'send button clicked' );
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
						self.debugLog( 'STEP 1: USER_ACTION', 'Enter key pressed in input (no shift)' );
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

// --- Auto-popup storage helpers -------------------------------------------
//
// Two independent pieces of state decide whether the auto-popup can show:
//
// 1. A per-TAB flag (sessionStorage, key below) - "has THIS tab already auto-
//    shown the popup?". sessionStorage is naturally scoped to one tab and
//    survives reloads within it, but a brand new tab/window always starts
//    fresh - exactly the "once per tab" semantics we want.
//
// 2. A cross-TAB counter with a 24h rolling window (localStorage, key below)
//    - "how many times has ANY tab shown the popup in the current 24h
//    period?". localStorage is shared by every same-origin tab, so this is
//    the single source of truth for the display limit.
//
// Concurrency note: localStorage has no built-in atomic read-modify-write,
// so two tabs could both read count=2 (limit 3) before either writes back
// 3, and each would believe it won the last slot - a classic
// check-then-act race. Where the browser supports the Web Locks API
// (https://developer.mozilla.org/en-US/docs/Web/API/Web_Locks_API - current
// Chrome/Edge/Firefox/Safari), consumeGlobalPopupSlot() uses it to make the
// read-check-increment sequence a genuine cross-tab critical section, which
// closes this race entirely. On older browsers without Web Locks, the same
// function falls back to a best-effort read-then-write - there remains a
// small theoretical window where two timers firing at the exact same
// instant could both succeed, but this is a client-side popup nicety, not
// a security or billing boundary, so the practical risk (occasionally
// showing one extra popup out of many tabs closing in the same millisecond)
// is an acceptable trade-off for staying purely client-side.

// Namespaced (not top-level const) so re-including this script never risks a
// "duplicate declaration" error - assigning object properties is idempotent.
window.WPSC_AI_Chatbot.POPUP_TAB_KEY = 'wpsc_acb_popup_shown_in_tab';
window.WPSC_AI_Chatbot.POPUP_STATE_KEY = 'wpsc_acb_popup_state';
window.WPSC_AI_Chatbot.POPUP_LOCK_NAME = 'wpsc_acb_popup_lock';
window.WPSC_AI_Chatbot.POPUP_PERIOD_MS = 24 * 60 * 60 * 1000;

// Has this tab already auto-shown the popup once?
window.WPSC_AI_Chatbot.hasTabShownPopup =

	function() {
		try {
			return window.sessionStorage.getItem( this.POPUP_TAB_KEY ) === '1';
		} catch ( error ) {
			// Storage unavailable (e.g. private browsing) - fail open so at least
			// this tab can still show the popup once.
			return false;
		}
	};

// Mark this tab as having auto-shown the popup, so it never shows again for
// the lifetime of this tab.
window.WPSC_AI_Chatbot.markTabShownPopup =

	function() {
		try {
			window.sessionStorage.setItem( this.POPUP_TAB_KEY, '1' );
		} catch ( error ) {
			// Ignore - worst case this tab could show the popup again later.
		}
	};

// Read the cross-tab popup state from localStorage, resetting it (and
// persisting the reset) if the 24h period has elapsed. Never throws - falls
// back to a fresh, valid state if storage is unavailable or the stored
// value is missing/corrupt, so a bad value can never permanently break the
// popup.
window.WPSC_AI_Chatbot.readPopupState =

	function() {
		const freshState = function() {
			return { count: 0, periodStartedAt: Date.now() };
		};

		let state;
		try {
			const parsed = JSON.parse( window.localStorage.getItem( this.POPUP_STATE_KEY ) );
			state = ( parsed && typeof parsed.count === 'number' && typeof parsed.periodStartedAt === 'number' )
				? parsed
				: freshState();
		} catch ( error ) {
			state = freshState();
		}

		if ( Date.now() - state.periodStartedAt >= this.POPUP_PERIOD_MS ) {
			this.debugLog( 'AUTO_POPUP', '24h period elapsed - resetting cross-tab popup count' );
			state = freshState();
		}

		try {
			window.localStorage.setItem( this.POPUP_STATE_KEY, JSON.stringify( state ) );
		} catch ( error ) {
			// Ignore storage failures - the in-memory state below is still usable for this call.
		}

		return state;
	};

// Try to consume one of the shared display slots for this 24h period.
// Returns a Promise<boolean> - true if a slot was available and has now
// been consumed (the caller should show the popup), false if the limit was
// already reached (by this tab or another one).
window.WPSC_AI_Chatbot.consumeGlobalPopupSlot =

	function( limit ) {
		const self = this;

		const tryConsume = function() {
			const state = self.readPopupState();
			if ( state.count >= limit ) {
				self.debugLog( 'AUTO_POPUP', 'global display limit already reached for this 24h period', state );
				return false;
			}

			state.count += 1;
			try {
				window.localStorage.setItem( self.POPUP_STATE_KEY, JSON.stringify( state ) );
			} catch ( error ) {
				// Ignore storage failures - proceed as consumed so this visitor still sees
				// the popup once, even if the count can't be persisted for other tabs.
			}
			self.debugLog( 'AUTO_POPUP', 'consumed global display slot', state );
			return true;
		};

		if ( window.navigator && navigator.locks && typeof navigator.locks.request === 'function' ) {
			return navigator.locks.request( self.POPUP_LOCK_NAME, function() {
				return tryConsume();
			} );
		}

		// Web Locks API unavailable - best-effort fallback, see concurrency note above.
		return Promise.resolve( tryConsume() );
	};

// Automatically open the chatbot after the configured delay (if Popup Delay
// Status is enabled - otherwise immediately), capped to a display limit
// shared across browser tabs within a rolling 24h period, with each tab
// showing the popup at most once. See the storage helpers above for the
// exact mechanics and their concurrency guarantees.
window.WPSC_AI_Chatbot.scheduleAutoPopup =

	function() {
		const self = this;

		if ( this.autoPopupTimer ) {
			clearTimeout( this.autoPopupTimer );
			this.autoPopupTimer = null;
		}

		const launcher = this.shadowRoot.querySelector( '.wpsc-chatbot-launcher' );
		const chatbot = this.shadowRoot.querySelector( '.wpsc-chatbot' );

		// Don't auto-open on top of an already active/ongoing conversation.
		if ( ! launcher || ! chatbot || launcher.getAttribute( 'data-sessionid' ) ) {
			self.debugLog( 'AUTO_POPUP', 'skip: missing widget elements or an active session already exists' );
			return;
		}

		// Popup Delay Status is the master switch for the whole auto-popup feature -
		// when it's off, Popup Delay and Popup Display Limit must not be acted on at
		// all: no timer is armed, and the display-limit counter is never touched, so
		// turning it back on later starts from a limit that wasn't silently consumed
		// while it was off. Defaults to enabled (matches pre-existing behavior) when
		// the setting is missing, e.g. on an older/unmigrated install.
		const delayStatusEnabled = typeof wpsc_ai_chatbot?.popup_delay_status === 'undefined'
			? true
			: !! parseInt( wpsc_ai_chatbot.popup_delay_status, 10 );
		if ( ! delayStatusEnabled ) {
			self.debugLog( 'AUTO_POPUP', 'skip: popup delay status is disabled - auto-popup is off' );
			return;
		}

		// A display limit of 0 (or unset) means the popup is disabled entirely.
		const limit = parseInt( wpsc_ai_chatbot?.popup_display_limit, 10 ) || 0;
		if ( limit <= 0 ) {
			self.debugLog( 'AUTO_POPUP', 'skip: popup display limit is 0/unset' );
			return;
		}

		// Once this tab has auto-shown the popup, it never shows again in this tab.
		if ( self.hasTabShownPopup() ) {
			self.debugLog( 'AUTO_POPUP', 'skip: this tab already auto-showed the popup once' );
			return;
		}

		// Cheap up-front check so a timer isn't even armed when the global limit is
		// already exhausted. The authoritative check happens again right before the
		// popup is shown (below), since another tab can consume the remaining slots
		// while this tab is waiting out its delay.
		if ( self.readPopupState().count >= limit ) {
			self.debugLog( 'AUTO_POPUP', 'skip: global display limit already reached for this period' );
			return;
		}

		const delaySeconds = parseInt( wpsc_ai_chatbot?.popup_delay, 10 ) || 0;

		self.debugLog( 'AUTO_POPUP', 'arming timer', { delaySeconds, delayStatusEnabled, limit } );

		this.autoPopupTimer = setTimeout(
			function() {
				self.autoPopupTimer = null;

				// Visitor may have already opened (or otherwise dismissed) the launcher before the timer fired.
				if ( chatbot.classList.contains( 'wpsc-chatbot--active' ) || launcher.classList.contains( 'wpsc-chatbot-launcher--hidden' ) ) {
					self.debugLog( 'AUTO_POPUP', 'skip at fire time: chat already opened' );
					return;
				}

				if ( self.hasTabShownPopup() ) {
					self.debugLog( 'AUTO_POPUP', 'skip at fire time: this tab already auto-showed the popup once' );
					return;
				}

				// Re-check and consume the shared slot atomically (where supported) right
				// before displaying - another tab may have used up the remaining slots
				// while this tab was waiting out its delay.
				self.consumeGlobalPopupSlot( limit ).then(
					function( consumed ) {
						if ( ! consumed ) {
							self.debugLog( 'AUTO_POPUP', 'skip at fire time: global limit reached by another tab' );
							return;
						}

						self.markTabShownPopup();

						chatbot.classList.add( 'wpsc-chatbot--active' );
						launcher.classList.add( 'wpsc-chatbot-launcher--hidden' );
						self.enableChatInput();
						self.debugLog( 'AUTO_POPUP', 'popup shown' );
					}
				);
			},
			delaySeconds * 1000
		);
	};

// On mobile, the chatbot fills the screen (100dvh) - but `dvh` doesn't reliably shrink
// for the on-screen keyboard across mobile browsers, so the footer/input can end up hidden
// behind it. Track the real visible area via visualViewport and resize the widget to match,
// the same way native chat apps (e.g. WhatsApp) keep header + messages + input all in view.
window.WPSC_AI_Chatbot.bindMobileKeyboardResize =

	function() {
		const self = this;
		const viewport = window.visualViewport;
		if ( ! viewport ) {
			return;
		}

		const chatbot = this.shadowRoot.querySelector( '.wpsc-chatbot' );
		if ( ! chatbot ) {
			return;
		}

		const isMobile = () => window.matchMedia( '(max-width: 768px)' ).matches;

		const adjustForViewport = function() {
			if ( ! isMobile() || ! chatbot.classList.contains( 'wpsc-chatbot--active' ) ) {
				chatbot.style.height = '';
				chatbot.style.top = '';
				return;
			}

			chatbot.style.top = viewport.offsetTop + 'px';
			chatbot.style.height = viewport.height + 'px';

			// Keep the latest message in view once the visible area shrinks/grows.
			const body = self.elements?.body;
			if ( body ) {
				body.scrollTop = body.scrollHeight;
			}
		};

		viewport.addEventListener( 'resize', adjustForViewport );
		viewport.addEventListener( 'scroll', adjustForViewport );
	};

// Get previous messages if session exists.
window.WPSC_AI_Chatbot.getPreviousMessages =

	function( isRetry ) {
		const self = this;
		self.cacheElements();
		const body = self.elements?.body;

		self.debugLog( 'HISTORY_LOAD', 'requesting previous messages for existing session cookie (isRetry=' + !! isRetry + ')' );

		jQuery.post(
			wpsc_ai_chatbot.ajax_url, {
				action: 'wpsc_chatbot_get_previous_messages',
				_ajax_nonce: wpsc_ai_chatbot.nonce,
			}
		).done(
			function( response ) {
				if ( ! response.success ) {
					self.debugLog( 'HISTORY_LOAD', 'response.success false; leaving chat body untouched' );
					return;
				}
				if ( ! body ) {
					return;
				}
				self.debugLog( 'HISTORY_LOAD', 'rendering ' + ( response.data?.length || 0 ) + ' previous message(s)' );
				body.innerHTML = self.getWelcomeMessageTemplate();
				response.data.forEach( ( message ) => {
					self.appendMessage( message.role, message.content );
				} );
			}
		).fail(
			function( jqXHR ) {

				// This call runs on page load, before the periodic nonce
				// refresh (see refreshNonce()) has had a chance to run - on
				// a long-lived full-page-cached copy of the page, the nonce
				// baked into that cached HTML can already be stale, so the
				// very first request here gets rejected with 401. Refresh
				// the nonce once and retry, instead of silently leaving the
				// chatbox empty even though the session and its history are
				// still there server-side.
				if ( isRetry || ! jqXHR || 401 !== jqXHR.status ) {
					return;
				}

				jQuery.post(
					wpsc_ai_chatbot.ajax_url, {
						action: 'wpsc_chatbot_get_nonce',
					}
				).done(
					function( nonceResponse ) {
						if ( nonceResponse && nonceResponse.success && nonceResponse.data && nonceResponse.data.nonce ) {
							wpsc_ai_chatbot.nonce = nonceResponse.data.nonce;
						}
					}
				).always(
					function() {
						self.getPreviousMessages( true );
					}
				);
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
			self.debugLog(
				'STEP 2: SEND_MESSAGE_GUARD',
				'sendMessage() aborted early',
				{ isSending: this.isSending, isLimitReached: this.isLimitReached, hasInput: !! input, inputDisabled: input?.disabled }
			);
			return;
		}

		const message = input.value.trim();
		if ( ! message ) {
			self.debugLog( 'STEP 2: SEND_MESSAGE_GUARD', 'sendMessage() aborted: empty message' );
			return;
		}

		self.debugLog( 'STEP 2: SEND_MESSAGE_START', 'user message captured, length=' + message.length, message );

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

		self.debugLog(
			'STEP 3: AJAX_REQUEST',
			'POST wpsc_chatbot_send_message -> ' + wpsc_ai_chatbot.ajax_url,
			{ action: 'wpsc_chatbot_send_message', message_length: message.length }
		);

		jQuery.post(
			wpsc_ai_chatbot.ajax_url, {
				action: 'wpsc_chatbot_send_message',
				_ajax_nonce: wpsc_ai_chatbot.nonce,
				message: message
			}
		).done(
			function( response ) {
				self.isSending = false;
				self.debugLog( 'STEP 4: AJAX_RESPONSE_RECEIVED', 'raw response from wpsc_chatbot_send_message', response );

				if ( ! response || ! response.data ) {
					self.debugLog( 'STEP 4: AJAX_RESPONSE_RECEIVED', 'response missing or has no data payload; aborting render' );
					self.hideTyping();
					self.enableChatInput();
					return;
				}

				if ( ! response.success ) {
					self.debugLog( 'STEP 4: AJAX_RESPONSE_RECEIVED', 'response.success is false; aborting render', response.data );
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

					self.debugLog(
						'STEP 5: RESPONSE_BRANCH',
						'limit_reached/create_ticket/session_expired branch',
						{ limit_reached, create_ticket, session_expired, chat_end_message }
					);

					if ( chat_end_message ) {
						self.hideTyping();
						self.debugLog( 'STEP 6: RENDER', 'chat_end_message present -> handleTicketCreated()', ai_response );
						self.handleTicketCreated( {
							chat_end_message,
							message: ai_response,
						} );
						return;
					}

					self.isLimitReached = true;
					self.debugLog( 'STEP 6: RENDER', 'appendMessage(assistant) [limit reached/ticket path, no chat_end_message]', ai_response );
					self.appendMessage( 'assistant', ai_response );
					// self.showTicketForm( disable_input_message );
					self.hideTyping();

					return;
				}

				// Handle chat end.
				if ( chat_end_message ) {
					self.hideTyping();
					self.debugLog( 'STEP 5: RESPONSE_BRANCH', 'chat_end_message branch -> handleTicketCreated()', ai_response );
					self.handleTicketCreated( {
						chat_end_message,
						message: ai_response,
					} );
					return;
				}

				self.debugLog( 'STEP 5: RESPONSE_BRANCH', 'normal assistant reply branch' );
				self.debugLog( 'STEP 6: RENDER', 'appendMessage(assistant)', response.data.ai_response );
				self.appendMessage( 'assistant', response.data.ai_response );
				self.hideTyping();
				self.enableChatInput();
				self.debugLog( 'STEP 7: TURN_COMPLETE', 'typing indicator hidden, input re-enabled; user now sees the response' );
			}
		).fail(
			function( jqXHR, textStatus, errorThrown ) {
				self.isSending = false;
				self.debugLog(
					'STEP 4: AJAX_RESPONSE_FAILED',
					'wpsc_chatbot_send_message request failed',
					{ status: jqXHR?.status, textStatus, errorThrown }
				);
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
			this.debugLog( 'APPEND_MESSAGE', 'aborted: .wpsc-chatbot__body not found in shadow DOM', { type } );
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
		this.debugLog( 'APPEND_MESSAGE', 'message node appended to DOM, sender=' + sender + ', length=' + formattedMessage.length );
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

		this.debugLog( 'TYPING_INDICATOR', 'typing indicator shown, waiting for AI response' );
		body.appendChild( typing );
		body.scrollTop = body.scrollHeight;
	};

// Hide typing after getting response from AI
window.WPSC_AI_Chatbot.hideTyping =

	function() {
		const typing = this.shadowRoot.querySelector( '#wpsc-chatbot-typing' );
		if ( typing ) {
			typing.remove();
			this.debugLog( 'TYPING_INDICATOR', 'typing indicator removed' );
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
		const self = this;

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

				// Session ended without a page reload - the visitor is back to a fresh, pre-conversation
				// state, so give the auto-popup a chance to show again (still capped by the display limit).
				self.scheduleAutoPopup();
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
				const textarea = self.shadowRoot.querySelector( '.wpsc-chatbot__input' );
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