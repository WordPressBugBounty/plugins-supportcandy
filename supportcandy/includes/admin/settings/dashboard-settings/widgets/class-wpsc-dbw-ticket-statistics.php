<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_DBW_Ticket_Statistics' ) ) :

	final class WPSC_DBW_Ticket_Statistics {

		/**
		 * Widget slug
		 *
		 * @var string
		 */
		public static $widget = 'ticket-statistics';

		/**
		 * Initialize this class
		 */
		public static function init() {

			add_action( 'wp_ajax_wpsc_dbw_run_ts_report', array( __CLASS__, 'run_ts_reports' ) );
		}

		/**
		 * Ticket statistics
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
			$db_gs = get_option( 'wpsc-db-gs-settings' );
			?>
			<div class="wpsc-dash-widget wpsc-dash-widget-mid wpsc-<?php echo esc_attr( $slug ); ?>">
				<div class="wpsc-dash-widget-header">
					<div class="wpsc-dashboard-widget-icon-header">
						<?php WPSC_Icons::get( 'line-graph' ); ?>
						<span>
							<?php
							$title = $widget['title'] ? WPSC_Translations::get( 'wpsc-dashboard-widget-' . $slug, stripslashes( htmlspecialchars( $widget['title'] ) ) ) : stripslashes( htmlspecialchars( $widget['title'] ) );
							echo esc_attr( $title );
							?>
						</span>
					</div>
					<div class="wpsc-dash-widget-actions">
						<div class="ticket-info-container">
							<div><?php esc_attr_e( 'Ticket created', 'supportcandy' ); ?>: <span class="tickets-created"></span></div>
							<div><?php esc_attr_e( 'Ticket closed', 'supportcandy' ); ?>: <span class="tickets-closed"></span></div>
							<select name="" class="select-box" id="date_wise_ts_report" onchange="wpsc_get_dbw_ticket_statistics();">
								<option <?php selected( $db_gs['default-date-range'], 'today' ); ?> value="today"><?php esc_attr_e( 'Today', 'supportcandy' ); ?></option>
								<option <?php selected( $db_gs['default-date-range'], 'yesterday' ); ?> value="yesterday"><?php esc_attr_e( 'Yesterday', 'supportcandy' ); ?></option>
								<option <?php selected( $db_gs['default-date-range'], 'this-week' ); ?> value="this-week"><?php esc_attr_e( 'This week', 'supportcandy' ); ?></option>
								<option <?php selected( $db_gs['default-date-range'], 'last-week' ); ?> value="last-week"><?php esc_attr_e( 'Last week', 'supportcandy' ); ?></option>
								<option <?php selected( $db_gs['default-date-range'], 'last-30-days' ); ?> value="last-30-days"><?php esc_attr_e( 'Last 30 days', 'supportcandy' ); ?></option>
								<option <?php selected( $db_gs['default-date-range'], 'this-month' ); ?> value="this-month"><?php esc_attr_e( 'This month', 'supportcandy' ); ?></option>
								<option <?php selected( $db_gs['default-date-range'], 'last-month' ); ?> value="last-month"><?php esc_attr_e( 'Last month', 'supportcandy' ); ?></option>
							</select>
						</div>
					</div>
				</div>
				<div class="wpsc-dash-widget-content wpsc-dbw-line-graph" id="wpsc-dash-ticket-statistics">
					<canvas id="wpsc-dbw-ticket-statistics"></canvas>
				</div>
			</div>
			<script>
				wpsc_get_dbw_ticket_statistics();
				async function wpsc_get_dbw_ticket_statistics() { 
					jQuery('#wpsc-dash-ticket-statistics').html( supportcandy.loader_html );
					var date_range = jQuery('#date_wise_ts_report').val();
					var dates = wpsc_db_set_filter_duration_dates(date_range);
					var startDate = new Date(dates.from);
					var endDate = new Date(dates.to);
					let report = {
						batches: wpsc_rp_get_baches(startDate, endDate),
						labels: [],
						created: [],
						closed: []
					}

					var batchesLength = report.batches.length;
					for (let i = 0; i < batchesLength; i++) {

						var promises = [];

						var batchLength = report.batches[i].length;
						for (let j = 0; j < batchLength; j++) {

							var duration = report.batches[i][j];
							promises.push(
								new Promise(
									function (resolve, reject) {

										dataform = new FormData();
										dataform.append( 'action', 'wpsc_dbw_run_ts_report' );
										dataform.append( 'from_date', duration.fromDate );
										dataform.append( 'to_date', duration.toDate );
										dataform.append( 'duration_type', duration.durationType );
										dataform.append( '_ajax_nonce', '<?php echo esc_attr( wp_create_nonce( 'ticket_statistics' ) ); ?>' );
										jQuery.ajax(
											{
												url: supportcandy.ajax_url,
												type: 'POST',
												data: dataform,
												processData: false,
												contentType: false
											}
										).done(
											function (res) {
												resolve( res );
											}
										).fail(
											function () {
												reject( new Error() );
											}
										);
									}
								)
							);
						}

						var isValidResults = true;
						var results        = await Promise.all( promises.map( p => p.catch( e => e ) ) );
						jQuery.each(
							results,
							function (index, response) {
								if (response instanceof Error) {
									isValidResults = false;
									return false;
								}
								report.labels.push( response.label );
								report.created.push( parseInt( response.created ) );
								report.closed.push( parseInt( response.closed ) );
							}
						);

						if ( ! isValidResults) {
							jQuery('#wpsc-dash-ticket-statistics').text( 'Something went wrong!' );
							return;
						}
					}

					var data   = {
						labels: report.labels,
						datasets: [
							{
								label: 'Tickets Created',
								backgroundColor: '#e74c3c',
								borderColor: '#e74c3c',
								data: report.created
							},
							{
								label: 'Tickets Closed',
								backgroundColor: '#2980b9',
								borderColor: '#2980b9',
								data: report.closed
							}
						]
					};
					var config = {
						type: 'line',
						data,
						options: {
							scales: {
								y: {
									beginAtZero: true
								}
							}
						}
					};
					jQuery('#wpsc-dash-ticket-statistics').html( '<canvas id="wpsc-dbw-ticket-statistics"></canvas>' );
					new Chart(
						document.getElementById( 'wpsc-dbw-ticket-statistics' ),
						config
					);

					//Total number of created tickets
					jQuery('.tickets-created').text(report.created.reduce((acc, curr) => acc + curr, 0));
					//Total number of closed tickets
					jQuery('.tickets-closed').text(report.closed.reduce((acc, curr) => acc + curr, 0));
				}
			</script>
			<?php
		}

		/**
		 * Run ticket statistics report
		 *
		 * @return void
		 */
		public static function run_ts_reports() {

			if ( check_ajax_referer( 'ticket_statistics', '_ajax_nonce', false ) !== 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$current_user = WPSC_Current_User::$current_user;
			if ( ! ( $current_user->is_agent ) ) {
				wp_send_json_error( __( 'Unauthorized', 'supportcandy' ), 401 );
			}

			$from_date = isset( $_POST['from_date'] ) ? sanitize_text_field( wp_unslash( $_POST['from_date'] ) ) : '';
			if (
				! $from_date ||
				! preg_match( '/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $from_date )
			) {
				wp_send_json_error( 'Bad request', 400 );
			}

			$to_date = isset( $_POST['to_date'] ) ? sanitize_text_field( wp_unslash( $_POST['to_date'] ) ) : '';
			if (
				! $to_date ||
				! preg_match( '/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $to_date )
			) {
				wp_send_json_error( 'Bad request', 400 );
			}

			$duration_time = isset( $_POST['duration_type'] ) ? sanitize_text_field( wp_unslash( $_POST['duration_type'] ) ) : '';
			if (
				! $duration_time ||
				! in_array( $duration_time, array( 'day', 'days', 'weeks', 'months', 'years' ) )
			) {
				wp_send_json_error( 'Bad request', 400 );
			}

			// current filter (default 'All').
			$filter = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : '';

			// custom filters.
			$filters = isset( $_POST['filters'] ) ? stripslashes( sanitize_textarea_field( wp_unslash( $_POST['filters'] ) ) ) : '';

			// filter arguments.
			$args = array(
				'is_active'      => 1,
				'items_per_page' => 1,
			);

			// meta query.
			$meta_query = array( 'relation' => 'AND' );

			// custom filters (if any).
			if ( $filter == 'custom' ) {
				if ( ! $filters ) {
					wp_send_json_error( 'Bad Request', 400 );
				}
				$meta_query = array_merge( $meta_query, WPSC_Ticket_Conditions::get_meta_query( $filters ) );
			}

			// saved filter (if applied).
			if ( is_numeric( $filter ) ) {
				$saved_filters = get_user_meta( $current_user->user->ID, get_current_blog_id() . '-wpsc-rp-saved-filters', true );
				if ( ! isset( $saved_filters[ intval( $filter ) ] ) ) {
					wp_send_json_error( 'Bad Request', 400 );
				}
				$filter_str  = $saved_filters[ intval( $filter ) ]['filters'];
				$filter_str  = str_replace( '^^', '\n', $filter_str );
				$meta_query  = array_merge( $meta_query, WPSC_Ticket_Conditions::get_meta_query( $filter_str ) );
			}

			// closed statuses.
			$ms_advance_settings = get_option( 'wpsc-tl-ms-advanced' );
			$closed_statuses     = $ms_advance_settings['closed-ticket-statuses'];

			$response = array();
			$filters = array();

			// label.
			switch ( $duration_time ) {

				case 'day':
					$response['label'] = sprintf(
						'%1$s - %2$s',
						( new DateTime( $from_date ) )->format( 'H:i' ),
						( new DateTime( $to_date ) )->format( 'H:i' )
					);
					break;

				case 'days':
					$response['label'] = ( new DateTime( $from_date ) )->format( 'Y-m-d' );
					break;

				case 'weeks':
					$response['label'] = sprintf(
						'%1$s - %2$s',
						( new DateTime( $from_date ) )->format( 'M d' ),
						( new DateTime( $to_date ) )->format( 'M d' )
					);
					break;

				case 'months':
					$response['label'] = ( new DateTime( $from_date ) )->format( 'F Y' );
					break;

				case 'years':
					$response['label'] = ( new DateTime( $from_date ) )->format( 'Y' );
					break;
			}

			// created.
			$created_meta_query  = array(
				array(
					'slug'    => 'date_created',
					'compare' => 'BETWEEN',
					'val'     => array(
						'operand_val_1' => ( new DateTime( $from_date ) )->format( 'Y-m-d H:i:s' ),
						'operand_val_2' => ( new DateTime( $to_date ) )->format( 'Y-m-d H:i:s' ),
					),
				),
			);
			$args['system_query'] = $current_user->get_tl_system_query( $filters );
			$args['meta_query']  = array_merge( $meta_query, $created_meta_query );
			$results             = WPSC_Ticket::find( $args );
			$response['created'] = $results['total_items'];

			// closed.
			$closed_meta_query  = array(
				array(
					'slug'    => 'date_closed',
					'compare' => 'BETWEEN',
					'val'     => array(
						'operand_val_1' => ( new DateTime( $from_date ) )->format( 'Y-m-d H:i:s' ),
						'operand_val_2' => ( new DateTime( $to_date ) )->format( 'Y-m-d H:i:s' ),
					),
				),
				array(
					'slug'    => 'status',
					'compare' => 'IN',
					'val'     => $closed_statuses,
				),
			);
			$args['meta_query'] = array_merge( $meta_query, $closed_meta_query );
			$results            = WPSC_Ticket::find( $args );
			$response['closed'] = $results['total_items'];

			wp_send_json( $response, 200 );
		}
	}
endif;
WPSC_DBW_Ticket_Statistics::init();
