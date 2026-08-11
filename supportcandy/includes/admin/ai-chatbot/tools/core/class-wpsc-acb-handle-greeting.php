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
				'description' => 'Signal that the current message is pure small-talk (greeting, thank-you, farewell) in any language, so you can compose the actual reply yourself in your next response. Set end_conversation=true only when the user clearly intends to end the chat. Never use this tool for mixed messages that also contain a support issue or question.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'end_conversation' => array(
							'type'        => 'boolean',
							'description' => 'Set true only when user clearly intends to end the chat.',
						),
					),
					'required'             => array( 'end_conversation' ),
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
		 * Returns structured intent data only; the calling LLM turn composes the
		 * actual user-facing reply (in the user's own language) from this result.
		 *
		 * @param array  $args Tool arguments.
		 * @param string $session_uuid Session UUID.
		 * @return array
		 */
		public static function execute_tool_handle_greeting( $args, $session_uuid ) {

			$end_conversation = ! empty( $args['end_conversation'] );

			if ( ! $end_conversation ) {
				return array(
					'success' => true,
					'intent'  => 'greeting',
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

			return array(
				'success'          => true,
				'intent'           => 'greeting',
				'end_conversation' => true,
				'session_expired'  => true,
				'reason'           => 'conversation_ended',
			);
		}
	}

endif;
WPSC_ACB_Handle_Greeting::init();
