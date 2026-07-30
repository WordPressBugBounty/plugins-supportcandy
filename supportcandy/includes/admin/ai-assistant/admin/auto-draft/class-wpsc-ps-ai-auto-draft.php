<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Auto_Draft' ) ) :

	final class WPSC_PS_AI_Auto_Draft {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Add AI assistant action in ticket reply actions.
			add_action( 'wpsc_it_editor_actions', array( __CLASS__, 'add_auto_draft_ai_tab' ) );

			add_action( 'wp_ajax_wpsc_improve_auto_draft_reply', array( __CLASS__, 'improve_auto_draft_reply' ) );
			add_action( 'wp_ajax_wpsc_auto_draft_ticket_reply_with_ai', array( __CLASS__, 'handle_ai_auto_draft_popup' ) );
			add_action( 'wp_ajax_wpsc_generate_ai_auto_draft', array( __CLASS__, 'handle_ai_auto_draft' ) );
		}

		/**
		 * Add AI assistant action in ticket editor
		 *
		 * @param WPSC_Ticket $ticket - current ticket object.
		 * @return void
		 */
		public static function add_auto_draft_ai_tab( $ticket ) {

			$current_user = WPSC_Current_User::$current_user;
			$general_settings = get_option( 'wpsc-gs-general' );
			$tl_advanced = get_option( 'wpsc-tl-ms-advanced' );
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );

			if (
				$current_user->is_agent &&
				WPSC_Individual_Ticket::has_ticket_cap( 'reply' ) &&
				! empty( $ai_settings['is-active'] ) &&
				$ticket->last_reply_by->id == $ticket->customer->id &&
				! (
					$ticket->status->id == $general_settings['close-ticket-status'] ||
					in_array( $ticket->status->id, $tl_advanced['closed-ticket-statuses'] )
				)
			) {
				?>
				<div class="wpsc-it-editor-action">
					<span class="wpsc-link wpsc-ai-assistant" onclick="wpsc_handle_ai_auto_draft(this,'<?php echo esc_attr( $ticket->id ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_handle_ai_auto_draft' ) ); ?>');"><?php esc_attr_e( 'Auto Draft', 'wpsc-ps' ); ?></span>
				</div>
				<?php
			}
		}

		/**
		 * Handle AJAX request to show AI assistant chatbox for improving auto draft reply
		 *
		 * @return void
		 */
		public static function improve_auto_draft_reply() {

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
			$context = WPSC_PS_AI_Functions::wpsc_build_prompt_using_agent_ticket_conversation( $raw_description_reply );
			$reply = self::wpsc_improve_auto_draft_reply_using_user_prompt( $ai_settings, $context, $ticket_id );

			if ( ! $reply ) {
				wp_send_json_error( __( 'Failed to generate reply.', 'wpsc-ps' ) );
			}

			WPSC_PS_AI_Logs::insert(
				array(
					'customer'     => $current_user->customer->id,
					'ticket'       => $ticket_id,
					'provider'     => $ai_settings['provider'] ?? WPSC_PS_AIT_Provider::OPENAI,
					'model'        => $ai_settings['model'] ?? 'gpt-4o-mini',
					'feature'      => 'reply_polish',
					'tokens'       => $reply['tokens'],
					'prompt'       => mb_substr( $context, 0, 500 ),
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

			wp_send_json_success(
				array(
					'reply' => wp_kses( $reply['reply'], $allowed_tags ),
				)
			);
		}

		/**
		 * Generate auto draft for customer reply
		 *
		 * @return void
		 */
		public static function handle_ai_auto_draft_popup() {

			if ( check_ajax_referer( 'wpsc_handle_ai_auto_draft', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( __( 'Unauthorized request!', 'wpsc-ps' ), 401 );
			}

			$ticket_id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
			if ( ! $ticket_id ) {
				wp_send_json_error( __( 'Bad request!', 'wpsc-ps' ), 400 );
			}

			$ticket = new WPSC_Ticket( $ticket_id );
			if ( ! $ticket->id ) {
				wp_send_json_error( __( 'Something went wrong!', 'wpsc-ps' ), 400 );
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
					<span><?php esc_html_e( 'AI Draft', 'wpsc-ps' ); ?></span>
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
					<span><?php esc_html_e( 'Auto drafting the response', 'wpsc-ps' ); ?></span>
				</div>
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
				<textarea id="wpsc-improve-auto-draft-reply" class="wpsc-improve-auto-draft-reply" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wpsc_polish_reply_with_ai' ) ); ?>" data-ticket-id="<?php echo esc_attr( $ticket->id ); ?>" data-callback="wpsc_improve_auto_draft_reply" placeholder="<?php esc_attr_e( 'Type your message to the AI and press Enter', 'wpsc-ps' ); ?>" autofocus></textarea>
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
		 * Generate auto draft for customer reply
		 *
		 * @return void
		 */
		public static function handle_ai_auto_draft() {

			if ( check_ajax_referer( 'wpsc_handle_ai_auto_draft', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( __( 'Unauthorized request!', 'wpsc-ps' ), 401 );
			}

			$ticket_id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
			if ( ! $ticket_id ) {
				wp_send_json_error( __( 'Bad request!', 'wpsc-ps' ), 400 );
			}

			$ticket = new WPSC_Ticket( $ticket_id );
			if ( ! $ticket->id ) {
				wp_send_json_error( __( 'Something went wrong!', 'wpsc-ps' ), 400 );
			}

			WPSC_Individual_Ticket::$ticket = $ticket;
			$current_user = WPSC_Current_User::$current_user;
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( ! ( $current_user->is_agent && WPSC_Individual_Ticket::has_ticket_cap( 'reply' ) && ! empty( $ai_settings['is-active'] ) ) ) {
				wp_send_json_error( __( 'Unauthorized request!', 'wpsc-ps' ), 401 );
			}

			$provider = WPSC_PS_AIT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			$response = $provider->wpsc_auto_draft_ticket_reply( $ai_settings, $ticket );

			if ( $response['status'] == 'error' ) {
				$safe_reply = esc_html__( 'There is an error with the AI response.', 'wpsc-ps' );
			} elseif ( $response['reply'] == '[NO_KB_MATCH]' ) {
				$safe_reply = esc_html__( 'No relevant information found in the knowledge base.', 'wpsc-ps' );
			} else {

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
				$safe_reply = wp_kses( $response['reply'], $allowed_tags );
			}

			wp_send_json_success(
				array(
					'draft_reply' => $safe_reply,
				)
			);
		}

		/**
		 * Process the agent's draft reply using the AI model to generate an improved version.
		 *
		 * @param array  $ai_settings The AI assistant settings retrieved from the database, containing provider, model, and other configurations.
		 * @param string $context The combined prompt containing the agent's draft and ticket history.
		 * @param int    $ticket_id The ID of the ticket being processed, used for logging purposes.
		 * @return string The improved reply generated by the AI model, or an empty string on failure.
		 */
		public static function wpsc_improve_auto_draft_reply_using_user_prompt( $ai_settings, $context, $ticket_id ) {

			$provider = WPSC_PS_AIT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			return $provider->wpsc_improve_draft_content( $context, $ticket_id );
		}
	}
endif;
WPSC_PS_AI_Auto_Draft::init();
