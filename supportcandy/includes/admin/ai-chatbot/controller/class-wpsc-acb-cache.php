<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Cache' ) ) :

	final class WPSC_ACB_Cache {

		/**
		 * Initialize the cache.
		 */
		public static function init() {}

		/**
		 * Get the cache key for a given session ID and message.
		 *
		 * @param string $session_id The session ID.
		 * @return string The cache key.
		 */
		public static function get_cache_label( $session_id ) {
			return "wpsc_acb_cache_{$session_id}";
		}

		/**
		 * Retrieve a cached response for a given session ID and message.
		 *
		 * @param string $session_id The session ID.
		 * @return mixed The cached response or false if not found.
		 */
		public static function get_acb_cache( $session_id ) {

			$identity = self::get_logged_in_identity();
			$default_data = array(
				'transcript' => array(),
				'user'       => array(
					'name'  => $identity['name'],
					'email' => $identity['email'],
				),
			);

			// Ensure cache payload always has a consistent shape.
			$cache_label = self::get_cache_label( $session_id );
			$cached_data = get_transient( $cache_label );
			if ( ! is_array( $cached_data ) ) {
				$cached_data = $default_data;
			}

			// Refresh identity in case a guest has since logged in during the conversation.
			$cached_data['user'] = $default_data['user'];

			set_transient( $cache_label, $cached_data, HOUR_IN_SECONDS );
			return $cached_data;
		}

		/**
		 * Set a cached response for a given session ID and message.
		 *
		 * @param string $session_id The session ID.
		 * @param string $sender The role of the message (user or assistant).
		 * @param string $message The message to cache.
		 */
		public static function set_acb_chat_messages( $session_id, $sender, $message ) {

			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}

			$sender = is_string( $sender ) ? sanitize_key( $sender ) : '';
			if ( ! in_array( $sender, array( 'user', 'assistant' ), true ) ) {
				return;
			}

			$message = is_string( $message ) ? $message : '';
			if ( '' === $message ) {
				return;
			}

			$cache_label = self::get_cache_label( $session_id );
			$cached_data = self::get_acb_cache( $session_id );

			$cached_data['transcript'][] = array(
				'role'         => $sender,
				'content'      => $message,
				'date_created' => ( new DateTime() )->format( 'Y-m-d H:i:s' ),
			);
			set_transient( $cache_label, $cached_data, HOUR_IN_SECONDS );
		}

		/**
		 * Clear the cached response for a given session ID.
		 *
		 * @param string $session_id The session ID.
		 */
		public static function clear_acb_cache( $session_id ) {
			$cache_label = self::get_cache_label( $session_id );
			delete_transient( $cache_label );
		}

		/**
		 * Resolve logged-in identity for chatbot ticket creation.
		 *
		 * @return array
		 */
		private static function get_logged_in_identity() {

			$unknown_identity = array(
				'is_logged_in' => false,
				'name'         => 'unknown',
				'email'        => 'unknown@unknown.com',
			);

			$current_user = WPSC_Current_User::$current_user;
			$user = isset( $current_user->user ) ? $current_user->user : null;

			if ( ! $user || empty( $user->ID ) ) {
				$user = wp_get_current_user();
			}

			if ( ! $user || empty( $user->ID ) ) {
				return $unknown_identity;
			}

			$name = trim( (string) $user->display_name );
			if ( '' === $name ) {
				$name = trim( (string) $user->user_login );
			}

			$name = sanitize_text_field( $name );
			$email = sanitize_email( (string) $user->user_email );

			if ( '' === $name ) {
				$name = $unknown_identity['name'];
			}

			if ( ! is_email( $email ) ) {
				$email = $unknown_identity['email'];
			}

			return array(
				'is_logged_in' => true,
				'name'         => $name,
				'email'        => $email,
			);
		}
	}
endif;
WPSC_ACB_Cache::init();
