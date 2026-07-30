<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_PR_Controller' ) ) :

	final class WPSC_PS_AI_PR_Controller {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Add AI assistant action in ticket reply actions.
			add_action( 'wpsc_it_editor_actions', array( __CLASS__, 'add_polish_ai_tab' ) );

			// Handle AJAX request to show AI assistant chatbox for improving ticket reply.
			add_action( 'wp_ajax_wpsc_refine_ticket_reply_with_ai', array( __CLASS__, 'refine_ticket_reply_with_ai' ) );
			add_action( 'wp_ajax_wpsc_generate_ai_reply', array( __CLASS__, 'generate_ai_reply' ) );
		}

		/**
		 * Add AI assistant action in ticket editor
		 *
		 * @param WPSC_Ticket $ticket - current ticket object.
		 * @return void
		 */
		public static function add_polish_ai_tab( $ticket ) {

			$current_user = WPSC_Current_User::$current_user;
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( ! ( $current_user->is_agent && WPSC_Individual_Ticket::has_ticket_cap( 'reply' ) && ! empty( $ai_settings['is-active'] ) ) ) {
				return;
			}
			?>
			<div class="wpsc-it-editor-action">
				<span class="wpsc-link wpsc-ai-assistant" onclick="wpsc_polish_reply_with_ai(this,'<?php echo esc_attr( $ticket->id ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_polish_reply_with_ai' ) ); ?>');"><?php esc_attr_e( 'Polish (AI)', 'wpsc-ps' ); ?></span>
			</div>
			<?php
		}

		/**
		 * Handle AJAX request to show AI assistant chatbox for improving ticket reply
		 *
		 * @return void
		 */
		public static function refine_ticket_reply_with_ai() {

			if ( check_ajax_referer( 'wpsc_polish_reply_with_ai', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( __( 'Unauthorized request!', 'wpsc-ps' ), 401 );
			}

			$ticket_id = isset( $_POST['ticket_id'] ) ? intval( $_POST['ticket_id'] ) : 0;
			if ( ! $ticket_id ) {
				wp_send_json_error( __( 'Unauthorized!', 'wpsc-ps' ), 401 );
			}

			$ticket = new WPSC_Ticket( $ticket_id );
			if ( ! $ticket->id ) {
				wp_send_json_error( __( 'Ticket not found.', 'wpsc-ps' ), 404 );
			}

			WPSC_Individual_Ticket::$ticket = $ticket;
			$current_user = WPSC_Current_User::$current_user;
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( ! ( $current_user->is_agent && WPSC_Individual_Ticket::has_ticket_cap( 'reply' ) && ! empty( $ai_settings['is-active'] ) ) ) {
				wp_send_json_error( __( 'Unauthorized request!', 'wpsc-ps' ), 401 );
			}

			ob_start();
			?>
			<div class="wpsc-ai-assistance-header">
				<div class="wpsc-ai-assistance-header-title">
					<span><?php esc_html_e( 'AI Assistant', 'wpsc-ps' ); ?></span>
				</div>
				<div class="wpsc-ai-assistance-header-close" onclick="wpsc_close_modal();">
					<?php WPSC_Icons::get( 'cancel' ); ?>
				</div>
			</div>
			<?php
			$header = ob_get_clean();

			ob_start();
			?>
			<div class="wpsc-ai-assistance-chatbox">
				<div class="wpsc-ai-message wpsc-customer-reply-message">
					<span><?php esc_html_e( 'Polish my reply', 'wpsc-ps' ); ?></span>
				</div>
				<div class="wpsc-ai-message wpsc-ai-reply-message" style="display:none;"></div>
				<div class="wpsc-ai-action-buttons" style="display:none;">
					<button class="wpsc-ai-append"><?php esc_html_e( 'Append', 'wpsc-ps' ); ?></button>
					<button class="wpsc-ai-replace"><?php esc_html_e( 'Replace', 'wpsc-ps' ); ?></button>
				</div>
			</div>
			<?php
			$body = ob_get_clean();

			ob_start();
			?>
			<div class="wpsc-input-area">
				<textarea id="wpsc-ai-chat-textarea" class="wpsc-ai-chat-textarea" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wpsc_polish_reply_with_ai' ) ); ?>" data-ticket-id="<?php echo esc_attr( $ticket->id ); ?>" data-callback="wpsc_generate_ai_reply" placeholder="<?php esc_attr_e( 'Type your message to the AI and press Enter', 'wpsc-ps' ); ?>" autofocus></textarea>
			</div>
			<?php
			$footer = ob_get_clean();

			$response = array(
				'title'  => $header,
				'body'   => $body,
				'footer' => $footer,
			);
			wp_send_json( $response );
		}

		/**
		 * Handle AJAX request to retrieve AI assistant reply
		 *
		 * @return void
		 */
		public static function generate_ai_reply() {

			if ( check_ajax_referer( 'wpsc_polish_reply_with_ai', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( __( 'Unauthorized request!', 'wpsc-ps' ), 401 );
			}

			$ticket_id = isset( $_POST['ticket_id'] ) ? (int) $_POST['ticket_id'] : 0;
			if ( ! $ticket_id ) {
				wp_send_json_error( __( 'Unauthorized!', 'wpsc-ps' ), 401 );
			}

			$ticket = new WPSC_Ticket( $ticket_id );
			if ( ! $ticket->id ) {
				wp_send_json_error( __( 'Ticket not found.', 'wpsc-ps' ), 404 );
			}

			WPSC_Individual_Ticket::$ticket = $ticket;
			$current_user = WPSC_Current_User::$current_user;
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( ! ( $current_user->is_agent && WPSC_Individual_Ticket::has_ticket_cap( 'reply' ) && ! empty( $ai_settings['is-active'] ) ) ) {
				wp_send_json_error( __( 'Unauthorized request!', 'wpsc-ps' ), 401 );
			}

			$is_system_call = isset( $_POST['is_system_call'] ) ? filter_var( wp_unslash( $_POST['is_system_call'] ), FILTER_VALIDATE_BOOLEAN ) : false;
			if ( ! $is_system_call ) {

				// Only wp_unslash, then json_decode. No sanitize_textarea_field or wp_kses_post.
				$description_raw = isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$raw_description_reply = json_decode( $description_raw, true );
				if ( ! is_array( $raw_description_reply ) ) {
					wp_send_json_error( __( 'Invalid or malformed JSON in description.', 'wpsc-ps' ) );
				}

				// Sanitize all string values in the decoded array (recursive).
				$sanitize_recursive = function ( $value ) use ( &$sanitize_recursive ) {
					if ( is_array( $value ) ) {
						foreach ( $value as $k => $v ) {
							$value[ $k ] = $sanitize_recursive( $v );
						}
						return $value;
					} elseif ( is_string( $value ) ) {
						return sanitize_text_field( $value );
					}
					return $value;
				};
				$raw_description_reply = $sanitize_recursive( $raw_description_reply );
				$prompt = WPSC_PS_AI_Functions::wpsc_build_prompt_using_agent_ticket_conversation( $raw_description_reply );
				$system_prompt = self::wpsc_prompt_to_enhance_agent_ticket_conversion( $ai_settings );
				$reply = self::wpsc_generate_ticket_reply_with_ai( $ai_settings, $system_prompt, $prompt, $ticket_id );
			} else {
				// For non-JSON path, sanitize and kses as before.
				$description = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';
				$history = WPSC_PS_AI_Functions::wpsc_get_clean_ticket_history( $ticket_id, 2 );
				$prompt = WPSC_PS_AI_Functions::wpsc_prompt_to_generate_ai_reply( wp_strip_all_tags( $description ), $history );
				$system_prompt = self::wpsc_prompt_to_enhance_base_reply( $ai_settings );
				$reply = self::wpsc_generate_ticket_reply_with_ai( $ai_settings, $system_prompt, $prompt, $ticket_id );
			}

			if ( ! $reply ) {
				wp_send_json_error( __( 'Failed to generate reply.', 'wpsc-ps' ) );
			}

			WPSC_PS_AI_Logs::insert(
				array(
					'customer'     => $current_user->customer->id,
					'ticket'       => $ticket_id,
					'provider'     => $ai_settings['provider'] ?? 'openai',
					'model'        => $ai_settings['model'] ?? 'gpt-4o-mini',
					'feature'      => 'reply_polish',
					'tokens'       => $reply['tokens'],
					'prompt'       => mb_substr( $prompt, 0, 500 ),
					'date_created' => ( new DateTime( 'now' ) )->format( 'Y-m-d H:i:s' ),
				)
			);
			// Sanitize AI-generated HTML before returning to client.
			$allowed_tags = array(
				'a'      => array(
					'href'   => true,
					'title'  => true,
					'target' => true,
					'rel'    => true,
				),
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'br'     => array(),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
				'hr'     => array(),
				'p'      => array(),
			);

			$safe_reply = wp_kses( $reply['reply'], $allowed_tags );
			wp_send_json_success(
				array(
					'reply' => $safe_reply,
				)
			);
		}

		/**
		 * Process the agent's draft reply using the AI model to generate an improved version.
		 *
		 * @param array  $ai_settings The AI assistant settings retrieved from the database, containing provider, model, and other configurations.
		 * @param string $system_prompt The system prompt to guide the AI's response.
		 * @param string $prompt The combined prompt containing the agent's draft and ticket history.
		 * @param int    $ticket_id The ID of the ticket being processed, used for logging purposes.
		 * @return string The improved reply generated by the AI model, or an empty string on failure.
		 */
		public static function wpsc_generate_ticket_reply_with_ai( $ai_settings, $system_prompt, $prompt, $ticket_id ) {

			$provider = WPSC_PS_AIT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			return $provider->wpsc_generate_polished_reply( $ai_settings, $system_prompt, $prompt, $ticket_id );
		}

		/**
		 * Get system prompt for conversation-based AI processing.
		 *
		 * @param array $ai_settings AI settings array.
		 * @return string The system prompt to guide the AI's response in conversation context.
		 */
		private static function wpsc_prompt_to_enhance_base_reply( $ai_settings ) {

			$base_prompt = 'You are an assistant that improves customer support replies.

				Make the reply clear, professional, concise, and helpful.
				Do not change the original meaning.

				STRICT CONTENT RULES:
				- Preserve all product names, field names, menu paths, and technical terms exactly as written
				- Do NOT generalize or rewrite specific UI labels

				FORMAT REQUIREMENTS:
				- Always format the response as clean HTML
				- Use <p> for paragraphs (MANDATORY)
				- Each paragraph MUST be wrapped in <p>
				- Do NOT return a single paragraph unless input is extremely short
				- Use <br> only when necessary
				- Keep spacing clean and readable
				- Do NOT wrap the response in markdown code blocks

				STRUCTURE EXAMPLE (MUST FOLLOW):
				<p>Hello,</p>
				<p>First, I want to understand the issue...</p>
				<p>If so, please go to <strong>Support > Email Notifications > Ticket Notifications</strong>...</p>
				<p>Additionally, we noticed that...</p>

				OUTPUT RULES:
				- Output ONLY the final HTML
				- No explanations
				- No placeholders
				';

			$custom_prompt = $ai_settings['custom-prompt'] ?? '';
			if ( ! empty( $custom_prompt ) ) {
				$base_prompt .= "\n\nAdditional instructions from user:\n" . $custom_prompt;
			}
			return $base_prompt;
		}

		/**
		 * Get system prompt for ticket history-based AI processing.
		 *
		 * @param array $ai_settings AI settings array.
		 * @return string The system prompt to guide the AI's response in ticket history context.
		 */
		private static function wpsc_prompt_to_enhance_agent_ticket_conversion( $ai_settings ) {

			$base_prompt = "You are a professional customer support reply assistant.
			You will receive a conversation containing:
			- the original support reply
			- your previously generated improved reply
			- additional instructions from the user requesting further improvements

			Your task is to always improve the MOST RECENT assistant reply based on the user's latest instruction.

			Rules:
			- Keep the original meaning and factual information unchanged
			- Apply only the user's requested changes (tone, clarity, friendliness, length, grammar, etc.)
			- If the user instruction is vague (e.g., \"improve it\", \"rewrite\", \"make better\"), enhance clarity, professionalism, and readability while keeping it concise and helpful
			- Do NOT invent new information
			- Do NOT explain what you changed
			- Do NOT include introductions, comments, or formatting notes

			Formatting requirements (IMPORTANT):
			- Return the reply as clean HTML suitable for inserting directly into a TinyMCE editor
			- Use simple common HTML tags when helpful: <strong>, <br>, <i>, <a>, <ul>, <ol>, <li>, <hr>, <p>
			- Keep the HTML minimal, clean, and valid
			- Do NOT wrap the response in markdown code blocks
			- Forbidden anywhere in output: ``` , ```html , ```xml , ```markdown
			- Do NOT output plain text — always return HTML formatted content
			- The first character of your response must be HTML content itself, not a fence or label

			If no previous assistant reply exists, improve the original reply instead.

			Return ONLY the final improved HTML reply ready to send to the customer.";
			return $base_prompt;
		}
	}
endif;
WPSC_PS_AI_PR_Controller::init();
