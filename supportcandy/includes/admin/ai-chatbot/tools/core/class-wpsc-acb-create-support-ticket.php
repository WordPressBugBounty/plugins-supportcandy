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
				'name'        => 'create_support_ticket',
				'description' => 'Create a support ticket. Use this tool only for ticket-confirmation decisions in any language. Set confirm_create_ticket=true when user asks to create/open/start/submit a ticket or clearly confirms in their language. Set confirm_create_ticket=false only when user explicitly declines ticket creation after the assistant has already asked a ticket-confirmation question in this conversation. Never call this tool with false for uncertainty, unrelated questions, or missing-knowledge fallback. Never fabricate identity values. After an explicit decline, continue helping in chat and do not ask for ticket creation again unless the customer asks for it.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'confirm_create_ticket' => array(
							'type'        => 'boolean',
							'description' => 'true when user requested or confirmed ticket creation; false only for explicit decline after the assistant asked a ticket-confirmation question.',
						),
						'customer_name'         => array(
							'type'        => 'string',
							'description' => __( 'Customer full name for guest users.', 'wpsc-ps' ),
						),
						'customer_email'        => array(
							'type'        => 'string',
							'description' => __( 'Customer email for guest users.', 'wpsc-ps' ),
						),
					),
					'required'             => array( 'confirm_create_ticket' ),
					'additionalProperties' => false,
				),
				'handler'     => 'execute_tool_create_support_ticket',
				'class'       => __CLASS__,
			);
			return $registry;
		}

		/**
		 * Execute create support ticket tool.
		 *
		 * @param array  $args Tool arguments.
		 * @param string $session_uuid Session ID.
		 * @return array
		 */
		public static function execute_tool_create_support_ticket( $args, $session_uuid ) {

			$confirm = self::normalize_tool_boolean( $args['confirm_create_ticket'] ?? null );
			if ( null === $confirm ) {
				return array(
					'success'  => true,
					'response' => '<p>' . esc_html__( 'Do you want me to create a support ticket now?', 'wpsc-ps' ) . '</p>',
				);
			}

			if ( ! $confirm ) {
				return array(
					'success'  => true,
					'response' => '<p>' . esc_html__( 'No problem. I can continue helping you here.', 'wpsc-ps' ) . '</p>',
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
						'success'  => true,
						'response' => '<p>' . esc_html__( 'I found your account but your profile name/email is incomplete. Please share your name and email to create the ticket.', 'wpsc-ps' ) . '</p>',
					);
				}
			} else {

				$name = sanitize_text_field( (string) ( $args['customer_name'] ?? '' ) );
				$raw_email = trim( (string) ( $args['customer_email'] ?? '' ) );
				$email = sanitize_email( $raw_email );

				$missing_fields = array();
				if ( '' === $name ) {
					$missing_fields[] = esc_html__( 'name', 'wpsc-ps' );
				}

				if ( '' === $raw_email || ! is_email( $raw_email ) ) {
					$missing_fields[] = esc_html__( 'email', 'wpsc-ps' );
				}

				if ( ! empty( $missing_fields ) ) {
					return array(
						'success'  => true,
						'response' => '<p>' . sprintf(
							/* translators: %s: comma separated missing fields. */
							esc_html__( 'Before I create your ticket, please share your %s', 'wpsc-ps' ),
							esc_html( implode( ', ', $missing_fields ) )
						) . '?</p>',
					);
				}

				if ( self::is_placeholder_identity( $name, $email ) ) {
					return array(
						'success'  => true,
						'response' => '<p>' . esc_html__( 'Before I create your ticket, please provide your real name and email in this chat message.', 'wpsc-ps' ) . '</p>',
					);
				}
			}

			$result = self::create_ticket_from_chat_session( $session_uuid, $name, $email );
			if ( ! $result['success'] ) {
				return array(
					'success'  => true,
					'response' => '<p>' . esc_html( $result['message'] ) . '</p>',
				);
			}

			$message = wp_kses_post( (string) $result['message'] );
			if ( 0 === preg_match( '/<\\/?(p|ul|ol|li|br)\\b/i', $message ) ) {
				$message = '<p>' . esc_html( $message ) . '</p>';
			}

			return array(
				'success'          => true,
				'response'         => wp_kses_post( $message ),
				'end_conversation' => true,
			);
		}


		/**
		 * Shared ticket creation from chatbot session.
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
					'message' => __( 'Valid name and email are required.', 'wpsc-ps' ),
				);
			}

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( empty( $ai_settings['is-active'] ) ) {
				return array(
					'success' => false,
					'message' => __( 'Unauthorized request!', 'wpsc-ps' ),
				);
			}

			$page_settings = get_option( 'wpsc-gs-page-settings', array() );
			$provider = WPSC_AIBOT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			$customer = WPSC_DF_Customer::get_customer_record( $name, $email );

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
					'message' => __( 'No active chat session found.', 'wpsc-ps' ),
				);
			}

			$request_visitor_id = self::get_request_visitor_id();
			if ( '' !== $request_visitor_id && (string) $session->visitor_id !== $request_visitor_id ) {
				return array(
					'success' => false,
					'message' => __( 'Unauthorized request!', 'wpsc-ps' ),
				);
			}

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

			$ticket = WPSC_Ticket::insert( $data );
			if ( ! $ticket->id ) {
				return array(
					'success' => false,
					'message' => __( 'Error creating ticket.', 'wpsc-ps' ),
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

			$message = '<p>' . esc_html__( 'Your support ticket has been created successfully. Our support team will review your issue and get back to you as soon as possible.', 'wpsc-ps' ) . '</p>';
			$general_settings = get_option( 'wpsc-gs-general' );
			$ticket_alice     = $general_settings['ticket-alice'];
			$message .= '<p>' . esc_html__( 'Your ticket ID is:', 'wpsc-ps' ) . ' ' . esc_html( $ticket_alice ) . esc_html( $ticket->id ) . '</p>';

			WPSC_ACB_Cookies::delete_session_cookie( 'wpsc_acb_session_id' );
			return array(
				'success'          => true,
				'session_expired'  => true,
				'message'          => $message,
				'end_conversation' => true,
			);
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
		 * Resolve logged-in identity for chatbot ticket creation.
		 *
		 * @return array
		 */
		private static function get_logged_in_identity() {

			$current_user = WPSC_Current_User::$current_user;
			$user = isset( $current_user->user ) ? $current_user->user : null;

			if ( ! $user || empty( $user->ID ) ) {
				$user = wp_get_current_user();
			}

			if ( ! $user || empty( $user->ID ) ) {
				return array(
					'is_logged_in' => false,
					'name'         => '',
					'email'        => '',
				);
			}

			$name = trim( (string) $user->display_name );
			if ( '' === $name ) {
				$name = trim( (string) $user->user_login );
			}

			return array(
				'is_logged_in' => true,
				'name'         => sanitize_text_field( $name ),
				'email'        => sanitize_email( (string) $user->user_email ),
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
