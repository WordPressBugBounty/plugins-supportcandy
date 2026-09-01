<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Get_Ticket_Status' ) ) :

	final class WPSC_ACB_Get_Ticket_Status {

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
				'description' => 'Get ticket status details securely. Always use this tool when customer asks for ticket status, ticket update, or ticket progress. Never guess or fabricate ticket_id or email values. For logged-in users, ask for ticket_id first if it is not already shared by the user, then return details. For guests, require both ticket_id and email; the tool checks that the email matches the ticket\'s own customer email and returns the ticket details directly when it matches.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'ticket_id' => array(
							'type'        => 'string',
							'description' => __( 'Ticket identifier shared by customer (for example: 123 or Ticket #123).', 'wpsc-ps' ),
						),
						'email'     => array(
							'type'        => 'string',
							'description' => __( 'Customer email. Required for guest verification.', 'wpsc-ps' ),
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

			// Guest's email matches the ticket's own customer email - that is the full
			// verification for this tool now; no OTP round-trip.
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
	}

endif;
WPSC_ACB_Get_Ticket_Status::init();
