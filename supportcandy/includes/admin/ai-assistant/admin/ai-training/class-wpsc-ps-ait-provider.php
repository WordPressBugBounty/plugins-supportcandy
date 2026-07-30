<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AIT_Provider' ) ) :

	class WPSC_PS_AIT_Provider {

		public const OPENAI = 'openai';
		public const GOOGLE_GEMINI = 'google-gemini';

		/**
		 * Provider labels
		 *
		 * @var array
		 */
		private static $labels = null;

		/**
		 * Return all labels
		 *
		 * @return array<string, string>
		 */
		public static function get_labels(): array {

			if ( self::$labels === null ) {
				self::$labels = array(
					self::OPENAI        => esc_attr__( 'OpenAI', 'wpsc-ps' ),
					self::GOOGLE_GEMINI => esc_attr__( 'Google Gemini', 'wpsc-ps' ),
				);
			}

			return self::$labels;
		}

		/**
		 * Get label with fallback
		 *
		 * @param string $provider Provider value.
		 * @return string Provider label.
		 */
		public static function get_label( string $provider ): string {

			$labels = self::get_labels();

			if ( isset( $labels[ $provider ] ) ) {
				return $labels[ $provider ];
			}

			return esc_attr(
				str_replace(
					'_',
					' ',
					mb_convert_case( $provider, MB_CASE_TITLE, 'UTF-8' )
				)
			);
		}

		/**
		 * Get all valid provider values
		 *
		 * @return array<string>
		 */
		public static function values(): array {
			return array_keys( self::get_labels() );
		}

		/**
		 * Check if value is valid (wrapper for in_array)
		 *
		 * @param string $provider Provider value.
		 * @return bool True if valid, false otherwise.
		 */
		public static function is_valid( string $provider ): bool {
			return in_array( $provider, self::values(), true );
		}
	}
endif;
