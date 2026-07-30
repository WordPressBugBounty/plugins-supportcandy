<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Reaction' ) ) :

	class WPSC_ACB_Reaction {

		// Do not change the values of these constants as they are stored in the database.
		public const HAPPY    = 1;
		public const UNHAPPY  = 2;

		/**
		 * Reaction labels
		 *
		 * @var array<int, string>
		 */
		private static $labels = null;

		/**
		 * Return all labels.
		 *
		 * @return array<int, string>
		 */
		public static function get_labels(): array {

			if ( self::$labels === null ) {
				self::$labels = array(
					self::HAPPY   => esc_attr__( 'Happy', 'wpsc-ps' ),
					self::UNHAPPY => esc_attr__( 'Unhappy', 'wpsc-ps' ),
				);
			}

			return self::$labels;
		}

		/**
		 * Get label by reaction.
		 *
		 * @param int $reaction Reaction value.
		 * @return string
		 */
		public static function get_label( int $reaction ): string {

			$labels = self::get_labels();

			return $labels[ $reaction ] ?? esc_attr__( 'Unknown', 'wpsc-ps' );
		}

		/**
		 * Get all valid reaction values.
		 *
		 * @return array<int>
		 */
		public static function values(): array {
			return array_keys( self::get_labels() );
		}

		/**
		 * Check if reaction is valid.
		 *
		 * @param int $reaction Reaction value.
		 * @return bool
		 */
		public static function is_valid( int $reaction ): bool {
			return in_array( $reaction, self::values(), true );
		}

		/**
		 * Get formatted HTML badge for reaction.
		 *
		 * @param int $reaction Reaction value.
		 * @return string
		 */
		public static function get_badge( int $reaction ): string {

			if ( ! self::is_valid( $reaction ) ) {
				return '';
			}
			$classes = array(
				self::HAPPY   => 'happy',
				self::UNHAPPY => 'unhappy',
			);

			$class = isset( $classes[ $reaction ] ) ? $classes[ $reaction ] : 'unhappy';

			return sprintf(
				'<span class="wpsc-acb-reaction wpsc-acb-reaction-%1$s">%2$s</span>',
				esc_attr( $class ),
				esc_html( self::get_label( $reaction ) )
			);
		}
	}

endif;
