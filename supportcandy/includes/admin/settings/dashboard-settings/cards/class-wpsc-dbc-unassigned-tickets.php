<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_DBC_Unassigned_Tickets' ) ) :

	final class WPSC_DBC_Unassigned_Tickets {

		/**
		 * Card slug
		 *
		 * @var string
		 */
		public static $card = 'unassigned';

		/**
		 * Initialize this class
		 */
		public static function init() {

			// Get count for the card.
			add_action( 'wp_ajax_wpsc_dash_card_unassigned_tickets', array( __CLASS__, 'get_count' ) );
			add_action( 'wp_ajax_nopriv_wpsc_dash_card_unassigned_tickets', array( __CLASS__, 'get_count' ) );
		}

		/**
		 * Calculate the unassigned ticket counts
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
				<div class="wpsc-dashboard-card-icon-header" onclick="wpsc_get_dbc_ticket_list(this, 'unassigned-tickets')">
					<h2 class="wpsc-dbc-count" id="wpsc-dunt-count">0</h2>
					<p>
						<?php
						$title = $card['title'] ? WPSC_Translations::get( 'wpsc-dashboard-card-' . $slug, stripslashes( htmlspecialchars( $card['title'] ) ) ) : stripslashes( htmlspecialchars( $card['title'] ) );
						echo esc_attr( $title );
						?>
					</p>
				</div>
				<?php WPSC_Icons::get( 'users-avatar' ); ?>
			</div>
			<script>
				wpsc_dash_card_unassigned_tickets();
				function wpsc_dash_card_unassigned_tickets(params) {
					var data = { action: 'wpsc_dash_card_unassigned_tickets' };
					jQuery.post(
						supportcandy.ajax_url,
						data,
						function (response) {
							jQuery('#wpsc-dunt-count').html(response.count);
						}
					);
				}
			</script>
			<?php
		}

		/**
		 * Get count of unassigned tickets.
		 *
		 * @return void
		 */
		public static function get_count() {

			$current_user = WPSC_Current_User::$current_user;
			$cards = get_option( 'wpsc-dashboard-cards', array() );
			$more_settings = get_option( 'wpsc-tl-ms-agent-view' );

			if ( $current_user->is_guest ||
			! ( $current_user->is_agent && in_array( $current_user->agent->role, $cards[ self::$card ]['allowed-agent-roles'] ) )
			) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

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
							'val'     => '',
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
WPSC_DBC_Unassigned_Tickets::init();
