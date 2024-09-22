<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_DBC_Mine_Tickets' ) ) :

	final class WPSC_DBC_Mine_Tickets {

		/**
		 * Card slug
		 *
		 * @var string
		 */
		public static $card = 'mine';

		/**
		 * Initialize this class
		 */
		public static function init() {

				// Get count for the card.
				add_action( 'wp_ajax_wpsc_dash_card_mine_tickets', array( __CLASS__, 'get_count' ) );
				add_action( 'wp_ajax_nopriv_wpsc_dash_card_mine_tickets', array( __CLASS__, 'get_count' ) );
		}

		/**
		 * Calculate the mine ticket counts
		 *
		 * @param String $slug - slug name.
		 * @param Array  $card - card array.
		 * @return void
		 */
		public static function print_dashboard_card( $slug, $card ) {

			$current_user = WPSC_Current_User::$current_user;
			if ( $current_user->is_guest ||
				! ( $current_user->is_agent && in_array( $current_user->agent->role, $card['allowed-agent-roles'] ) )
			) {
				return;
			}
			?>
			<div class="wpsc-dash-widget wpsc-dash-widget-small wpsc-<?php echo esc_attr( $slug ); ?>">
				<div class="wpsc-dashboard-card-icon-header" onclick="wpsc_get_dbc_ticket_list(this, 'mine-tickets')">
					<h2 class="wpsc-dbc-count" id="wpsc-dmt-count">0</h2>
					<p>
						<?php
						$title = $card['title'] ? WPSC_Translations::get( 'wpsc-dashboard-card-' . $slug, stripslashes( htmlspecialchars( $card['title'] ) ) ) : stripslashes( htmlspecialchars( $card['title'] ) );
						echo esc_attr( $title );
						?>
					</p>
				</div>
				<?php WPSC_Icons::get( 'assignment' ); ?>
			</div>
			<script>
				wpsc_dash_card_mine_tickets();
				function wpsc_dash_card_mine_tickets(params) {
					var data = { action: 'wpsc_dash_card_mine_tickets' };
					jQuery.post(
						supportcandy.ajax_url,
						data,
						function (response) {
							jQuery('#wpsc-dmt-count').html(response.count);
						}
					);
				}
			</script>
			<?php
		}

		/**
		 * Get count of mine tickets.
		 *
		 * @return void
		 */
		public static function get_count() {

			$current_user = WPSC_Current_User::$current_user;
			$cards = get_option( 'wpsc-dashboard-cards', array() );

			if ( $current_user->is_guest ||
			! ( $current_user->is_agent && in_array( $current_user->agent->role, $cards[ self::$card ]['allowed-agent-roles'] ) )
			) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$more_settings = $current_user->is_agent ? get_option( 'wpsc-tl-ms-agent-view' ) : get_option( 'wpsc-tl-ms-customer-view' );

			$filters = array();
			$count = WPSC_Ticket::find(
				array(
					'items_per_page' => 0,
					'system_query'   => $current_user->get_tl_system_query( $filters ),
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'assigned_agent',
							'compare' => '=',
							'val'     => $current_user->agent->id,
						),
						array(
							'slug'    => 'status',
							'compare' => 'IN',
							'val'     => $more_settings['unresolved-ticket-statuses'],
						),
					),
				)
			)['total_items'];
			wp_send_json( array( 'count' => $count ) );
		}
	}
endif;
WPSC_DBC_Mine_Tickets::init();
