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
		 * Store add-ons additionally flag 'mutates_cart' (the tool changes a
		 * cart - its items or its applied coupons - so a success counts as a
		 * real cart change this turn), 'applies_coupons' (the tool can
		 * genuinely apply/remove coupons, so a success means a "coupon
		 * applied" reply is not fabricated) and 'unsupported_action_reply' (the
		 * customer-facing reply to use when this tool is called to report an
		 * action that is not supported).
		 *
		 * @return array{requires_confirmation: bool, side_effecting: bool, max_calls_per_turn: int, mutates_cart: bool, applies_coupons: bool, unsupported_action_reply: string} max_calls_per_turn of 0 means unlimited.
		 */
		public static function get_tool_metadata( $tool_name ) {

			$tool_name = sanitize_key( (string) $tool_name );
			$registry = self::get_registry();
			$tool = $registry[ $tool_name ] ?? array();

			$side_effecting = ! empty( $tool['side_effecting'] );
			$default_cap = $side_effecting ? 1 : 0;

			return array(
				'requires_confirmation'    => ! empty( $tool['requires_confirmation'] ),
				'side_effecting'           => $side_effecting,
				'max_calls_per_turn'       => isset( $tool['max_calls_per_turn'] ) ? max( 1, (int) $tool['max_calls_per_turn'] ) : $default_cap,
				'mutates_cart'             => ! empty( $tool['mutates_cart'] ),
				'applies_coupons'          => ! empty( $tool['applies_coupons'] ),
				'unsupported_action_reply' => is_string( $tool['unsupported_action_reply'] ?? null ) ? $tool['unsupported_action_reply'] : '',
				'order_source'             => is_string( $tool['order_source'] ?? null ) ? $tool['order_source'] : '',
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

		/**
		 * Get the distinct order-system labels ('order_source' registry key)
		 * across all registered order-lookup tools (e.g. 'WooCommerce'), so
		 * the system prompt can tell whether more than one order system is
		 * available and, if so, instruct the model to ask the customer which
		 * one they mean rather than guessing. A tool opts in by setting its
		 * own 'order_source' key in its registry entry; tools that are not
		 * order-lookup tools simply omit it and are ignored here.
		 *
		 * @return array
		 */
		public static function get_order_tool_sources() {

			return self::get_tool_sources( 'order_source' );
		}

		/**
		 * The order-lookup tools of every order system other than the given
		 * one, as tool name => order_source label.
		 *
		 * @param string $order_source Order system to exclude.
		 * @return array<string, string>
		 */
		public static function get_other_order_tools( $order_source ) {

			$tools = array();
			foreach ( self::get_registry() as $name => $tool ) {
				if ( ! empty( $tool['order_source'] ) && $tool['order_source'] !== $order_source ) {
					$tools[ (string) $name ] = (string) $tool['order_source'];
				}
			}

			return $tools;
		}

		/**
		 * The order systems ('order_source') in which the current logged-in
		 * customer has at least one order, for tools that can tell (registry
		 * 'customer_has_orders' => callable returning bool). Null when any
		 * order tool cannot tell, so the result is only trusted when complete.
		 *
		 * @return array|null
		 */
		public static function get_order_sources_with_customer_orders() {

			$with_orders = array();
			foreach ( self::get_registry() as $tool ) {
				if ( empty( $tool['order_source'] ) ) {
					continue;
				}
				if ( empty( $tool['customer_has_orders'] ) || ! is_callable( $tool['customer_has_orders'] ) ) {
					return null;
				}
				if ( call_user_func( $tool['customer_has_orders'] ) ) {
					$with_orders[ (string) $tool['order_source'] ] = true;
				}
			}

			return array_keys( $with_orders );
		}

		/**
		 * Get the distinct system labels registered tools declare under a
		 * given registry key - 'order_source' (order lookups), 'cart_source'
		 * (cart tools) or 'catalog_source' (product search) - e.g.
		 * array( 'WooCommerce', 'Easy Digital Downloads' ). More than one label
		 * means the site runs more than one store/order system, so the model
		 * must find out which one the customer means instead of assuming.
		 *
		 * @param string $key Registry key.
		 * @return array
		 */
		public static function get_tool_sources( $key ) {

			$sources = array();
			foreach ( self::get_registry() as $tool ) {
				if ( ! empty( $tool[ $key ] ) && is_string( $tool[ $key ] ) ) {
					$sources[ $tool[ $key ] ] = true;
				}
			}

			return array_keys( $sources );
		}
	}
endif;
