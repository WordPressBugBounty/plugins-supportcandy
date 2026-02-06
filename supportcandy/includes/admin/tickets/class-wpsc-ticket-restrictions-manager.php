<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Ticket_Restrictions_Manager' ) ) :

	final class WPSC_Ticket_Restrictions_Manager {

		/**
		 * Initialize the class
		 *
		 * @return void
		 */
		public static function init() {

			add_filter( 'wpsc_individual_ticket_actions', array( __CLASS__, 'individual_ticket_actions' ), 10, 2 );
			add_filter( 'wpsc_it_submit_actions', array( __CLASS__, 'it_submit_actions' ), 99, 2 );
			add_filter( 'wpsc_it_thread_actions', array( __CLASS__, 'it_thread_actions' ), 10, 2 );
		}

		/**
		 * Check if a ticket action is restricted.
		 *
		 * @param WPSC_Ticket $ticket Ticket object.
		 * @param string      $action Name of action like reply, delete, note, etc.
		 * @return bool
		 */
		public static function is_restricted( $ticket, $action = '' ) {

			// If ticket is active, restriction ALWAYS true.
			if ( ! $ticket->is_active ) {
				return true;
			}

			// If active, let filter decide.
			$restricted = apply_filters( 'wpsc_restricted_ticket_action', false, $ticket, $action );

			return $restricted;
		}

		/**
		 * Filter ticket actions based on ticket status.
		 *
		 * @param array       $actions - action array.
		 * @param WPSC_Ticket $ticket - ticket object.
		 * @return array
		 */
		public static function individual_ticket_actions( $actions, $ticket ) {

			if ( ! $ticket->is_active ) {
				$restricted = array( 'refresh', 'close', 'duplicate', 'copy', 'archive', 'delete' );
			} else {
				$restricted = array( 'restore', 'delete-permanently' );
			}

			$allows_actions = array_diff_key( $actions, array_flip( $restricted ) );
			$allows_actions = apply_filters( 'wpsc_allowed_it_actions', $allows_actions, $actions, $ticket );
			return $allows_actions;
		}

		/**
		 * Filter submit actions based on ticket status.
		 *
		 * @param array       $actions - action array.
		 * @param WPSC_Ticket $ticket - ticket object.
		 * @return array
		 */
		public static function it_submit_actions( $actions, $ticket ) {

			if ( ! $ticket->is_active ) {
				$restricted = array( 'reply', 'private-note', 'reply-and-close' );
			} else {
				$restricted = array();
			}

			$allows_actions = array_diff_key( $actions, array_flip( $restricted ) );
			$allows_actions = apply_filters( 'wpsc_allowed_it_aubmit_actions', $allows_actions, $actions, $ticket );
			return $allows_actions;
		}

		/**
		 * Filter submit actions based on ticket status.
		 *
		 * @param array       $actions - action array.
		 * @param WPSC_Ticket $ticket - ticket object.
		 * @return array
		 */
		public static function it_thread_actions( $actions, $ticket ) {

			if ( ! $ticket->is_active ) {
				$restricted = array( 'edit', 'delete' );
			} else {
				$restricted = array();
			}

			$allows_actions = array_diff_key( $actions, array_flip( $restricted ) );
			$allows_actions = apply_filters( 'wpsc_allowed_it_thread_actions', $allows_actions, $actions, $ticket );
			return $allows_actions;
		}
	}

endif;
WPSC_Ticket_Restrictions_Manager::init();
