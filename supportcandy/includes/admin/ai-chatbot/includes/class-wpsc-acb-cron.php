<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Cron' ) ) :

	final class WPSC_ACB_Cron {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Register custom cron schedules.
			add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );

			// Chatbot session check cron jobs.
			add_action( 'init', array( __CLASS__, 'schedule_events' ) );

			// Check chatbot sessions and inactive session if necessary.
			add_action( 'wpsc_acb_session_check', array( __CLASS__, 'acb_session_check' ) );

			// Delete chatbot logs cron job.
			add_action( 'wpsc_delete_acb_logs', array( __CLASS__, 'delete_acb_logs' ) );
		}

		/**
		 * Register custom cron schedules.
		 *
		 * @param array $schedules Existing schedules.
		 * @return array
		 */
		public static function register_cron_schedules( $schedules ) {

			if ( ! isset( $schedules['wpsc_every_fifteen_minutes'] ) ) {
				$schedules['wpsc_every_fifteen_minutes'] = array(
					'interval' => 15 * MINUTE_IN_SECONDS,
					'display'  => __( 'Every 15 Minutes', 'wpsc-ps' ),
				);
			}

			return $schedules;
		}

		/**
		 * Check chatbot sessions and inactive session if necessary.
		 *
		 * @return void
		 */
		public static function schedule_events() {
			$session_check_hook       = 'wpsc_acb_session_check';
			$session_check_recurrence = 'wpsc_every_fifteen_minutes';
			$next_session_check       = wp_next_scheduled( $session_check_hook );

			// Ensure this hook uses intended recurrence.
			if ( ! $next_session_check ) {
				wp_schedule_event( time(), $session_check_recurrence, $session_check_hook );
			} elseif ( wp_get_schedule( $session_check_hook ) !== $session_check_recurrence ) {
				wp_clear_scheduled_hook( $session_check_hook );
				wp_schedule_event( time(), $session_check_recurrence, $session_check_hook );
			}

			$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );

			$auto_delete_acb_logs_time = isset( $acb_settings['retention-policy-time'] ) ? intval( $acb_settings['retention-policy-time'] ) : 0;
			if ( $auto_delete_acb_logs_time > 0 && ! wp_next_scheduled( 'wpsc_delete_acb_logs' ) ) {
				wp_schedule_single_event( time(), 'wpsc_delete_acb_logs' );
			}
		}

		/**
		 * Check chatbot sessions and inactive session if necessary.
		 *
		 * @return void
		 */
		public static function acb_session_check() {

			$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );
			if ( empty( $acb_settings['status'] ) || '1' !== $acb_settings['status'] ) {
				return;
			}

			$now = new DateTime( 'now' );
			$inactive_cutoff = $now->modify( '-1 hour' )->format( 'Y-m-d H:i:s' );

			// Get all active sessions.
			$sessions = WPSC_ACB_Sessions::find(
				array(
					'items_per_page' => 50,
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'status',
							'compare' => '=',
							'val'     => WPSC_ACB_Status::ACTIVE,
						),
						array(
							'slug'    => 'last_activity',
							'compare' => '<=',
							'val'     => $inactive_cutoff,
						),
					),
				),
			);
			$active_sessions = $sessions['results'] ?? array();

			// No active sessions found. Cache this result for 1 hour.
			if ( empty( $active_sessions ) ) {
				return;
			}

			// Mark sessions as inactive if last activity was more than 1 hour ago.
			foreach ( $active_sessions as $session ) {

				$data = self::analyze_chat_conversation( $session->id );
				if ( ! empty( $data['subject'] ) ) {
					$session->subject = $data['subject'];
				}
				$status = $data['status'] ?? WPSC_ACB_Status::INACTIVE;

				if ( is_string( $status ) ) {
					$status = WPSC_ACB_Status::get_value_by_key( $status );
				}

				if ( ! is_int( $status ) || ! WPSC_ACB_Status::is_valid( $status ) ) {
					$status = WPSC_ACB_Status::INACTIVE;
				}

				$session->status = $status;
				$session->save();
			}
		}


		/**
		 * Analyze chat conversation and determine subject and status.
		 *
		 * @param int $session_id Session ID.
		 * @return array Analysis result with success, subject, status, and message.
		 */
		public static function analyze_chat_conversation( $session_id ) {

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( empty( $ai_settings['is-active'] ) ) {
				return array(
					'subject' => __( 'AI assistant is inactive.', 'wpsc-ps' ),
					'status'  => WPSC_ACB_Status::INACTIVE,
				);
			}

			$system_prompt = self::system_prompt();
			$conversation_text = WPSC_ACB_Chats::get_conversation_text( 'text', $session_id );
			$provider = WPSC_AIBOT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			return $provider->wpsc_analyze_conversation_subject_status( $ai_settings, $system_prompt, $conversation_text );
		}

		/**
		 * Delete AI ChatBot logs
		 *
		 * @return void
		 */
		public static function delete_acb_logs() {

			$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );
			if ( empty( $acb_settings['status'] ) || '1' !== $acb_settings['status'] ) {
				return;
			}

			$tz = wp_timezone();
			$today = new DateTime( 'now', $tz );

			// Get auto delete time and unit from setting.
			$unit = isset( $acb_settings['retention-policy-unit'] ) ? $acb_settings['retention-policy-unit'] : 'year';
			$time = isset( $acb_settings['retention-policy-time'] ) ? $acb_settings['retention-policy-time'] : 0;
			if ( $time === 0 ) {
				return;
			}

			// Find the date after which tickets should be archived.
			$age = clone $today;
			switch ( $unit ) {
				case 'days':
					$age->sub( new DateInterval( 'P' . $time . 'D' ) );
					break;

				case 'month':
					$age->sub( new DateInterval( 'P' . $time . 'M' ) );
					break;

				case 'year':
					$age->sub( new DateInterval( 'P' . $time . 'Y' ) );
					break;
			}

			$logs = WPSC_ACB_Sessions::find(
				array(
					'items_per_page' => 50,
					'orderby'        => 'last_activity',
					'order'          => 'ASC',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'last_activity',
							'compare' => '<',
							'val'     => $age->format( 'Y-m-d' ),
						),
					),
				)
			);

			if ( $logs['total_items'] > 0 ) {
				foreach ( $logs['results'] as $log ) {
					WPSC_ACB_Sessions::destroy( $log );
				}
			}

			if ( $logs['has_next_page'] ) {
				wp_schedule_single_event( time(), 'wpsc_delete_acb_logs' );
			} else {
				wp_schedule_single_event( time() + DAY_IN_SECONDS, 'wpsc_delete_acb_logs' );
			}
		}

		/**
		 * System prompt for chatbot.
		 *
		 * @return string
		 */
		private static function system_prompt() {

			return '
				You are a support conversation analyzer.
				Analyze the provided conversation and determine:
				1. A concise support ticket subject.
				2. The conversation status.

				Status Rules:
				resolved
				- The assistant provided an answer, solution, or the requested information for the user\'s issue, even if the user never replied again or left the conversation afterward (e.g. closed the chat/tab without further response).
				- The user confirms success, satisfaction, understanding, or thanks.
				- No further action appears required from the assistant.

				abandoned
				- The assistant asked the user a clarifying question or requested more information, and the user left without responding.
				- The conversation ended before the assistant could provide any answer, solution, or requested information.
				- The user\'s issue remains clearly incomplete or unanswered at the point the conversation stopped.
				- If uncertain, classify as resolved when the assistant\'s last message already addressed the user\'s issue; otherwise classify as abandoned.

				Subject Rules:
				- Generate a concise support ticket subject between 3 and 8 words.
				- Focus on the user\'s primary issue or request.
				- Use professional support desk language.
				- Do not use quotation marks.
				- Do not use prefixes such as Subject:, Title:, or Issue:.
				- If no clear subject exists, use General Inquiry.

				Output Rules:
				Return ONLY valid JSON.
				Example:
				{
					"subject": "Unable to Login",
					"status": "resolved"
				}
				Do not include explanations.
				Do not include markdown.
				Do not include any text outside the JSON object.
			';
		}
	}

endif;
WPSC_ACB_Cron::init();
