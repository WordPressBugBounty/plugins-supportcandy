<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_DBW_Recent_Tickets' ) ) :

	final class WPSC_DBW_Recent_Tickets {

		/**
		 * Card slug
		 *
		 * @var string
		 */
		public static $widget = 'recent-tickets';

		/**
		 * Initialize this class
		 */
		public static function init() {

			// Recent Ticket List.
			add_action( 'wp_ajax_wpsc_recent_tickets_list', array( __CLASS__, 'recent_tickets_list' ) );
			add_action( 'wp_ajax_nopriv_wpsc_recent_tickets_list', array( __CLASS__, 'recent_tickets_list' ) );
		}

		/**
		 * Recent tickets
		 *
		 * @param $slug   $slug - slug name.
		 * @param $widget $widget - widget array.
		 * @return void
		 */
		public static function print_dashboard_widget( $slug, $widget ) {

			$current_user = WPSC_Current_User::$current_user;
			if ( $current_user->is_guest ||
			! ( $current_user->is_agent && in_array( $current_user->agent->role, $widget['allowed-agent-roles'] ) )
			) {
				return;
			}
			?>
			<div class="wpsc-dash-widget wpsc-dash-widget-mid wpsc-<?php echo esc_attr( $slug ); ?>">
				<div class="wpsc-dash-widget-header">
					<div class="wpsc-dashboard-widget-icon-header">
						<?php WPSC_Icons::get( 'list' ); ?>
						<span>
							<?php
							$title = $widget['title'] ? WPSC_Translations::get( 'wpsc-dashboard-widget-' . $slug, stripslashes( htmlspecialchars( $widget['title'] ) ) ) : stripslashes( htmlspecialchars( $widget['title'] ) );
							echo esc_attr( $title );
							?>
						</span>
					</div>
					<div class="wpsc-dash-widget-actions">
						<a href="javascript:wpsc_get_ticket_list();"><?php echo esc_attr__( 'View All', 'supportcandy' ); ?></a>
					</div>
				</div>
				<div class="wpsc-dash-widget-content wpsc-dbw-info" id="wpsc-recent-ticket"></div>
			</div>
			<script>
				wpsc_recent_tickets_list();
				function wpsc_recent_tickets_list() {
					jQuery('#wpsc-recent-ticket').html( supportcandy.loader_html );
					var data = { action: 'wpsc_recent_tickets_list', view: supportcandy.is_frontend, _ajax_nonce: supportcandy.nonce };
					jQuery . post(
						supportcandy . ajax_url,
						data,
						function ( response ) {
							jQuery( '#wpsc-recent-ticket' ) . html( response.html );
						}
					);
				}
			</script>
			<?php
		}

		/**
		 * Recent tickets list
		 *
		 * @return void
		 */
		public static function recent_tickets_list() {

			if ( check_ajax_referer( 'general', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$view = isset( $_POST['view'] ) ? sanitize_text_field( wp_unslash( $_POST['view'] ) ) : '0';

			$current_user = WPSC_Current_User::$current_user;
			$widgets = get_option( 'wpsc-dashboard-widgets', array() );
			if ( $current_user->is_guest ||
				! ( $current_user->is_agent && in_array( $current_user->agent->role, $widgets[ self::$widget ]['allowed-agent-roles'] ) )
			) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$filters = array();
			$tickets = WPSC_Ticket::find(
				array(
					'items_per_page' => 10,
					'orderby'        => 'date_created',
					'order'          => 'DESC',
					'system_query'   => $current_user->get_tl_system_query( $filters ),
				)
			)['results'];
			ob_start();
			?>
			<table class="wpsc-recent-ticket-list">
				<thead>
					<th><?php esc_attr_e( 'Ticket', 'supportcandy' ); ?></th>
					<th><?php esc_attr_e( 'Status', 'supportcandy' ); ?></th>
					<th><?php esc_attr_e( 'Subject', 'supportcandy' ); ?></th>
					<th><?php esc_attr_e( 'Assignee', 'supportcandy' ); ?></th>
				</thead>
				<tbody>
					<?php
					if ( $tickets ) {
						foreach ( $tickets as $ticket ) {
							$url = WPSC_Functions::get_ticket_url( $ticket->id, $view );
							?>
							<tr>
								<td>
									<a href="<?php echo esc_attr( $url ); ?>" target="_blank">
										<?php echo esc_attr( '#' . $ticket->id ); ?>
									</a> 
								</td>
								<td>
									<div class="wpsc-tag" style="background-color: <?php echo esc_attr( $ticket->status->bg_color ); ?>; color:<?php echo esc_attr( $ticket->status->color ); ?>;">
										<?php echo esc_attr( $ticket->status->name ); ?>
									</div>
								</td>
								<td><?php echo esc_attr( $ticket->subject ); ?></td>
								<td>
									<?php
										$agent_names = array_filter(
											array_map(
												fn( $agent ) => $agent->id ? $agent->name : '',
												$ticket->assigned_agent
											)
										);
										$value = $agent_names ? implode( ', ', $agent_names ) : esc_attr__( 'None', 'supportcandy' );
										echo esc_attr( $value );
									?>
								</td>
							</tr>
							<?php
						}
					} else {
						echo esc_attr__( 'No recent tickets!', 'supportcandy' );
					}
					?>
				</tbody>
			</table>
			<script>
				jQuery('table.wpsc-recent-ticket-list').DataTable({
					ordering: true,
					order: [[0, 'desc']],
					pageLength: 10,
					bLengthChange: false,
					info: false,
					paging: false,
					searching: false,
					columnDefs: [
						{ targets: '_all', className: 'dt-left' },
						{
							"targets": '_all', // All columns
							"searchable": false,
							"orderable": true
						}
					],
					language: supportcandy.translations.datatables
				});
			</script>
			<?php
			$recent_tickets = ob_get_clean();

			wp_send_json( array( 'html' => $recent_tickets ) );

			wp_die();
		}
	}
endif;
WPSC_DBW_Recent_Tickets::init();
