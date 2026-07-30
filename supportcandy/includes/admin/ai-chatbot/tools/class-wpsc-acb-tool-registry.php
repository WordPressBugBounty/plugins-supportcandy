<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Tool_Registry' ) ) :

	final class WPSC_ACB_Tool_Registry {

		/**
		 * Get registered chatbot function-calling tools.
		 *
		 * @return array
		 */
		public static function get_registry() {

			$registry = array();
			return apply_filters( 'wpsc_acb_tool_registry', $registry );
		}

		/**
		 * Get provider-ready tool definitions.
		 *
		 * @return array
		 */
		public static function get_tool_definitions() {

			$registry = self::get_registry();
			$tools = array();

			foreach ( $registry as $tool ) {
				if ( empty( $tool['name'] ) || empty( $tool['description'] ) || empty( $tool['parameters'] ) ) {
					continue;
				}

				$tools[] = array(
					'name'        => $tool['name'],
					'description' => $tool['description'],
					'parameters'  => $tool['parameters'],
				);
			}

			return $tools;
		}

		/**
		 * Get handler method name for a tool.
		 *
		 * @param string $tool_name Tool name.
		 * @return string
		 */
		public static function get_tool_handler( $tool_name ) {

			$tool_name = sanitize_key( (string) $tool_name );
			$registry = self::get_registry();

			if ( empty( $registry[ $tool_name ]['handler'] ) ) {
				return '';
			}

			return (string) $registry[ $tool_name ]['handler'];
		}

		/**
		 * Get handler class name for a tool.
		 *
		 * @param string $tool_name Tool name.
		 * @return string
		 */
		public static function get_tool_handler_class( $tool_name ) {

			$tool_name = sanitize_key( (string) $tool_name );
			$registry = self::get_registry();

			if ( empty( $registry[ $tool_name ]['class'] ) ) {
				return '';
			}

			return (string) $registry[ $tool_name ]['class'];
		}
	}
endif;
