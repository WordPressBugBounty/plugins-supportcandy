<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Cookies' ) ) :

	final class WPSC_ACB_Cookies {

		/**
		 * Initialize the cookies.
		 */
		public static function init() {}

		/**
		 * Resolve current request session identifier.
		 *
		 * @return string
		 */
		public static function get_request_session_id() {

			$cookie_name = 'wpsc_acb_session_id';
			$cookie_val = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

			if ( ! empty( $cookie_val ) ) {
				return $cookie_val;
			}

			$session_id = wp_generate_uuid4();
			self::set_session_cookie( $cookie_name, $session_id );
			return $session_id;
		}

		/**
		 * Set guest session cookie.
		 *
		 * @param string $cookie_name Cookie name.
		 * @param string $cookie_val Cookie value.
		 * @return bool
		 */
		public static function set_session_cookie( $cookie_name, $cookie_val ) {

			if ( headers_sent() ) {
				return false;
			}

			$expires    = time() + HOUR_IN_SECONDS;
			$cookie_val = (string) $cookie_val;

			$paths = self::get_cookie_paths();
			foreach ( $paths as $path ) {

				setcookie(
					$cookie_name,
					$cookie_val,
					self::get_cookie_options( $expires, $path )
				);
			}
			$_COOKIE[ $cookie_name ] = $cookie_val;
			return true;
		}

		/**
		 * Delete guest session cookie.
		 *
		 * @param string $cookie_name Cookie name.
		 * @return bool
		 */
		public static function delete_session_cookie( $cookie_name ) {

			if ( headers_sent() ) {
				unset( $_COOKIE[ $cookie_name ] );
				return false;
			}

			$paths = self::get_cookie_paths();
			foreach ( $paths as $path ) {

				$options = self::get_cookie_options( time() - DAY_IN_SECONDS, $path );
				setcookie( $cookie_name, '', $options );
			}
			unset( $_COOKIE[ $cookie_name ] );
			return true;
		}

		/**
		 * Logged-in users use their user ID; guests get a stable UUID cookie.
		 *
		 * @return string
		 */
		public static function get_request_visitor_id() {

			$current_user = WPSC_Current_User::$current_user;
			if ( ! empty( $current_user->user->ID ) ) {
				return (string) absint( $current_user->user->ID );
			}

			$cookie_name = 'wpsc_acb_visitor_id';
			$cookie_val = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

			if ( ! empty( $cookie_val ) ) {
				return $cookie_val;
			}

			$visitor_id = wp_generate_uuid4();
			self::set_visitor_cookie( $cookie_name, $visitor_id );
			return $visitor_id;
		}

		/**
		 * Set guest visitor cookie.
		 *
		 * @param string $cookie_name Cookie name.
		 * @param string $cookie_val Cookie value.
		 * @return bool
		 */
		public static function set_visitor_cookie( $cookie_name, $cookie_val ) {

			if ( headers_sent() ) {
				return false;
			}

			$expires    = time() + DAY_IN_SECONDS;
			$cookie_val = (string) $cookie_val;
			$paths      = self::get_cookie_paths();

			foreach ( $paths as $path ) {

				setcookie(
					$cookie_name,
					$cookie_val,
					self::get_cookie_options( $expires, $path )
				);
			}

			$_COOKIE[ $cookie_name ] = $cookie_val;
			return true;
		}

		/**
		 * Get cookie options for setting cookies.
		 *
		 * @param int    $expires Expiration timestamp.
		 * @param string $path Cookie path.
		 * @return array
		 */
		private static function get_cookie_options( $expires, $path ) {

			return array(
				'expires'  => $expires,
				'path'     => $path,
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			);
		}

		/**
		 * Get cookie paths used by WordPress for front-end cookies.
		 *
		 * @return array
		 */
		private static function get_cookie_paths() {

			return array_unique(
				array_filter(
					array(
						COOKIEPATH ? COOKIEPATH : '/',
						SITECOOKIEPATH ? SITECOOKIEPATH : '/',
					)
				)
			);
		}
	}
endif;
WPSC_ACB_Cookies::init();
