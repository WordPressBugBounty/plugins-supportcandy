<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Cron' ) ) :

	final class WPSC_Cron {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Add custom cron intervals.
			add_filter( 'cron_schedules', array( __CLASS__, 'custom_interval' ) ); //phpcs:ignore

			// Schedule cron jobs.
			add_action( 'init', array( __CLASS__, 'schedule_events' ) );

			// cron event callbacks.
			add_action( 'wpsc_auto_archive_closed_tickets', array( __CLASS__, 'auto_archive_closed_tickets' ) );
			add_action( 'wpsc_permanently_delete_archive_tickets', array( __CLASS__, 'permanently_delete_archive_tickets' ) );
			add_action( 'wpsc_permanently_delete_tickets', array( __CLASS__, 'permanently_delete_tickets' ) );

			// run background processes.
			add_action( 'wp_ajax_wpsc_run_ajax_background_process', array( __CLASS__, 'run_background_process' ) );
			add_action( 'wp_ajax_nopriv_wpsc_run_ajax_background_process', array( __CLASS__, 'run_background_process' ) );
		}

		/**
		 * Custom cron job intervals for SupportCandy
		 *
		 * @param array $schedules - schedule time.
		 * @return array
		 */
		public static function custom_interval( $schedules ) {

			$schedules['wpsc_1min'] = array(
				'interval' => 60,
				'display'  => esc_attr__( 'Every one minute', 'supportcandy' ),
			);

			$schedules['wpsc_5min'] = array(
				'interval' => 300,
				'display'  => esc_attr__( 'Every five minutes', 'supportcandy' ),
			);

			return $schedules;
		}

		/**
		 * Schedule cron job events for SupportCandy
		 *
		 * @return void
		 */
		public static function schedule_events() {

			$advanced = get_option( 'wpsc-ms-advanced-settings' );

			// Schedule cron job for every five minute events.
			if ( ! wp_next_scheduled( 'wpsc_cron_five_minute' ) ) {
				wp_schedule_event(
					time(),
					'wpsc_5min',
					'wpsc_cron_five_minute'
				);
			}

			// Schedule cron job for daily events.
			if ( ! wp_next_scheduled( 'wpsc_cron_daily' ) ) {
				wp_schedule_event(
					self::get_midnight_timestamp(),
					'daily',
					'wpsc_cron_daily'
				);
			}

			// license checker.
			if ( ! wp_next_scheduled( 'wpsc_license_checker' ) ) {
				wp_schedule_event(
					self::get_midnight_timestamp(),
					'daily',
					'wpsc_license_checker'
				);
			}

			// Auto-archive closed tickets.
			$auto_archive_time = isset( $advanced['auto-archive-tickets-time'] ) ? $advanced['auto-archive-tickets-time'] : 0;
			if ( $auto_archive_time > 0 && ! wp_next_scheduled( 'wpsc_auto_archive_closed_tickets' ) ) {
				wp_schedule_single_event( time(), 'wpsc_auto_archive_closed_tickets' );
			}

			// Permanently delete archive tickets.
			$permanent_archive_time = isset( $advanced['permanent-archive-tickets-time'] ) ? $advanced['permanent-archive-tickets-time'] : 0;
			if ( $permanent_archive_time > 0 && ! wp_next_scheduled( 'wpsc_permanently_delete_archive_tickets' ) ) {
				wp_schedule_single_event( time(), 'wpsc_permanently_delete_archive_tickets' );
			}

			// Permanently delete deleted tickets.
			$permanent_delete_time = isset( $advanced['permanent-delete-tickets-time'] ) ? $advanced['permanent-delete-tickets-time'] : 0;
			if ( $permanent_delete_time > 0 && ! wp_next_scheduled( 'wpsc_permanently_delete_tickets' ) ) {
				wp_schedule_single_event( time(), 'wpsc_permanently_delete_tickets' );
			}

			// Attachment garbage collector.
			if ( ! wp_next_scheduled( 'wpsc_attach_garbage_collector' ) ) {
				wp_schedule_event(
					time(),
					'hourly',
					'wpsc_attach_garbage_collector'
				);
			}
		}

		/**
		 * Remove existing scheduled events.
		 * Can be used while deactivation of plugin or resetting schedules after an update etc.
		 *
		 * @return void
		 */
		public static function unschedule_events() {

			// Remove every five minute cron.
			$timestamp = wp_next_scheduled( 'wpsc_cron_five_minute' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wpsc_cron_five_minute' );
			}

			// Remove daily cron.
			$timestamp = wp_next_scheduled( 'wpsc_cron_daily' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wpsc_cron_daily' );
			}
		}

		/**
		 * Provide mid-night unix timestamp
		 *
		 * @return String
		 */
		public static function get_midnight_timestamp() {

			$tz   = wp_timezone();
			$date = new DateTime( 'now', $tz );
			$date->setTime( 0, 0, 0 );
			$date->add( new DateInterval( 'P1D' ) );
			return $date->getTimestamp();
		}

		/**
		 * Auto archive closed ticket after x days/months/years
		 *
		 * @return void
		 */
		public static function auto_archive_closed_tickets() {

			$tz = wp_timezone();
			$today = new DateTime( 'now', $tz );
			$ms_settings = get_option( 'wpsc-ms-advanced-settings' );
			$ad_settings = get_option( 'wpsc-tl-ms-advanced' );
			$time = isset( $ms_settings['auto-archive-tickets-time'] ) ? $ms_settings['auto-archive-tickets-time'] : 0;
			$unit = isset( $ms_settings['auto-archive-tickets-unit'] ) ? $ms_settings['auto-archive-tickets-unit'] : 'days';
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

			// Get tickets to be archive.
			$tickets = WPSC_Ticket::find(
				array(
					'items_per_page' => 20,
					'orderby'        => 'date_closed',
					'order'          => 'ASC',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'status',
							'compare' => 'IN',
							'val'     => $ad_settings['closed-ticket-statuses'],
						),
						array(
							'slug'    => 'date_closed',
							'compare' => '<',
							'val'     => $age->format( 'Y-m-d' ),
						),
					),
				)
			);

			// Archive tickets.
			if ( $tickets['total_items'] > 0 ) {
				foreach ( $tickets['results'] as $ticket ) {
					WPSC_Individual_Ticket::$ticket = $ticket;
					WPSC_Individual_Ticket::archive_ticket();
				}
			}

			// Schedule next run.
			if ( $tickets['has_next_page'] ) {
				wp_schedule_single_event( time(), 'wpsc_auto_archive_closed_tickets' );
			} else {
				wp_schedule_single_event( time() + DAY_IN_SECONDS, 'wpsc_auto_archive_closed_tickets' );
			}
		}

		/**
		 * Permenently delete tickets after x days/months/years
		 *
		 * @return void
		 */
		public static function permanently_delete_archive_tickets() {

			$tz = wp_timezone();
			$today = new DateTime( 'now', $tz );
			$ms_settings = get_option( 'wpsc-ms-advanced-settings' );
			$time = isset( $ms_settings['permanent-archive-tickets-time'] ) ? $ms_settings['permanent-archive-tickets-time'] : 0;
			$unit = isset( $ms_settings['permanent-archive-tickets-unit'] ) ? $ms_settings['permanent-archive-tickets-unit'] : 'days';
			if ( $time === 0 ) {
				return;
			}

			// find the date before which tickets to be deleted.
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

			// get tickets to be deleted.
			$tickets = WPSC_Archive_Ticket::find(
				array(
					'items_per_page' => 25,
					'orderby'        => 'date_updated',
					'order'          => 'ASC',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'date_updated',
							'compare' => '<',
							'val'     => $age->format( 'Y-m-d' ),
						),
					),
				)
			);

			// Delete tickets.
			if ( $tickets['total_items'] > 0 ) {
				foreach ( $tickets['results'] as $ticket ) {
					WPSC_Individual_Archive_Ticket::delete_archive_ticket( $ticket );
				}
			}

			// schedule next run.
			if ( $tickets['has_next_page'] ) {
				wp_schedule_single_event( time(), 'wpsc_permanently_delete_archive_tickets' );
			} else {
				wp_schedule_single_event( time() + DAY_IN_SECONDS, 'wpsc_permanently_delete_archive_tickets' );
			}
		}

		/**
		 * Permenently delete tickets after x days/months/years
		 *
		 * @return void
		 */
		public static function permanently_delete_tickets() {

			$tz = wp_timezone();
			$today = new DateTime( 'now', $tz );
			$ms_settings = get_option( 'wpsc-ms-advanced-settings' );
			$time = isset( $ms_settings['permanent-delete-tickets-time'] ) ? $ms_settings['permanent-delete-tickets-time'] : 0;
			$unit = isset( $ms_settings['permanent-delete-tickets-unit'] ) ? $ms_settings['permanent-delete-tickets-unit'] : 'days';
			if ( $time === 0 ) {
				return;
			}

			// find the date before which tickets to be deleted.
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

			// get tickets to be deleted.
			$tickets = WPSC_Ticket::find(
				array(
					'items_per_page' => 2,
					'orderby'        => 'date_closed',
					'order'          => 'ASC',
					'is_active'      => 0,
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'date_updated',
							'compare' => '<',
							'val'     => $age->format( 'Y-m-d' ),
						),
					),
				)
			);

			// Delete tickets.
			if ( $tickets['total_items'] > 0 ) {
				foreach ( $tickets['results'] as $ticket ) {
					WPSC_Individual_Ticket::$ticket = $ticket;
					WPSC_Individual_Ticket::delete_permanently();
				}
			}

			// schedule next run.
			if ( $tickets['has_next_page'] ) {
				wp_schedule_single_event( time(), 'wpsc_permanently_delete_tickets' );
			} else {
				wp_schedule_single_event( time() + DAY_IN_SECONDS, 'wpsc_permanently_delete_tickets' );
			}
		}

		/**
		 * Execute background processes
		 *
		 * @return void
		 */
		public static function run_background_process() {

			do_action( 'wpsc_run_ajax_background_process' );
			wp_die();
		}
	}
endif;

WPSC_Cron::init();
