<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_DBC_New_Tickets' ) ) :

	final class WPSC_DBC_New_Tickets {

		/**
		 * Card slug
		 *
		 * @var string
		 */
		public static $card = 'new-tickets';

		/**
		 * Initialize this class
		 */
		public static function init() {

			// Get count for the card.
			add_action( 'wp_ajax_wpsc_dash_card_new_tickets', array( __CLASS__, 'get_count' ) );
			add_action( 'wp_ajax_nopriv_wpsc_dash_card_new_tickets', array( __CLASS__, 'get_count' ) );
		}

		/**
		 * Calculate the new ticket counts
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
				<div class="wpsc-dashboard-card-icon-header" onclick="wpsc_get_dbc_ticket_list(this, 'new-tickets')">
					<h2 class="wpsc-dbc-count" id="wpsc-dnt-count">0</h2>
					<p>
						<?php
						$title = $card['title'] ? WPSC_Translations::get( 'wpsc-dashboard-card-' . $slug, stripslashes( htmlspecialchars( $card['title'] ) ) ) : stripslashes( htmlspecialchars( $card['title'] ) );
						echo esc_attr( $title );
						?>
					</p>
				</div>
				<?php WPSC_Icons::get( 'unread' ); ?>
			</div>
			<script>
				wpsc_dash_card_new_tickets();
				function wpsc_dash_card_new_tickets() {
					var data = { action: 'wpsc_dash_card_new_tickets' };
					jQuery.post(
						supportcandy.ajax_url,
						data,
						function (response) {
							jQuery('#wpsc-dnt-count').html(response.count);
						}
					);
				}
			</script>
			<?php
		}

		/**
		 * Get count of new tickets.
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

			$cf  = WPSC_Custom_Field::get_cf_by_slug( 'status' );
			$filters = array();
			$count = WPSC_Ticket::find(
				array(
					'items_per_page' => 0,
					'system_query'   => $current_user->get_tl_system_query( $filters ),
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'status',
							'compare' => '=',
							'val'     => $cf->default_value[0],
						),
					),
				)
			)['total_items'];

			wp_send_json( array( 'count' => $count ) );
		}
	}
endif;
WPSC_DBC_New_Tickets::init();
