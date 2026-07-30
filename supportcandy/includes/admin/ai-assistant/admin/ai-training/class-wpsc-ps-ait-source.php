<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AIT_Source' ) ) :

	class WPSC_PS_AIT_Source {

		public const TICKET     = 'ticket';
		public const FILE       = 'file';
		public const URL        = 'url';

		/**
		 * Source labels
		 *
		 * @var array|null
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
					self::TICKET => esc_attr__( 'Ticket', 'wpsc-ps' ),
					self::FILE   => esc_attr__( 'File', 'wpsc-ps' ),
					self::URL    => esc_attr__( 'URL', 'wpsc-ps' ),
				);

				self::$labels = apply_filters( 'wpsc_ps_ait_source_labels', self::$labels );
			}

			return self::$labels;
		}

		/**
		 * Get label with fallback
		 *
		 * @param string $source source value.
		 * @return string
		 */
		public static function get_label( string $source ): string {

			$labels = self::get_labels();

			if ( isset( $labels[ $source ] ) ) {
				return $labels[ $source ];
			}

			return esc_attr(
				str_replace(
					'_',
					' ',
					mb_convert_case( $source, MB_CASE_TITLE, 'UTF-8' )
				)
			);
		}

		/**
		 * Get all valid source values
		 *
		 * @return array<string>
		 */
		public static function values(): array {
			return array_keys( self::get_labels() );
		}

		/**
		 * Check if value is valid
		 *
		 * @param string $source source value.
		 * @return bool
		 */
		public static function is_valid( string $source ): bool {
			return in_array( $source, self::values(), true );
		}

		/**
		 * Look up a training source (from wpsc-ps-ai-training-sources) by its slug.
		 *
		 * @param string $slug Training source slug.
		 * @return array Source data, or an empty array if not found.
		 */
		public static function get_training_source( string $slug ): array {

			if ( '' === $slug ) {
				return array();
			}

			$sources = get_option( 'wpsc-ps-ai-training-sources', array() );
			if ( ! is_array( $sources ) ) {
				return array();
			}

			foreach ( $sources as $source ) {
				if ( is_array( $source ) && isset( $source['slug'] ) && $source['slug'] === $slug ) {
					return $source;
				}
			}

			return array();
		}
	}
endif;
