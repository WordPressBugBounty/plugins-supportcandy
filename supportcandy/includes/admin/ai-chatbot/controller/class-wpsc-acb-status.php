<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Status' ) ) :

	class WPSC_ACB_Status {

		// Do not change the values of these constants as they are stored in the database.
		public const ACTIVE    = 1;
		public const INACTIVE  = 2;
		public const ABANDONED = 3;
		public const HANDOFF   = 4;
		public const RESOLVED  = 5;
		public const CLOSED    = 6;

		/**
		 * Status labels
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
					self::ACTIVE    => esc_attr__( 'Active', 'wpsc-ps' ),
					self::INACTIVE  => esc_attr__( 'Inactive', 'wpsc-ps' ),
					self::ABANDONED => esc_attr__( 'Abandoned', 'wpsc-ps' ),
					self::HANDOFF   => esc_attr__( 'Handoff', 'wpsc-ps' ),
					self::RESOLVED  => esc_attr__( 'Resolved', 'wpsc-ps' ),
					self::CLOSED    => esc_attr__( 'Closed', 'wpsc-ps' ),
				);
			}

			return self::$labels;
		}

		/**
		 * Get label by status.
		 *
		 * @param int $status Status value.
		 * @return string
		 */
		public static function get_label( int $status ): string {

			$labels = self::get_labels();

			return $labels[ $status ] ?? esc_attr__( 'Unknown', 'wpsc-ps' );
		}

		/**
		 * Get all valid status values.
		 *
		 * @return array<int>
		 */
		public static function values(): array {
			return array_keys( self::get_labels() );
		}

		/**
		 * Get status value by key.
		 *
		 * @param string $key Status key.
		 * @return int|null
		 */
		public static function get_value_by_key( string $key ): ?int {

			$map = array(
				'active'    => self::ACTIVE,
				'inactive'  => self::INACTIVE,
				'abandoned' => self::ABANDONED,
				'handoff'   => self::HANDOFF,
				'resolved'  => self::RESOLVED,
				'closed'    => self::CLOSED,
			);

			$key = sanitize_key( $key );

			return $map[ $key ] ?? null;
		}

		/**
		 * Check if status is valid.
		 *
		 * @param int $status Status value.
		 * @return bool
		 */
		public static function is_valid( int $status ): bool {
			return in_array( $status, self::values(), true );
		}

		/**
		 * Get formatted HTML badge for status.
		 *
		 * @param int $status Status.
		 * @return string
		 */
		public static function get_badge( int $status ): string {

			$classes = array(
				self::ACTIVE    => 'active',
				self::INACTIVE  => 'inactive',
				self::ABANDONED => 'abandoned',
				self::HANDOFF   => 'handoff',
				self::RESOLVED  => 'resolved',
				self::CLOSED    => 'closed',
			);

			$class = isset( $classes[ $status ] ) ? $classes[ $status ] : 'inactive';

			return sprintf(
				'<span class="wpsc-acb-status wpsc-acb-status-%1$s">%2$s</span>',
				esc_attr( $class ),
				esc_html( self::get_label( $status ) )
			);
		}
	}

endif;
