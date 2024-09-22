<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_DBW_Week_Trend_Tickets' ) ) :

	final class WPSC_DBW_Week_Trend_Tickets {

		/**
		 * Widget slug
		 *
		 * @var string
		 */
		public static $widget = 'week-trends';

		/**
		 * Initialize this class
		 */
		public static function init() {

			add_action( 'wp_ajax_wpsc_avg_tickets_bar_chart', array( __CLASS__, 'avg_tickets_bar_chart' ) );
			add_action( 'wp_ajax_nopriv_wpsc_avg_tickets_bar_chart', array( __CLASS__, 'avg_tickets_bar_chart' ) );
		}

		/**
		 * Average tickets
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
						<?php WPSC_Icons::get( 'pie-chart' ); ?>
						<span>
							<?php
							$title = $widget['title'] ? WPSC_Translations::get( 'wpsc-dashboard-widget-' . $slug, stripslashes( htmlspecialchars( $widget['title'] ) ) ) : stripslashes( htmlspecialchars( $widget['title'] ) );
							echo esc_attr( $title );
							?>
						</span>
					</div>
					<div class="wpsc-dash-widget-actions">
						<select name="" id="date_wise_avg_tickets_report" onchange="wpsc_avg_tickets_bar_chart();" style="min-height: 18px !important;max-height: 18px !important;line-height: 15px !important;font-size: 12px !important;">
							<option value="last_7"><?php esc_attr_e( 'Last 7 days', 'supportcandy' ); ?></option>
							<option value="last_week"><?php esc_attr_e( 'Last Week', 'supportcandy' ); ?></option>
							<option value="last_30"><?php esc_attr_e( 'Last 30 Days', 'supportcandy' ); ?></option>
							<option value="this_month"><?php esc_attr_e( 'This Month', 'supportcandy' ); ?></option>
							<option value="last_month"><?php esc_attr_e( 'Last Month', 'supportcandy' ); ?></option>
						</select>
					</div>
				</div>
				<div class="wpsc-dash-widget-content wpsc-dbw-line-graph" id="wpsc-dash-week-trends"></div>
			</div>
			<script>
				wpsc_avg_tickets_bar_chart();
				function wpsc_avg_tickets_bar_chart() {
					jQuery( '#wpsc-dash-week-trends' ).html( supportcandy.loader_html );
					var date_range = jQuery('#date_wise_avg_tickets_report').val();
					var data = { action: 'wpsc_avg_tickets_bar_chart', date_range, _ajax_nonce: supportcandy.nonce };
					jQuery.post(
						supportcandy.ajax_url,
						data,
						function (response) {
							jQuery('#wpsc-dash-week-trends').html(response.chart);
						}
					);
				}
			</script>
			<?php
		}

		/**
		 * Average tickets vertical bar chart
		 *
		 * @return void
		 */
		public static function avg_tickets_bar_chart() {

			if ( check_ajax_referer( 'general', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$range = isset( $_POST['date_range'] ) ? sanitize_text_field( wp_unslash( $_POST['date_range'] ) ) : '';
			if ( ! $range ) {
				wp_send_json_error( 'Something went wrong', 400 );
			}

			$current_user = WPSC_Current_User::$current_user;
			$widgets = get_option( 'wpsc-dashboard-widgets', array() );
			if ( $current_user->is_guest ||
				! ( $current_user->is_agent && in_array( $current_user->agent->role, $widgets[ self::$widget ]['allowed-agent-roles'] ) )
			) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$tl_agent_setting = get_option( 'wpsc-tl-ms-agent-view', array() );

			// calculate date range.
			$date_range = WPSC_Functions::get_dashboard_date_range( $range );

			$days = array(
				esc_attr__( 'Monday', 'supportcandy' ),
				esc_attr__( 'Tuesday', 'supportcandy' ),
				esc_attr__( 'Wednesday', 'supportcandy' ),
				esc_attr__( 'Thursday', 'supportcandy' ),
				esc_attr__( 'Friday', 'supportcandy' ),
				esc_attr__( 'Saturday', 'supportcandy' ),
				esc_attr__( 'Sunday', 'supportcandy' ),
			);

			$day_names = array();
			$random_color = array();
			$filters = array();

			foreach ( $days as $day ) {

				$day_names[] = '"' . $day . '"';
				$random_color[] = "'" . WPSC_Functions::generate_random_color() . "'";

			}
			$args = array(
				'items_per_page' => 0,
				'system_query'   => $current_user->get_tl_system_query( $filters ),
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'slug'    => 'date_created',
						'compare' => 'BETWEEN',
						'val'     => array(
							'operand_val_1' => $date_range[0],
							'operand_val_2' => $date_range[1],
						),
					),
				),
			);
			$total_tickets = WPSC_Ticket::find( $args )['results'];
			$day_counts = array(
				'Monday'    => 0,
				'Tuesday'   => 0,
				'Wednesday' => 0,
				'Thursday'  => 0,
				'Friday'    => 0,
				'Saturday'  => 0,
				'Sunday'    => 0,
			);
			$temp = array();
			foreach ( $total_tickets as $ticket ) {
				$date_created = $ticket->date_created;
				$weeks = $date_created->format( 'l' );
				$temp = $day_counts[ $weeks ]++;
			}
			ob_start();
			?>
			<div class="graph-container">
				<div id="week-trends-bar-chart">
					<canvas id="weekTrendBarChart" style="height: 350px;"></canvas>
				</div>
			</div>
			<script>
				var data   = {
					labels: [<?php echo wp_kses_post( implode( ',', $day_names ) ); ?>],
					datasets: [
						{
							label: '',
							backgroundColor: [<?php echo wp_kses_post( implode( ',', $random_color ) ); ?>],
							borderColor: [<?php echo wp_kses_post( implode( ',', $random_color ) ); ?>],
							borderWidth: 1,
							data: [<?php echo wp_kses_post( implode( ',', $day_counts ) ); ?>]
						}
					]
				};
				var config = {
					type: 'bar',
					data,
					options: {
						plugins: {
							legend: {
								display: false
							}
						},
						responsive: true,
						maintainAspectRatio: false,
						scales: {
							y: {
								beginAtZero: true,
							}
						}
					}
				};
				new Chart(
					document.getElementById( 'weekTrendBarChart' ),
					config
				);
			</script>
			<?php
			$chart = ob_get_clean();
			wp_send_json( array( 'chart' => $chart ) );
		}
	}
endif;
WPSC_DBW_Week_Trend_Tickets::init();