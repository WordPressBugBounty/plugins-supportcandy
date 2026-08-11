<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Get_Ticket_Status' ) ) :

	final class WPSC_ACB_Get_Ticket_Status extends WPSC_Email_Notifications {

		/**
		 * Max OTP verification attempts allowed within a single chat turn (i.e.
		 * across the tool calls of one agentic loop), separate from and
		 * tighter than the existing cross-turn 5-attempt transient lockout -
		 * stops the model from brute-forcing many guesses back-to-back inside
		 * one multi-iteration turn.
		 *
		 * @var int
		 */
		const INTRA_TURN_OTP_ATTEMPT_LIMIT = 2;

		/**
		 * Count of OTP verification attempts made so far in this PHP request.
		 *
		 * @var int
		 */
		private static $otp_verify_attempts_this_turn = 0;

		/**
		 * Initialize the tool.
		 */
		public static function init() {

			add_filter( 'wpsc_acb_tool_registry', array( __CLASS__, 'register_tool' ) );
		}

		/**
		 * Register tool definition.
		 *
		 * @param array $registry Current registry.
		 * @return array
		 */
		public static function register_tool( $registry ) {

			$registry['get_ticket_status'] = array(
				'name'        => 'get_ticket_status',
				'description' => 'Get ticket status details securely. Always use this tool when customer asks for ticket status, ticket update, or ticket progress. Never guess or fabricate ticket_id, email, or otp values. For logged-in users, ask for ticket_id first if it is not already shared by the user, then return details. For guests, require ticket_id and email, then send an OTP to email before revealing ticket details. Verify OTP in a follow-up call with otp parameter.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'ticket_id'  => array(
							'type'        => 'string',
							'description' => __( 'Ticket identifier shared by customer (for example: 123 or Ticket #123).', 'wpsc-ps' ),
						),
						'email'      => array(
							'type'        => 'string',
							'description' => __( 'Customer email. Required for guest verification.', 'wpsc-ps' ),
						),
						'otp'        => array(
							'type'        => 'string',
							'description' => __( 'One-time password sent to guest email for verification.', 'wpsc-ps' ),
						),
						'resend_otp' => array(
							'type'        => 'boolean',
							'description' => __( 'Set true to resend OTP for guests when requested.', 'wpsc-ps' ),
						),
					),
					'additionalProperties' => false,
				),
				'handler'     => 'execute_tool_get_ticket_status',
				'class'       => __CLASS__,
			);

			return $registry;
		}

		/**
		 * Execute get ticket status tool.
		 *
		 * Returns structured data only (a 'need' code describing what's missing,
		 * or a 'ticket' payload on success); the calling LLM turn composes the
		 * actual user-facing reply (in the user's own language) from this result.
		 *
		 * @param array  $args Tool arguments.
		 * @param string $session_uuid Session UUID.
		 * @return array
		 */
		public static function execute_tool_get_ticket_status( $args, $session_uuid ) {

			$parsed_ticket_id = self::parse_ticket_id( $args['ticket_id'] ?? '' );
			if ( ! $parsed_ticket_id || ! self::was_ticket_id_shared_by_user( $parsed_ticket_id ) ) {
				return array(
					'success' => true,
					'need'    => 'ticket_id',
				);
			}

			$ticket = self::get_ticket_object( $parsed_ticket_id );
			if ( ! $ticket ) {
				return array(
					'success' => true,
					'need'    => 'ticket_not_found',
				);
			}

			$current_user = WPSC_Current_User::$current_user;
			if ( self::is_logged_in_user() ) {

				if ( ! self::can_logged_in_user_access_ticket( $ticket, $current_user ) ) {
					return array(
						'success' => true,
						'need'    => 'permission_denied',
					);
				}

				return array(
					'success' => true,
					'ticket'  => self::build_ticket_status_data( $ticket ),
				);
			}

			$email_raw = trim( (string) ( $args['email'] ?? '' ) );
			$email = sanitize_email( $email_raw );

			if ( '' === $email_raw || ! is_email( $email_raw ) ) {
				return array(
					'success' => true,
					'need'    => 'guest_email',
				);
			}

			if ( ! self::is_guest_authorized_for_ticket( $ticket, $email ) ) {
				return array(
					'success' => true,
					'need'    => 'guest_verification_failed',
				);
			}

			$session_uuid = sanitize_text_field( (string) $session_uuid );
			$otp_state_key = self::get_otp_state_key( $session_uuid, $ticket->id, $email );
			$otp_state = get_transient( $otp_state_key );

			$otp_input = sanitize_text_field( (string) ( $args['otp'] ?? '' ) );
			$resend_otp = self::normalize_tool_boolean( $args['resend_otp'] ?? null );

			if ( '' === $otp_input ) {

				if ( ! empty( $otp_state['otp_id'] ) && true !== $resend_otp ) {
					return array(
						'success' => true,
						'need'    => 'otp_pending',
					);
				}

				$sent = self::send_guest_otp( $email, $ticket->id, $otp_state_key );
				if ( ! $sent ) {
					return array(
						'success' => true,
						'need'    => 'otp_send_failed',
					);
				}

				return array(
					'success' => true,
					'need'    => 'otp_sent',
				);
			}

			// Intra-turn brute-force guard: separate from and tighter than the
			// cross-turn 5-attempt transient lockout below, this stops the model
			// from cycling through many OTP guesses within one agentic-loop turn.
			++self::$otp_verify_attempts_this_turn;
			if ( self::$otp_verify_attempts_this_turn > self::INTRA_TURN_OTP_ATTEMPT_LIMIT ) {
				return array(
					'success' => true,
					'need'    => 'otp_locked',
				);
			}

			if ( empty( $otp_state['otp_id'] ) ) {
				return array(
					'success' => true,
					'need'    => 'otp_not_requested',
				);
			}

			$otp = new WPSC_Email_OTP( (int) $otp_state['otp_id'] );
			if ( ! $otp->id || strtolower( (string) $otp->email ) !== strtolower( (string) $email ) ) {
				delete_transient( $otp_state_key );
				return array(
					'success' => true,
					'need'    => 'otp_expired',
				);
			}

			$attempts = isset( $otp_state['attempts'] ) ? (int) $otp_state['attempts'] : 0;
			if ( $attempts >= 5 ) {
				WPSC_Email_OTP::destroy( $otp );
				delete_transient( $otp_state_key );
				return array(
					'success' => true,
					'need'    => 'otp_locked',
				);
			}

			if ( ! $otp->is_valid( $otp_input ) ) {

				$otp_state['attempts'] = $attempts + 1;
				set_transient( $otp_state_key, $otp_state, MINUTE_IN_SECONDS * 10 );

				if ( $otp_state['attempts'] >= 5 ) {
					WPSC_Email_OTP::destroy( $otp );
					delete_transient( $otp_state_key );
					return array(
						'success' => true,
						'need'    => 'otp_locked',
					);
				}

				return array(
					'success' => true,
					'need'    => 'otp_invalid',
				);
			}

			WPSC_Email_OTP::destroy( $otp );
			delete_transient( $otp_state_key );

			return array(
				'success' => true,
				'ticket'  => self::build_ticket_status_data( $ticket ),
			);
		}

		/**
		 * Parse ticket id from customer input.
		 *
		 * @param mixed $ticket_id Raw ticket id input.
		 * @return int
		 */
		private static function parse_ticket_id( $ticket_id ) {

			$ticket_id = is_scalar( $ticket_id ) ? trim( (string) $ticket_id ) : '';
			if ( '' === $ticket_id ) {
				return 0;
			}

			if ( ctype_digit( $ticket_id ) ) {
				return (int) $ticket_id;
			}

			if ( preg_match( '/(\d+)/', $ticket_id, $matches ) ) {
				return (int) $matches[1];
			}

			return 0;
		}

		/**
		 * Check whether ticket id was actually shared by user in chat history.
		 *
		 * @param int $ticket_id Ticket id.
		 * @return bool
		 */
		private static function was_ticket_id_shared_by_user( $ticket_id ) {

			$ticket_id = absint( $ticket_id );
			if ( ! $ticket_id ) {
				return false;
			}

			if ( ! class_exists( 'WPSC_ACB_Chats' ) ) {
				return false;
			}

			$history = WPSC_ACB_Chats::get_conversation_history();
			if ( ! is_array( $history ) || empty( $history ) ) {
				return false;
			}

			$ticket_id_string = (string) $ticket_id;
			foreach ( $history as $message ) {

				$role = isset( $message['role'] ) ? sanitize_key( (string) $message['role'] ) : '';
				if ( 'user' !== $role ) {
					continue;
				}

				$content = isset( $message['content'] ) ? wp_strip_all_tags( (string) $message['content'] ) : '';
				if ( '' === $content ) {
					continue;
				}

				$has_exact = preg_match( '/(^|\D)' . preg_quote( $ticket_id_string, '/' ) . '(\D|$)/', $content );
				if ( 1 === $has_exact ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Get ticket object by id from active or archive tickets.
		 *
		 * @param int $ticket_id Ticket id.
		 * @return object|null
		 */
		private static function get_ticket_object( $ticket_id ) {

			$ticket_id = absint( $ticket_id );
			if ( ! $ticket_id ) {
				return null;
			}

			$ticket = new WPSC_Ticket( $ticket_id );
			if ( $ticket->id ) {
				return $ticket;
			}

			// currently we do not support archive tickets for this tool, but we can add it in future if needed.

			return null;
		}

		/**
		 * Check if current visitor is logged in.
		 *
		 * @return bool
		 */
		private static function is_logged_in_user() {

			$current_user = WPSC_Current_User::$current_user;
			if ( ! empty( $current_user->user->ID ) ) {
				return true;
			}

			$user = wp_get_current_user();
			return ! empty( $user->ID );
		}

		/**
		 * Check logged-in user permission for this ticket.
		 *
		 * @param WPSC_Ticket|WPSC_Archive_Ticket $ticket Ticket object.
		 * @param WPSC_Current_User               $current_user Current user.
		 * @return bool
		 */
		private static function can_logged_in_user_access_ticket( $ticket, $current_user ) {

			if ( WPSC_Functions::is_site_admin() ) {
				return true;
			}

			$customer_id = (int) $current_user->customer->id;
			$ticket_customer_id = (int) $ticket->customer->id;

			if ( $current_user->is_agent ) {
				if ( WPSC_Agent::has_ticket_cap( $ticket, 'view' ) || ( $customer_id === $ticket_customer_id ) ) {
					return true;
				}
			}

			return $customer_id > 0 && $customer_id === $ticket_customer_id;
		}

		/**
		 * Verify guest can request this ticket.
		 *
		 * @param WPSC_Ticket|WPSC_Archive_Ticket $ticket Ticket object.
		 * @param string                          $email Guest email.
		 * @return bool
		 */
		private static function is_guest_authorized_for_ticket( $ticket, $email ) {

			$ticket_email = (string) $ticket->customer->email;
			return '' !== $ticket_email && strtolower( $ticket_email ) === strtolower( (string) $email );
		}

		/**
		 * Send OTP email for guest status verification.
		 *
		 * @param string $email Guest email.
		 * @param int    $ticket_id Ticket id.
		 * @param string $state_key Transient key.
		 * @return bool
		 */
		private static function send_guest_otp( $email, $ticket_id, $state_key ) {

			if ( ! class_exists( 'WPSC_EN_Guest_Login_OTP' ) ) {
				return false;
			}

			$otp = WPSC_Email_OTP::insert(
				array(
					'email'       => $email,
					'date_expiry' => ( new DateTime() )->add( new DateInterval( 'PT10M' ) )->format( 'Y-m-d H:i:s' ),
					'data'        => wp_json_encode(
						array(
							'email'     => $email,
							'ticket_id' => $ticket_id,
						),
					),
				)
			);

			if ( ! $otp || ! $otp->id ) {
				return false;
			}

			self::send_otp( $otp );
			set_transient(
				$state_key,
				array(
					'otp_id'   => (int) $otp->id,
					'attempts' => 0,
				),
				MINUTE_IN_SECONDS * 10
			);

			return true;
		}

		/**
		 * Build transient key for guest OTP verification state.
		 *
		 * @param string $session_uuid Session UUID.
		 * @param int    $ticket_id Ticket id.
		 * @param string $email Guest email.
		 * @return string
		 */
		private static function get_otp_state_key( $session_uuid, $ticket_id, $email ) {

			$session_uuid = sanitize_text_field( (string) $session_uuid );
			$visitor_id = WPSC_ACB_Cookies::get_request_visitor_id();
			$ip_address = WPSC_DF_IP_Address::get_current_user_ip();
			$key_material = strtolower( $session_uuid . '|' . $visitor_id . '|' . $ticket_id . '|' . $email . '|' . $ip_address );

			return 'wpsc_acb_ticket_status_otp_' . md5( $key_material );
		}

		/**
		 * Build structured ticket status data for the calling LLM turn to
		 * compose a user-facing reply from.
		 *
		 * @param WPSC_Ticket|WPSC_Archive_Ticket $ticket Ticket object.
		 * @return array
		 */
		private static function build_ticket_status_data( $ticket ) {

			$last_updated = wp_date( 'M d, Y h:i A', ( $ticket->date_updated )->setTimezone( wp_timezone() )->getTimestamp() );

			return array(
				'ticket_id'    => (int) $ticket->id,
				'status'       => (string) $ticket->status->name,
				'priority'     => (string) $ticket->priority->name,
				'category'     => (string) $ticket->category->name,
				'last_updated' => (string) $last_updated,
				'ticket_url'   => (string) WPSC_Functions::get_ticket_url( $ticket->id, 1 ),
			);
		}

		/**
		 * Normalize boolean values from tool args.
		 *
		 * @param mixed $value Raw value.
		 * @return bool|null
		 */
		private static function normalize_tool_boolean( $value ) {

			if ( is_bool( $value ) ) {
				return $value;
			}

			if ( is_string( $value ) ) {
				$normalized = strtolower( trim( $value ) );
				if ( in_array( $normalized, array( 'yes', 'y', 'true', '1' ), true ) ) {
					return true;
				}

				if ( in_array( $normalized, array( 'no', 'n', 'false', '0' ), true ) ) {
					return false;
				}
			}

			if ( is_numeric( $value ) ) {
				return ( (int) $value ) === 1;
			}

			return null;
		}

		/**
		 * Send OTP
		 *
		 * @param WPSC_Email_OTP $otp - otp.
		 * @return void
		 */
		public static function send_otp( $otp ) {

			$en = new WPSC_Email_Notifications();

			// from name & email.
			$en_general = get_option( 'wpsc-en-general' );
			$en->from_name  = $en_general['from-name'] ?? '';
			$en->from_email = $en_general['from-email'] ?? '';
			$en->reply_to   = $en_general['reply-to'] ? $en_general['reply-to'] : $en->from_email;

			$body = '<p>' . esc_html__( 'Hello,', 'wpsc-ps' ) . '</p>'
				. '<p>' . esc_html__( 'Use the one-time password below to verify your email and view your ticket status:', 'wpsc-ps' ) . '</p>'
				. '<p><strong>{{otp}}</strong></p>'
				. '<p>' . esc_html__( 'This code will expire in 10 minutes. If you did not request this, you can safely ignore this email.', 'wpsc-ps' ) . '</p>';

			$en->subject = __( 'Your OTP to verify ticket status', 'wpsc-ps' );
			$en->body    = str_replace( '{{otp}}', $otp->otp, $body );
			$en->to      = array( $otp->email );
			$en->send();
		}
	}

endif;
WPSC_ACB_Get_Ticket_Status::init();
