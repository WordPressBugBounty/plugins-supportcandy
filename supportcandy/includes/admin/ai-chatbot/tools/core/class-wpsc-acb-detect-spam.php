<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Detect_Spam' ) ) :

	final class WPSC_ACB_Detect_Spam {

		/**
		 * Initialize the tool.
		 */
		public static function init() {

			add_filter( 'wpsc_acb_tool_registry', array( __CLASS__, 'register_tool' ) );
		}

		/**
		 * Register the tool in the registry.
		 *
		 * @param array $registry Current tool registry.
		 * @return array
		 */
		public static function register_tool( $registry ) {

			$registry['detect_spam'] = array(
				'name'        => 'detect_spam',
				'description' => 'Detect whether the current customer message is spam/trolling. Set is_spam=true only when the message is clearly spam, abusive noise, repeated nonsense, phishing/scam bait, or non-support trolling. Set is_spam=false when message is a genuine support request. If is_spam=true, this tool will end the current chat session with a moderation message.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'is_spam' => array(
							'type'        => 'boolean',
							'description' => 'true if the customer message is spam/trolling; false if it is not spam.',
						),
					),
					'required'             => array( 'is_spam' ),
					'additionalProperties' => false,
				),
				'handler'     => 'execute_tool_detect_spam',
				'class'       => __CLASS__,
			);

			return $registry;
		}

		/**
		 * Execute detect spam tool.
		 *
		 * Returns structured intent data only; the calling LLM turn composes the
		 * actual user-facing reply (in the user's own language) from this result.
		 *
		 * @param array  $args Tool arguments.
		 * @param string $session_uuid Session UUID.
		 * @return array
		 */
		public static function execute_tool_detect_spam( $args, $session_uuid ) {

			$is_spam = self::normalize_tool_boolean( $args['is_spam'] ?? null );

			if ( true !== $is_spam ) {
				return array(
					'success' => true,
					'intent'  => 'not_spam',
				);
			}

			$session_uuid = sanitize_text_field( (string) $session_uuid );
			$session = $session_uuid ? WPSC_ACB_Sessions::get_session_by_session_uuid( $session_uuid ) : null;

			if ( $session ) {
				$session->status = WPSC_ACB_Status::CLOSED;
				$session->save();
				WPSC_ACB_Cache::clear_acb_cache( $session->id );
			}

			WPSC_ACB_Cookies::delete_session_cookie( 'wpsc_acb_session_id' );

			return array(
				'success'          => true,
				'intent'           => 'spam',
				'end_conversation' => true,
				'session_expired'  => true,
				'reason'           => 'spam_closed',
			);
		}

		/**
		 * Normalize tool boolean values from model arguments.
		 *
		 * @param mixed $value Raw argument value.
		 * @return bool|null
		 */
		private static function normalize_tool_boolean( $value ) {

			if ( is_bool( $value ) ) {
				return $value;
			}

			if ( is_string( $value ) ) {
				$normalized = strtolower( trim( $value ) );
				if ( in_array( $normalized, array( 'yes', 'y', 'true', '1' ), true ) ) {
					return true;
				}

				if ( in_array( $normalized, array( 'no', 'n', 'false', '0' ), true ) ) {
					return false;
				}
			}

			if ( is_numeric( $value ) ) {
				return ( (int) $value ) === 1;
			}

			return null;
		}
	}

endif;
WPSC_ACB_Detect_Spam::init();
