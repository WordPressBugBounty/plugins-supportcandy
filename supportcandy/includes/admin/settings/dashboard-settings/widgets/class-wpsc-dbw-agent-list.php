<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_DBW_Agent_List' ) ) :

	final class WPSC_DBW_Agent_List {

		/**
		 * Widget slug
		 *
		 * @var string
		 */
		public static $widget = 'agent-list';

		/**
		 * Initialize this class
		 */
		public static function init() {

			// Get list of agents.
			add_action( 'wp_ajax_wpsc_dash_get_agent_ticket_list', array( __CLASS__, 'get_list' ) );
			add_action( 'wp_ajax_nopriv_wpsc_dash_get_agent_ticket_list', array( __CLASS__, 'get_list' ) );

			// Set filter for the card.
			add_action( 'wp_ajax_wpsc_get_agent_status_ticket_list', array( __CLASS__, 'get_agent_status_ticket_list' ) );
			add_action( 'wp_ajax_nopriv_wpsc_get_agent_status_ticket_list', array( __CLASS__, 'get_agent_status_ticket_list' ) );
		}

		/**
		 * Agents list
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
			<div class="wpsc-dash-widget wpsc-dash-widget-large wpsc-<?php echo esc_attr( $slug ); ?>">
				<div class="wpsc-dash-widget-header">
					<div class="wpsc-dashboard-widget-icon-header">
						<?php WPSC_Icons::get( 'users' ); ?>
						<span>
							<?php
							$title = $widget['title'] ? WPSC_Translations::get( 'wpsc-dashboard-widget-' . $slug, stripslashes( htmlspecialchars( $widget['title'] ) ) ) : stripslashes( htmlspecialchars( $widget['title'] ) );
							echo esc_attr( $title );
							?>
						</span>
					</div>
					<div class="wpsc-dash-widget-actions">
						<input type="text" id="wpsc-db-al-search" placeholder="<?php esc_attr_e( 'Search...', 'supportcandy' ); ?>">
					</div>
				</div>
				<div class="wpsc-dash-widget-content wpsc-dbw-info" id="wpsc-dash-agent-list"></div>
			</div>
			<script>
				wpsc_dash_get_agent_ticket_list();
				function wpsc_dash_get_agent_ticket_list(){
					jQuery('#wpsc-dash-agent-list').html( supportcandy.loader_html );
					var data = { action: 'wpsc_dash_get_agent_ticket_list' };
					jQuery.post(
						supportcandy.ajax_url,
						data,
						function (response) {
							jQuery('#wpsc-dash-agent-list').html(response.html);
						}
					);
				}

				jQuery(document).ready(function() {
					jQuery('#wpsc-db-al-search').on('keyup', function() {
							var searchTerm = jQuery(this).val();
							jQuery('table.wpsc-db-agent-list').DataTable().search(searchTerm).draw();
					});
				})
			</script>
			<?php
		}

		/**
		 * Get agent list and ticket count.
		 *
		 * @return void
		 */
		public static function get_list() {

			$current_user = WPSC_Current_User::$current_user;
			$widgets = get_option( 'wpsc-dashboard-widgets', array() );
			if ( $current_user->is_guest ||
				! ( $current_user->is_agent && in_array( $current_user->agent->role, $widgets[ self::$widget ]['allowed-agent-roles'] ) )
			) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$agent_view = get_option( 'wpsc-tl-ms-agent-view', array() );
			$selected_statuses = isset( $agent_view['unresolved-ticket-statuses'] ) && is_array( $agent_view['unresolved-ticket-statuses'] ) ? $agent_view['unresolved-ticket-statuses'] : array();

			$statuses = WPSC_Status::find(
				array(
					'items_per_page' => 0,
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'id',
							'compare' => 'IN',
							'val'     => $selected_statuses,
						),
					),
				)
			)['results'];

			$agents = WPSC_Agent::find(
				array(
					'items_per_page' => 0,
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'is_active',
							'compare' => '=',
							'val'     => 1,
						),
						array(
							'slug'    => 'is_agentgroup',
							'compare' => '=',
							'val'     => 0,
						),
					),
				)
			)['results'];

			ob_start();
			?>
			<table class="wpsc-db-agent-list">
				<thead>
					<tr>
						<th><?php echo esc_attr__( 'Agent', 'supportcandy' ); ?></th>
						<?php
						foreach ( $statuses as $status ) :
							?>
							<th><?php echo esc_attr( $status->name ); ?></th>
							<?php
						endforeach;
						?>
						<th><?php echo esc_attr__( 'Total', 'supportcandy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( $agents ) :
						foreach ( $agents as $agent ) :
							?>
							<tr>
								<td><?php echo esc_attr( $agent->name ); ?></td>
								<?php
								$total_count = 0;
								foreach ( $statuses as $status ) :
									$count = WPSC_Ticket::find(
										array(
											'items_per_page' => 0,
											'meta_query' => array(
												'relation' => 'AND',
												array(
													'slug' => 'assigned_agent',
													'compare' => 'IN',
													'val'  => array( $agent->id ),
												),
												array(
													'slug' => 'status',
													'compare' => '=',
													'val'  => $status->id,
												),
											),
										)
									)['total_items'];
									?>
									<td>
										<?php
										if ( $count ) {
											?>
											<a class="wpsc-link" onclick="wpsc_get_agent_status_ticket_list(<?php echo esc_attr( $agent->id ); ?>, <?php echo esc_attr( $status->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_agent_status_ticket_list' ) ); ?>' )">
											<?php echo esc_attr( $count ); ?>
											</a>
											<?php
										} else {
											echo esc_attr( $count );
										}
										?>
									</td>
									<?php
									$total_count = $total_count + $count;
								endforeach;
								?>
								<td>
									<?php
									if ( $total_count ) {
										?>
										<a class="wpsc-link" onclick="wpsc_get_agent_status_ticket_list(<?php echo esc_attr( $agent->id ); ?>, 0, '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_agent_status_ticket_list' ) ); ?>' )">
										<?php echo esc_attr( $total_count ); ?>
										</a>
										<?php
									} else {
										echo esc_attr( $total_count );
									}
									?>
								</td>
							</tr>
							<?php
						endforeach;
					endif;
					?>
				</tbody>
			</table>
			<script>
				var indexLastColumn = jQuery("table.wpsc-db-agent-list").find('tr')[0].cells.length-1;
				jQuery('table.wpsc-db-agent-list').DataTable({
					ordering: true,
					order: [[indexLastColumn, 'desc']],
					pageLength: 10,
					searching: true,
					bLengthChange: false,
					columnDefs: [
						{ targets: '_all', className: 'dt-left' },
						{
							"targets": [0], // First column
							"searchable": true,
							"orderable": false,
						},
						{
							"targets": '_all', // All other columns
							"searchable": false,
							"orderable": true
						}
					],
					language: supportcandy.translations.datatables
				});
			</script>
			<?php
			$table = ob_get_clean();
			wp_send_json( array( 'html' => $table ) );
		}

		/**
		 * Set filter for the agent status.
		 *
		 * @return void
		 */
		public static function get_agent_status_ticket_list() {

			if ( check_ajax_referer( 'wpsc_get_agent_status_ticket_list', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$agent_id = isset( $_POST['agent_id'] ) ? intval( $_POST['agent_id'] ) : 0;
			if ( ! $agent_id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$agent = new WPSC_Agent( $agent_id );
			if ( ! $agent->id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$status_id = isset( $_POST['status_id'] ) ? intval( $_POST['status_id'] ) : 0;
			$view = isset( $_POST['view'] ) ? sanitize_text_field( wp_unslash( $_POST['view'] ) ) : '0';

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_agent ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$page_settings = get_option( 'wpsc-gs-page-settings' );

			$custom_filters = array();

			$obj = new stdClass();
			$obj->slug = 'assigned_agent';
			$obj->operator = '=';
			$obj->operand_val_1 = $agent->id;
			$custom_filters[] = array( $obj );

			if ( $status_id ) {

				$obj = new stdClass();
				$obj->slug = 'status';
				$obj->operator = '=';
				$obj->operand_val_1 = $status_id;
				$custom_filters[] = array( $obj );

			} else {

				$agent_view = get_option( 'wpsc-tl-ms-agent-view', array() );
				$selected_statuses = isset( $agent_view['unresolved-ticket-statuses'] ) && is_array( $agent_view['unresolved-ticket-statuses'] ) ? $agent_view['unresolved-ticket-statuses'] : array();

				$obj = new stdClass();
				$obj->slug = 'status';
				$obj->operator = 'IN';
				$obj->operand_val_1 = $selected_statuses;
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

			setcookie( 'wpsc-tl-filters', wp_json_encode( $filters ), time() + 3600 );

			$url = '';
			if ( $view === '0' ) {
				$url = admin_url( 'admin.php?page=wpsc-tickets&section=ticket-list' );
			} elseif ( ( $page_settings['ticket-url-page'] == 'support-page' && $page_settings['support-page'] ) ||
						( $page_settings['ticket-url-page'] == 'open-ticket-page' && $page_settings['open-ticket-page'] ) ) {
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
WPSC_DBW_Agent_List::init();
