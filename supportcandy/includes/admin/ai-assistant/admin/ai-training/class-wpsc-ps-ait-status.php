<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AIT_Status' ) ) :

	class WPSC_PS_AIT_Status {

		public const NEW = 'new';
		public const INDEXED = 'indexed';
		public const PROCESSING = 'processing';
		public const DELETE = 'delete';
		public const FAILED = 'failed';

		/**
		 * Status labels
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
					self::NEW        => esc_attr__( 'New', 'wpsc-ps' ),
					self::PROCESSING => esc_attr__( 'Processing', 'wpsc-ps' ),
					self::INDEXED    => esc_attr__( 'Indexed', 'wpsc-ps' ),
					self::DELETE     => esc_attr__( 'Deleted', 'wpsc-ps' ),
					self::FAILED     => esc_attr__( 'Failed', 'wpsc-ps' ),
				);
			}

			return self::$labels;
		}

		/**
		 * Get label with fallback
		 *
		 * @param string $status Status value.
		 * @return string Status label.
		 */
		public static function get_label( string $status ): string {

			$labels = self::get_labels();

			if ( isset( $labels[ $status ] ) ) {
				return $labels[ $status ];
			}

			return esc_attr(
				str_replace(
					'_',
					' ',
					mb_convert_case( $status, MB_CASE_TITLE, 'UTF-8' )
				)
			);
		}

		/**
		 * Get all valid status values
		 *
		 * @return array<string>
		 */
		public static function values(): array {
			return array_keys( self::get_labels() );
		}

		/**
		 * Check if value is valid (wrapper for in_array)
		 *
		 * @param string $status Status value.
		 * @return bool True if valid, false otherwise.
		 */
		public static function is_valid( string $status ): bool {
			return in_array( $status, self::values(), true );
		}
	}
endif;
