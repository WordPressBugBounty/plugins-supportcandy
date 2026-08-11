<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AIT_Cron' ) ) :

	final class WPSC_PS_AIT_Cron {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Register custom cron schedules.
			add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) ); //phpcs:ignore

			// schedule cron jobs.
			add_action( 'wpsc_ai_training_upload', array( __CLASS__, 'ai_training_upload' ) );
			add_action( 'wpsc_delete_ai_training_record', array( __CLASS__, 'delete_ai_training_record' ) );

			// Periodic check to requeue/fail training files stuck in PROCESSING.
			add_action( 'init', array( __CLASS__, 'schedule_stale_processing_check' ) );
			add_action( 'wpsc_ai_training_stale_check', array( __CLASS__, 'stale_processing_check' ) );
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
		 * Ensure the stale-processing check runs on a recurring schedule, independent of
		 * whether new files are currently being queued (which is what normally triggers
		 * the single-shot upload cron).
		 *
		 * @return void
		 */
		public static function schedule_stale_processing_check() {

			$hook = 'wpsc_ai_training_stale_check';
			$recurrence = 'wpsc_every_fifteen_minutes';

			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $recurrence, $hook );
			} elseif ( wp_get_schedule( $hook ) !== $recurrence ) {
				wp_clear_scheduled_hook( $hook );
				wp_schedule_event( time(), $recurrence, $hook );
			}
		}

		/**
		 * Requeue or fail training files stuck in PROCESSING status.
		 *
		 * @return void
		 */
		public static function stale_processing_check() {

			// Resume or give up on any post-type sync jobs stuck mid-chain (a lost cron
			// tick, a crashed request) regardless of whether an admin has the training
			// source's settings screen open - see recover_all_stalled_syncs(). This does
			// not depend on the AI assistant being active, since a sync can be left running
			// from before it was disabled.
			WPSC_PS_AI_Setting_AI_Training_Actions::recover_all_stalled_syncs();

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( empty( $ai_settings['is-active'] ) ) {
				return;
			}

			WPSC_PS_AIT_Controller::reset_stale_processing_files();
		}

		/**
		 * Execute AI training upload cron
		 */
		public static function ai_training_upload() {

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( empty( $ai_settings['is-active'] ) ) {
				return;
			}

			WPSC_PS_AIT_Controller::upload_file_to_training( $ai_settings );
		}

		/**
		 * Execute delete AI training record cron
		 */
		public static function delete_ai_training_record() {

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( empty( $ai_settings['is-active'] ) ) {
				return;
			}

			WPSC_PS_AIT_Controller::delete_ai_training_record( $ai_settings );
		}
	}
endif;
WPSC_PS_AIT_Cron::init();
