<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Create_Support_Ticket' ) ) :

	final class WPSC_ACB_Create_Support_Ticket {

		/**
		 * Initialize the tool.
		 */
		public static function init() {

			add_filter( 'wpsc_acb_tool_registry', array( __CLASS__, 'register_tool' ) );
		}

		/**
		 * Register the tool in the registry.
		 *
		 * @param array $registry Current tool registry.
		 * @return array
		 */
		public static function register_tool( $registry ) {

			$registry['create_support_ticket'] = array(
				'name'                  => 'create_support_ticket',
				'description'           => 'Create a support ticket. Requires user confirmation first: before ever calling this tool with confirm_create_ticket=true, you must already have asked the customer in a plain conversational reply (no tool call) whether they want a ticket created, and they must have affirmatively agreed in a later message. Once that confirmation has happened, it is fine (and expected) to call this tool with confirm_create_ticket=true again on later turns even before you have the customer\'s full name/email - if either is still missing, it will fail with error=missing_fields and a missing array naming exactly which field(s) to ask for next; use that to word your follow-up question, do not give up or move on without the customer\'s email. Never set confirm_create_ticket=false just because information is still incomplete or you are still gathering it - false must be reserved exclusively for a clear, explicit "no"/"don\'t"/"cancel" from the customer after you asked for confirmation; using false as a placeholder while waiting for name/email will incorrectly tell the customer their request was declined. Never call this tool to ask the confirmation question yourself, never call it for uncertainty or missing-knowledge fallback, and never fabricate identity values. For guest users, both customer_name and customer_email are mandatory - a ticket cannot be created without a valid email. After an explicit decline, continue helping in chat and do not ask again unless the customer asks for it.',
				'parameters'            => array(
					'type'                 => 'object',
					'properties'           => array(
						'confirm_create_ticket' => array(
							'type'        => 'boolean',
							'description' => 'true once the customer has affirmatively agreed to ticket creation (keep using true on later turns of the same request, even while still gathering name/email). false ONLY for an explicit decline - never as a placeholder for "not ready yet" or "still missing info".',
						),
						'customer_name'         => array(
							'type'        => 'string',
							'description' => __( 'Customer full name for guest users. Mandatory for guests - if not yet known, omit this argument rather than guessing; the tool will report it as missing.', 'wpsc-ps' ),
						),
						'customer_email'        => array(
							'type'        => 'string',
							'description' => __( 'Customer email for guest users. Mandatory for guests - a ticket cannot be created without a valid email; if not yet known, omit this argument rather than guessing, and ask the customer for it based on the tool\'s missing_fields result.', 'wpsc-ps' ),
						),
					),
					'required'             => array( 'confirm_create_ticket' ),
					'additionalProperties' => false,
				),
				'handler'               => 'execute_tool_create_support_ticket',
				'class'                 => __CLASS__,
				'requires_confirmation' => true,
				'side_effecting'        => true,
				'max_calls_per_turn'    => 1,
			);
			return $registry;
		}

		/**
		 * Execute create support ticket tool.
		 *
		 * Returns structured data only; the calling LLM turn composes the actual
		 * user-facing reply (in the user's own language) from this result.
		 *
		 * @param array  $args Tool arguments.
		 * @param string $session_uuid Session ID.
		 * @return array
		 */
		public static function execute_tool_create_support_ticket( $args, $session_uuid ) {

			$confirm = self::normalize_tool_boolean( $args['confirm_create_ticket'] ?? null );
			if ( null === $confirm ) {
				return array(
					'success' => false,
					'error'   => 'missing_confirmation',
				);
			}

			if ( ! $confirm ) {
				return array(
					'success'        => true,
					'ticket_created' => false,
					'declined'       => true,
				);
			}

			$identity = self::get_logged_in_identity();
			$name = '';
			$email = '';

			if ( $identity['is_logged_in'] ) {
				$name = $identity['name'];
				$email = $identity['email'];

				if ( '' === $name || '' === $email || ! is_email( $email ) ) {
					return array(
						'success' => false,
						'error'   => 'incomplete_profile',
					);
				}
			} else {

				$name = sanitize_text_field( (string) ( $args['customer_name'] ?? '' ) );
				$raw_email = trim( (string) ( $args['customer_email'] ?? '' ) );
				$email = sanitize_email( $raw_email );

				$missing_fields = array();
				if ( '' === $name ) {
					$missing_fields[] = 'name';
				}

				if ( '' === $raw_email || ! is_email( $raw_email ) ) {
					$missing_fields[] = 'email';
				}

				if ( ! empty( $missing_fields ) ) {
					return array(
						'success' => false,
						'error'   => 'missing_fields',
						'missing' => $missing_fields,
					);
				}

				if ( self::is_placeholder_identity( $name, $email ) ) {
					return array(
						'success' => false,
						'error'   => 'placeholder_identity',
					);
				}
			}

			return self::create_ticket_from_chat_session( $session_uuid, $name, $email );
		}


		/**
		 * Shared ticket creation from chatbot session.
		 *
		 * Returns structured data only (ticket_id/error code), never pre-rendered
		 * message text - callers compose their own user-facing text: the chat
		 * tool layer feeds this back into the LLM for multi-language
		 * composition, while the plain (non-AI) manual ticket-form AJAX handler
		 * builds its own fixed WP-i18n string from the 'error'/success fields.
		 *
		 * @param string $session_uuid Session UUID.
		 * @param string $name Customer name.
		 * @param string $email Customer email.
		 * @return array
		 */
		public static function create_ticket_from_chat_session( $session_uuid, $name, $email ) {

			$name = sanitize_text_field( (string) $name );
			$raw_email = trim( (string) $email );
			$email = sanitize_email( $raw_email );
			$session_uuid = sanitize_text_field( (string) $session_uuid );

			if ( '' === $name || '' === $raw_email || ! is_email( $raw_email ) ) {
				return array(
					'success' => false,
					'error'   => 'invalid_identity',
				);
			}

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( empty( $ai_settings['is-active'] ) ) {
				return array(
					'success' => false,
					'error'   => 'unauthorized',
				);
			}

			$filter = array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'slug'    => 'status',
						'compare' => '=',
						'val'     => WPSC_ACB_Status::ACTIVE,
					),
					array(
						'slug'    => 'session_id',
						'compare' => '=',
						'val'     => $session_uuid,
					),
				),
			);

			$session = WPSC_ACB_Sessions::find( $filter )['results'][0] ?? null;
			if ( empty( $session ) ) {
				return array(
					'success' => false,
					'error'   => 'no_active_session',
				);
			}

			$request_visitor_id = self::get_request_visitor_id();
			if ( '' !== $request_visitor_id && (string) $session->visitor_id !== $request_visitor_id ) {
				return array(
					'success' => false,
					'error'   => 'unauthorized',
				);
			}

			// Idempotency guard: bail out if this session already has a ticket,
			// in case the model calls the tool again within the same turn (the
			// agentic loop also caps this tool at 1 call/turn generically, this
			// is the data-level backstop).
			if ( ! empty( $session->ticket_id ) ) {

				$existing_ticket = new WPSC_Ticket( (int) $session->ticket_id );
				if ( $existing_ticket->id ) {

					// Safe to clear here: the later feedback-reaction popup
					// (chatbot_end_conversation()) looks the session up by UUID
					// straight from the DB (no status filter) and rebuilds the
					// transcript from psmsc_acb_messages if the transient cache
					// is empty - it never depends on this transient surviving.
					WPSC_ACB_Cache::clear_acb_cache( $session->id );
					WPSC_ACB_Cookies::delete_session_cookie( 'wpsc_acb_session_id' );
					return array(
						'success'           => true,
						'ticket_created'    => true,
						'already_created'   => true,
						'ticket_id'         => $existing_ticket->id,
						'ticket_display_id' => self::format_ticket_display_id( $existing_ticket->id ),
						'end_conversation'  => true,
						'session_expired'   => true,
						'reason'            => 'ticket_created',
					);
				}
			}

			$provider = WPSC_AIBOT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			$customer = WPSC_DF_Customer::get_customer_record( $name, $email );

			$subject = WPSC_ACB_Chats::generate_session_subject_and_summary( 'subject', $provider, $ai_settings, $session->id );
			if ( '' === $subject ) {
				$subject = $session->subject;
			}
			$summary = WPSC_ACB_Chats::generate_session_subject_and_summary( 'summary', $provider, $ai_settings, $session->id );

			$data = array();
			$data['customer']      = $customer->id ? $customer->id : 0;
			$data['subject']       = $subject;
			$data['status']        = WPSC_DF_Status::get_default_value( WPSC_Custom_Field::get_cf_by_slug( 'status' ) );
			$data['priority']      = WPSC_DF_Priority::get_default_value( WPSC_Custom_Field::get_cf_by_slug( 'priority' ) );
			$data['category']      = WPSC_DF_Category::get_default_value( WPSC_Custom_Field::get_cf_by_slug( 'category' ) );
			$data['assigned_agent'] = '';
			$data['last_reply_on'] = ( new DateTime() )->format( 'Y-m-d H:i:s' );
			$data['last_reply_by'] = $customer->id;
			$data['date_created']  = ( new DateTime() )->format( 'Y-m-d H:i:s' );
			$data['date_updated']  = ( new DateTime() )->format( 'Y-m-d H:i:s' );
			$data['source']        = 'chatbot';
			$data['ip_address']    = WPSC_DF_IP_Address::get_current_user_ip();
			$data['browser']       = WPSC_DF_Browser::get_user_browser();
			$data['os']            = WPSC_DF_OS::get_user_platform();
			$data['misc']          = wp_json_encode( array( 'chat_session_id' => $session->id ) );

			$ticket = WPSC_Ticket::insert( $data );
			if ( ! $ticket->id ) {
				return array(
					'success' => false,
					'error'   => 'ticket_creation_failed',
				);
			}

			$session->subject = $subject;
			$session->status = WPSC_ACB_Status::HANDOFF;
			$session->ticket_id = $ticket->id;
			$session->save();

			$thread = WPSC_Thread::insert(
				array(
					'ticket'      => $ticket->id,
					'customer'    => $ticket->customer->id,
					'type'        => 'report',
					'body'        => $summary,
					'attachments' => '',
					'ip_address'  => $ticket->ip_address,
					'source'      => $ticket->source,
					'os'          => $ticket->os,
					'browser'     => $ticket->browser,
				)
			);

			// Generate session transcript attachment for the ticket thread.
			$attachment = self::generate_session_attachment( $ticket, $thread, $session->id );

			do_action( 'wpsc_create_new_ticket', $ticket );
			WPSC_Email_Notifications::send_background_emails();

			// Safe to clear here: the later feedback-reaction popup
			// (chatbot_end_conversation()) looks the session up by UUID
			// straight from the DB (no status filter) and rebuilds the
			// transcript from psmsc_acb_messages if the transient cache is
			// empty - it never depends on this transient surviving. This
			// also drops the now-stale memoized known_user_context (see
			// WPSC_ACB_Cache::get/set_known_user_context()) instead of
			// letting it sit around for up to an hour after the session
			// has already been handed off to a ticket.
			WPSC_ACB_Cache::clear_acb_cache( $session->id );
			WPSC_ACB_Cookies::delete_session_cookie( 'wpsc_acb_session_id' );
			return array(
				'success'           => true,
				'ticket_created'    => true,
				'ticket_id'         => $ticket->id,
				'ticket_display_id' => self::format_ticket_display_id( $ticket->id ),
				'end_conversation'  => true,
				'session_expired'   => true,
				'reason'            => 'ticket_created',
			);
		}

		/**
		 * Format a ticket ID with the site's configured ticket ID prefix.
		 *
		 * @param int $ticket_id Ticket ID.
		 * @return string
		 */
		private static function format_ticket_display_id( $ticket_id ) {

			$general_settings = get_option( 'wpsc-gs-general' );
			$ticket_alice = $general_settings['ticket-alice'] ?? '';

			return $ticket_alice . (string) $ticket_id;
		}

		/**
		 * Generate a concise and meaningful attachment for the chat conversation based on the conversation history.
		 *
		 * @param WPSC_Ticket $ticket The ticket object to associate the attachment with.
		 * @param WPSC_Thread $thread The thread object to associate the attachment with.
		 * @param int         $session_id The ID of the chat session to generate the attachment for.
		 * @return string The generated attachment for the chat conversation in HTML format.
		 */
		public static function generate_session_attachment( $ticket, $thread, $session_id ) {

			// Get conversation content.
			$conversation_text = WPSC_ACB_Chats::get_conversation_text( 'text', $session_id );

			// Validate upload directory.
			$upload_dir = wp_upload_dir();
			if ( empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
				return 0;
			}

			$today = new DateTime( 'now' );
			$base_dir = $upload_dir['basedir'] . '/wpsc/' . $today->format( 'Y' ) . '/' . $today->format( 'm' );
			if ( ! file_exists( $base_dir ) ) {
				if ( ! wp_mkdir_p( $base_dir ) ) {
					return 0;
				}
			}

			// Check writable.
			if ( ! is_writable( $base_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				return 0;
			}

			// Normalize content.
			$conversation_text = str_replace( array( "\r\n", "\r" ), "\n", $conversation_text );
			$conversation_text = trim( $conversation_text );

			// Ensure UTF-8.
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$conversation_text = mb_convert_encoding( $conversation_text, 'UTF-8', 'UTF-8' );
			}

			// Create file name.
			$file_name = time() . '_chat_transcript_' . sanitize_file_name( $ticket->id . '.txt' );

			// Absolute file path.
			$file_path = trailingslashit( $base_dir ) . $file_name;

			// Remove old file if exists.
			if ( file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}

			// Create file.
			$result = file_put_contents( $file_path, $conversation_text, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			if ( false === $result || ! file_exists( $file_path ) ) {
				return 0;
			}

			// Relative path stored in SupportCandy attachment table.
			$filepath_short = '/wpsc/' . $today->format( 'Y' ) . '/' . $today->format( 'm' ) . '/' . $file_name;

			// Prepare attachment data.
			$data = array(
				'name'         => $file_name,
				'is_image'     => 0,
				'file_path'    => $filepath_short,
				'source'       => 'report',
				'source_id'    => $thread->id,
				'ticket_id'    => $ticket->id,
				'date_created' => $today->format( 'Y-m-d H:i:s' ),
				'is_active'    => 1,
			);

			// Insert attachment.
			$new_attachement = WPSC_Attachment::insert( $data );

			if ( empty( $new_attachement ) ) {
				return 0;
			}

			// Get attachment ID if needed.
			$attachment_id = is_object( $new_attachement ) ? $new_attachement->id : $new_attachement;

			$thread->attachments = array( $attachment_id );
			$thread->save();
			return $attachment_id;
		}

		/**
		 * Resolve the current visitor's identity if they are logged in.
		 *
		 * Must use the exact same "known customer" condition as
		 * WPSC_ACB_Chats::get_known_user_context() (is_customer + customer
		 * record on the canonical WPSC_Current_User::$current_user instance) -
		 * NOT a raw WP_User/wp_get_current_user() check. Those can disagree
		 * (e.g. if WPSC_Current_User resolved the visitor as a guest for this
		 * request before WordPress lazily populated the current user), which
		 * previously let the model converse with the customer as a guest
		 * (asking for name/email) while this tool silently created the ticket
		 * under a different, real account it detected on its own - discarding
		 * whatever guest identity the customer had just typed.
		 *
		 * @return array{is_logged_in: bool, name: string, email: string}
		 */
		private static function get_logged_in_identity() {

			$current_user = WPSC_Current_User::$current_user;

			// Note: WPSC_Customer exposes 'id' via a magic __get() with no
			// __isset(), and empty( $current_user->customer->id ) always
			// evaluates true regardless of the actual value in that case -
			// verified directly (empty() on a magic-getter-only property
			// short-circuits before ever calling __get()). Read the value
			// out first via a plain property access and test that instead.
			$customer_id = empty( $current_user ) || empty( $current_user->is_customer ) || empty( $current_user->customer ) ? '' : $current_user->customer->id;
			if ( ! $customer_id ) {
				return array(
					'is_logged_in' => false,
					'name'         => '',
					'email'        => '',
				);
			}

			$name = sanitize_text_field( (string) $current_user->customer->name );
			$email = sanitize_email( (string) $current_user->customer->email );

			if ( '' === $name || '' === $email || ! is_email( $email ) ) {
				return array(
					'is_logged_in' => false,
					'name'         => '',
					'email'        => '',
				);
			}

			return array(
				'is_logged_in' => true,
				'name'         => $name,
				'email'        => $email,
			);
		}

		/**
		 * Normalize tool boolean values from model arguments.
		 *
		 * @param mixed $value Raw argument value.
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
		 * Check placeholder or test identity values that should not be accepted.
		 *
		 * @param string $name Customer name.
		 * @param string $email Customer email.
		 * @return bool
		 */
		private static function is_placeholder_identity( $name, $email ) {

			$name = strtolower( trim( (string) $name ) );
			$email = strtolower( trim( (string) $email ) );

			$placeholder_names = array( 'user', 'test', 'customer', 'guest', 'anonymous' );
			if ( in_array( $name, $placeholder_names, true ) ) {
				return true;
			}

			$placeholder_emails = array(
				'user@example.com',
				'test@example.com',
				'customer@example.com',
				'guest@example.com',
				'anonymous@example.com',
			);

			if ( in_array( $email, $placeholder_emails, true ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Resolve visitor identifier from current request context.
		 *
		 * @return string
		 */
		private static function get_request_visitor_id() {

			$current_user = WPSC_Current_User::$current_user;
			if ( ! empty( $current_user->user->ID ) ) {
				return (string) absint( $current_user->user->ID );
			}

			$cookie_name = 'wpsc_acb_visitor_id';
			$cookie_val = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

			return is_string( $cookie_val ) ? trim( $cookie_val ) : '';
		}
	}

endif;
WPSC_ACB_Create_Support_Ticket::init();
