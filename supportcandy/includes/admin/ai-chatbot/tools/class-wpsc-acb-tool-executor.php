<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Tool_Executor' ) ) :

	final class WPSC_ACB_Tool_Executor {

		/**
		 * Execute a tool call.
		 *
		 * @param array  $tool_call Tool call payload.
		 * @param string $session_uuid Session ID or internal session ID.
		 * @return array
		 */
		public static function execute_tool_call( $tool_call, $session_uuid ) {

			$tool_name = sanitize_key( (string) ( $tool_call['name'] ?? '' ) );
			$args = is_array( $tool_call['arguments'] ?? null ) ? $tool_call['arguments'] : array();
			$handler = WPSC_ACB_Tool_Registry::get_tool_handler( $tool_name );
			$class = WPSC_ACB_Tool_Registry::get_tool_handler_class( $tool_name );

			if ( '' === $handler || ! is_callable( array( $class, $handler ) ) ) {
				return array(
					'success' => false,
					'error'   => 'unknown_tool',
				);
			}

			$result = call_user_func( array( $class, $handler ), $args, $session_uuid );

			return $result;
		}
	}
endif;
