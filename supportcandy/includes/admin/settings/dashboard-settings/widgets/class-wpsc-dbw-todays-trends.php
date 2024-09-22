<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_DBW_Todays_Trends' ) ) :

	final class WPSC_DBW_Todays_Trends {

		/**
		 * Widget slug
		 *
		 * @var string
		 */
		public static $widget = 'todays-trends';

		/**
		 * Initialize this class
		 */
		public static function init() {
		}

		/**
		 * Todays trends
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
						<?php WPSC_Icons::get( 'line-graph' ); ?>
						<span>
							<?php
							$title = $widget['title'] ? WPSC_Translations::get( 'wpsc-dashboard-widget-' . $slug, stripslashes( htmlspecialchars( $widget['title'] ) ) ) : stripslashes( htmlspecialchars( $widget['title'] ) );
							echo esc_attr( $title );
							?>
						</span>
					</div>
					<div class="wpsc-dash-widget-actions">
					</div>
				</div>
				<div class="wpsc-dash-widget-content wpsc-dbw-line-graph" id="wpsc-dash-todays-trend">
					<canvas id="wpsc-dbw-todays-trend"></canvas>
				</div>
			</div>
			<script>
				wpsc_get_dbw_todays_trend();
				async function wpsc_get_dbw_todays_trend() { 
					jQuery('#wpsc-dash-todays-trend').html( supportcandy.loader_html );
					var dates = wpsc_db_set_filter_duration_dates('today');
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
							jQuery('#wpsc-dash-todays-trend').text( 'Something went wrong!' );
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
					jQuery('#wpsc-dash-todays-trend').html( '<canvas id="wpsc-dbw-todays-trend"></canvas>' );
					new Chart(
						document.getElementById( 'wpsc-dbw-todays-trend' ),
						config
					);
				}
			</script>
			<?php
		}
	}
endif;
WPSC_DBW_Todays_Trends::init();
