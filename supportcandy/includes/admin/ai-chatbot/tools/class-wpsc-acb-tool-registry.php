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

		/**
		 * Get agentic-loop metadata for a tool, so the loop can enforce
		 * per-turn call caps generically instead of hand-coding checks per
		 * tool name. Tools opt in via 'side_effecting' / 'requires_confirmation'
		 * / 'max_calls_per_turn' keys in their registry entry; all default to
		 * an unrestricted, no-confirmation tool.
		 *
		 * @param string $tool_name Tool name.
		 * @return array{requires_confirmation: bool, side_effecting: bool, max_calls_per_turn: int} max_calls_per_turn of 0 means unlimited.
		 */
		public static function get_tool_metadata( $tool_name ) {

			$tool_name = sanitize_key( (string) $tool_name );
			$registry = self::get_registry();
			$tool = $registry[ $tool_name ] ?? array();

			$side_effecting = ! empty( $tool['side_effecting'] );
			$default_cap = $side_effecting ? 1 : 0;

			return array(
				'requires_confirmation' => ! empty( $tool['requires_confirmation'] ),
				'side_effecting'        => $side_effecting,
				'max_calls_per_turn'    => isset( $tool['max_calls_per_turn'] ) ? max( 1, (int) $tool['max_calls_per_turn'] ) : $default_cap,
			);
		}

		/**
		 * Get the names of tools flagged as requiring explicit user confirmation
		 * before being called with an action-confirming argument, so the system
		 * prompt can instruct the model generically without per-tool prose.
		 *
		 * @return array
		 */
		public static function get_confirmation_required_tool_names() {

			$names = array();
			foreach ( self::get_registry() as $tool ) {
				if ( ! empty( $tool['requires_confirmation'] ) && ! empty( $tool['name'] ) ) {
					$names[] = sanitize_key( (string) $tool['name'] );
				}
			}

			return $names;
		}
	}
endif;
