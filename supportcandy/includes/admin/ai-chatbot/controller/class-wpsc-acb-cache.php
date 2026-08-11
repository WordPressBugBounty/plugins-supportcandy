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
				'transcript'         => array(),
				'user'               => array(
					'name'  => $identity['name'],
					'email' => $identity['email'],
				),
				// Memoized "known user" system-prompt block for this session -
				// see get_known_user_context()/set_known_user_context() below.
				// null means "not computed yet this session"; '' is a valid
				// cached result (e.g. an anonymous guest with nothing to say).
				'known_user_context' => null,
			);

			// Ensure cache payload always has a consistent shape. array_merge()
			// (rather than a straight overwrite) so a transient written by an
			// older version of this class - before 'known_user_context' existed -
			// still gets that key filled in instead of missing entirely.
			$cache_label = self::get_cache_label( $session_id );
			$cached_data = get_transient( $cache_label );
			$cache_hit = is_array( $cached_data );

			$cached_data = $cache_hit ? array_merge( $default_data, $cached_data ) : $default_data;

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
		 * Get this session's memoized "known user" system-prompt block (see
		 * WPSC_ACB_Chats::get_known_user_context()), so it only has to be built
		 * once per chat session instead of on every single message - relevant
		 * once that block grows beyond a cheap in-memory identity read to
		 * include lookups elsewhere (e.g. WooCommerce order history, LMS
		 * course/progress data) that would otherwise re-run on every turn.
		 *
		 * Lives inside the same per-session transient as the transcript cache,
		 * so it is automatically destroyed together with it - whenever
		 * clear_acb_cache() runs (conversation resolved/skipped/escalation
		 * cancelled) or after an hour of inactivity, whichever comes first -
		 * rather than needing its own separate lifecycle to manage.
		 *
		 * @param int $session_id Session ID.
		 * @return string|null The cached block, or null if nothing has been cached yet this session.
		 */
		public static function get_known_user_context( $session_id ) {

			$cached_data = self::get_acb_cache( $session_id );
			$context = is_string( $cached_data['known_user_context'] ?? null ) ? $cached_data['known_user_context'] : null;

			return $context;
		}

		/**
		 * Store this session's "known user" system-prompt block for reuse by
		 * later messages in the same session. See get_known_user_context().
		 *
		 * @param int    $session_id Session ID.
		 * @param string $context The rendered system-prompt block (may be '' for an anonymous guest).
		 * @return void
		 */
		public static function set_known_user_context( $session_id, $context ) {

			$session_id = (int) $session_id;
			if ( $session_id <= 0 ) {
				return;
			}

			$cache_label = self::get_cache_label( $session_id );
			$cached_data = self::get_acb_cache( $session_id );
			$cached_data['known_user_context'] = is_string( $context ) ? $context : '';
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
		 * Must use the same "known customer" condition as
		 * WPSC_ACB_Chats::get_known_user_context() (is_customer + customer
		 * record on WPSC_Current_User::$current_user) rather than a raw
		 * WP_User/wp_get_current_user() check, so this cached identity can
		 * never disagree with what the model was told about the visitor.
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

			// Note: WPSC_Customer exposes 'id' via a magic __get() with no
			// __isset(), and empty( $current_user->customer->id ) always
			// evaluates true regardless of the actual value in that case -
			// verified directly (empty() on a magic-getter-only property
			// short-circuits before ever calling __get()). Read the value
			// out first via a plain property access and test that instead.
			$customer_id = empty( $current_user ) || empty( $current_user->is_customer ) || empty( $current_user->customer ) ? '' : $current_user->customer->id;
			if ( ! $customer_id ) {
				return $unknown_identity;
			}

			$name = sanitize_text_field( (string) $current_user->customer->name );
			$email = sanitize_email( (string) $current_user->customer->email );

			if ( '' === $name || ! is_email( $email ) ) {
				return $unknown_identity;
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
