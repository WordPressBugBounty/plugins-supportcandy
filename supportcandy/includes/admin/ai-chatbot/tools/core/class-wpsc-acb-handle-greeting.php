<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Handle_Greeting' ) ) :

	final class WPSC_ACB_Handle_Greeting {

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

			$registry['handle_greeting'] = array(
				'name'        => 'handle_greeting',
				'description' => 'Handle pure small-talk messages in any language. AI should decide the intent internally and provide a user-ready reply. Optionally request to end conversation when user clearly intends to end chat. Never use this tool for mixed messages that also contain a support issue or question.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'reply'                 => array(
							'type'        => 'string',
							'description' => 'User-ready short reply for the current small-talk message.',
						),
						'end_conversation'      => array(
							'type'        => 'boolean',
							'description' => 'Set true only when user clearly intends to end the chat.',
						),
						'disable_input_message' => array(
							'type'        => 'string',
							'description' => 'Optional message shown when chat input is disabled after ending conversation.',
						),
					),
					'required'             => array( 'reply' ),
					'additionalProperties' => false,
				),
				'handler'     => 'execute_tool_handle_greeting',
				'class'       => __CLASS__,
			);

			return $registry;
		}

		/**
		 * Execute greeting/small-talk tool.
		 *
		 * @param array  $args Tool arguments.
		 * @param string $session_uuid Session UUID.
		 * @return array
		 */
		public static function execute_tool_handle_greeting( $args, $session_uuid ) {

			$reply = sanitize_text_field( (string) ( $args['reply'] ?? '' ) );
			if ( '' === $reply ) {
				$reply = __( 'I am here to help. Please tell me what you need.', 'wpsc-ps' );
			}

			$end_conversation = ! empty( $args['end_conversation'] );

			if ( ! $end_conversation ) {
				return array(
					'success'  => true,
					'response' => '<p>' . esc_html( $reply ) . '</p>',
				);
			}

			$session_uuid = sanitize_text_field( (string) $session_uuid );
			$session = $session_uuid ? WPSC_ACB_Sessions::get_session_by_session_uuid( $session_uuid ) : null;

			if ( $session ) {
				$session->status = WPSC_ACB_Status::RESOLVED;
				$session->save();
				WPSC_ACB_Cache::clear_acb_cache( $session->id );
			}

			WPSC_ACB_Cookies::delete_session_cookie( 'wpsc_acb_session_id' );

			$end_message = sanitize_text_field( (string) ( $args['disable_input_message'] ?? '' ) );
			if ( '' === $end_message ) {
				$end_message = __( 'Conversation ended. You can start a new chat anytime.', 'wpsc-ps' );
			}

			return array(
				'success'               => true,
				'response'              => '<p>' . esc_html( $reply ) . '</p>',
				'end_conversation'      => true,
				'session_expired'       => true,
				'disable_input_message' => $end_message,
			);
		}
	}

endif;
WPSC_ACB_Handle_Greeting::init();
