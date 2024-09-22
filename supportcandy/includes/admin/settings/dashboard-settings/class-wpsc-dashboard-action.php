<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Dashboard_Action' ) ) :

	final class WPSC_Dashboard_Action {


		/**
		 * Initialize this class
		 */
		public static function init() {

			// Set filter for the card.
			add_action( 'wp_ajax_wpsc_dash_card_count_filter', array( __CLASS__, 'set_filter' ) );
			add_action( 'wp_ajax_nopriv_wpsc_dash_card_count_filter', array( __CLASS__, 'set_filter' ) );
		}

		/**
		 * Set filter for the card.
		 *
		 * @return void
		 */
		public static function set_filter() {

			if ( check_ajax_referer( 'general', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$card = isset( $_POST['card'] ) ? sanitize_text_field( wp_unslash( $_POST['card'] ) ) : '';
			if ( ! $card ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$view = isset( $_POST['view'] ) ? sanitize_text_field( wp_unslash( $_POST['view'] ) ) : '0';

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_agent ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$page_settings = get_option( 'wpsc-gs-page-settings' );
			$ticket_list = get_option( 'wpsc-tl-ms-customer-view' );
			$more_settings = $current_user->is_agent ? get_option( 'wpsc-tl-ms-agent-view' ) : get_option( 'wpsc-tl-ms-customer-view' );

			// closed statuses.
			$ms_advance_settings = get_option( 'wpsc-tl-ms-advanced' );
			$closed_statuses     = $ms_advance_settings['closed-ticket-statuses'];

			$custom_filters = array();

			if ( $card == 'new-tickets' ) {

				$cf  = WPSC_Custom_Field::get_cf_by_slug( 'status' );
				$obj = new stdClass();
				$obj->slug = 'status';
				$obj->operator = '=';
				$obj->operand_val_1 = $cf->default_value[0];

				$custom_filters[] = array( $obj );

			} elseif ( $card == 'unassigned-tickets' ) {
				$obj = new stdClass();
				$obj->slug = 'assigned_agent';
				$obj->operator = '=';
				$obj->operand_val_1 = '';

				$custom_filters[] = array( $obj );

			} elseif ( $card == 'unresolved-tickets' ) {
				$obj = new stdClass();
				$obj->slug = 'status';
				$obj->operator = 'IN';
				$obj->operand_val_1 = $ticket_list['unresolved-ticket-statuses'];

				$custom_filters[] = array( $obj );

			} elseif ( $card == 'mine-tickets' ) {
				$obj = new stdClass();
				$obj->slug = 'assigned_agent';
				$obj->operator = '=';
				$obj->operand_val_1 = $current_user->agent->id;

				$custom_filters[] = array( $obj );

				$obj = new stdClass();
				$obj->slug = 'status';
				$obj->operator = 'IN';
				$obj->operand_val_1 = $more_settings['unresolved-ticket-statuses'];

				$custom_filters[] = array( $obj );

			} elseif ( $card == 'closed-tickets' ) {
				$obj = new stdClass();
				$obj->slug = 'status';
				$obj->operator = 'IN';
				$obj->operand_val_1 = $closed_statuses;

				$custom_filters[] = array( $obj );

			}

			$filters = array(
				'filterSlug'    => 'custom',
				'parent-filter' => 'all',
				'filters'       => wp_json_encode( $custom_filters ),
				'orderby'       => 'date_updated',
				'order'         => 'DESC',
				'page_no'       => 1,
				'search'        => '',
			);

			$filters = apply_filters( 'wpsc_dbw_set_filter', $filters, $custom_filters, $card );

			setcookie( 'wpsc-tl-filters', wp_json_encode( $filters ), time() + 3600 );

			$url = '';
			if ( $view === '0' ) {
				$url = admin_url( 'admin.php?page=wpsc-tickets&section=ticket-list' );
			} elseif ( ( $page_settings['ticket-url-page'] == 'support-page' && $page_settings['support-page'] ) ||
					$page_settings['ticket-url-page'] == 'open-ticket-page' && $page_settings['open-ticket-page'] ) {

				$url = get_permalink( $page_settings['support-page'] );
				$url = add_query_arg(
					array(
						'wpsc-section' => 'ticket-list',
					),
					$url
				);
			}

			wp_send_json( array( 'url' => $url ) );
		}
	}
endif;

WPSC_Dashboard_Action::init();
