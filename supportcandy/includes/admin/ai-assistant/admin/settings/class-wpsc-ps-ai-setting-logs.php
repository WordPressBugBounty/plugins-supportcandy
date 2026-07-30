<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Setting_Logs' ) ) :

	final class WPSC_PS_AI_Setting_Logs {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Schedule cron jobs.
			add_action( 'init', array( __CLASS__, 'schedule_events' ) );
			add_action( 'wp_ajax_wpsc_get_aia_logs_setting', array( __CLASS__, 'get_ai_logs' ) );
			add_action( 'wpsc_delete_aia_logs', array( __CLASS__, 'delete_ai_logs' ) );
		}

		/**
		 * Schedule cron job events for SupportCandy
		 *
		 * @return void
		 */
		public static function schedule_events() {

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			$auto_delete_ai_logs_time = isset( $ai_settings['auto-delete-ai-logs-time'] ) ? intval( $ai_settings['auto-delete-ai-logs-time'] ) : 0;
			if ( $auto_delete_ai_logs_time > 0 && ! wp_next_scheduled( 'wpsc_delete_aia_logs' ) ) {
				wp_schedule_single_event( time(), 'wpsc_delete_aia_logs' );
			}
		}

		/**
		 * Get AI assistant general setting
		 *
		 * @return void
		 */
		public static function get_ai_logs() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			$data = array();
			$meta_query = array();
			if ( isset( $_POST['filter'] ) && is_array( $_POST['filter'] ) ) { // phpcs:ignore
				$filter_raw = wp_unslash( $_POST['filter'] ); // phpcs:ignore
				$filter     = array_map( 'sanitize_text_field', $filter_raw );

				if (
					( ! empty( $filter['agent_id'] ) ) ||
					( ! empty( $filter['from_date'] ) && ! empty( $filter['to_date'] ) )
				) {
					$data       = $filter;
					$meta_query = array( 'relation' => 'AND' );

					// Add customer filter if agent_id is provided.
					if ( ! empty( $data['agent_id'] ) ) {
						$meta_query[] = array(
							'slug'    => 'customer',
							'compare' => '=',
							'val'     => $data['agent_id'],
						);
					}

					// Add date range filter only if date_range is provided.
					if ( ! empty( $data['date_range'] ) ) {
						$date_range   = WPSC_Functions::get_dashboard_date_range( $data['date_range'] );
						$meta_query[] = array(
							'slug'    => 'date_created',
							'compare' => 'BETWEEN',
							'val'     => array(
								$date_range[0],
								$date_range[1],
							),
						);
					}
				}
			}

			$logs = WPSC_PS_AI_Logs::find(
				array(
					'orderby'    => 'date_created',
					'order'      => 'DESC',
					'meta_query' => $meta_query,
				)
			);
			?>
			<table class="wpsc-ai-logs wpsc-setting-tbl">
				<thead>
					<tr>
						<th><?php esc_attr_e( 'ID', 'wpsc-ps' ); ?></th>
						<th><?php esc_attr_e( 'Agent', 'wpsc-ps' ); ?></th>
						<th><?php esc_attr_e( 'Ticket', 'wpsc-ps' ); ?></th>
						<th><?php esc_attr_e( 'Model', 'wpsc-ps' ); ?></th>
						<th><?php esc_attr_e( 'Tokens', 'wpsc-ps' ); ?></th>
						<th><?php esc_attr_e( 'Prompt', 'wpsc-ps' ); ?></th>
					</tr>
				</thead>
					<tbody>
						<?php
						if ( isset( $logs['results'] ) && ! empty( $logs['results'] ) ) {
							foreach ( $logs['results'] as $log ) {
								$ticket = $log->ticket;
								if ( ! $ticket->is_active ) {
									continue;
								}

								$subject = isset( $ticket->subject ) ? wp_trim_words( $ticket->subject, 4, '...' ) : '';
								$prompt = '';
								$clean_prompt = trim( preg_replace( '/\s+/', ' ', (string) $log->prompt ) );
								if ( $clean_prompt !== '' ) {
									$prompt = wp_trim_words( $clean_prompt, 7, '...' );
								}
								?>
								<tr>
									<td><?php echo esc_attr( $log->id ); ?></td>
									<td><?php echo esc_attr( $log->customer->name ); ?></td>
									<td>
									<?php
										echo $ticket->id ? sprintf(
											'<a href="%s" target="_blank" style="text-decoration: none;"><div>%s</div></a>',
											esc_url( admin_url( 'admin.php?page=wpsc-tickets&section=ticket-list&id=' . $ticket->id ) ),
											esc_html( '#' . $ticket->id . ' ' . $subject )
										) : '';
									?>
									</td>
									<td><?php echo esc_attr( $log->model ); ?></td>
									<td><?php echo esc_attr( $log->tokens ); ?></td>
									<td>
									<?php
										echo $prompt ? sprintf(
											'<div style="word-break:break-word;">%s</div>',
											esc_html( $prompt )
										) : '';
									?>
									</td>
								</tr>
								<?php
							}
						}
						?>
					</tbody>
			</table>
			<script>
				jQuery('table.wpsc-ai-logs').DataTable({
					ordering: false,
					pageLength: 20,
					bLengthChange: false,
					columnDefs: [ 
						{ targets: -1, searchable: false },
						{ targets: '_all', className: 'dt-left' }
					],
					language: supportcandy.translations.datatables,
				});
			</script>
			<?php
			wp_die();
		}

		/**
		 * Delete AI logs
		 *
		 * @return void
		 */
		public static function delete_ai_logs() {

			$tz = wp_timezone();
			$today = new DateTime( 'now', $tz );

			// Get auto delete time and unit from setting.
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			$unit = isset( $ai_settings['auto-delete-ai-logs-unit'] ) ? $ai_settings['auto-delete-ai-logs-unit'] : 'year';
			$time = isset( $ai_settings['auto-delete-ai-logs-time'] ) ? $ai_settings['auto-delete-ai-logs-time'] : 1;
			if ( $time === 0 ) {
				return;
			}

			// Find the date after which tickets should be archived.
			$age = clone $today;
			switch ( $unit ) {
				case 'days':
					$age->sub( new DateInterval( 'P' . $time . 'D' ) );
					break;

				case 'month':
					$age->sub( new DateInterval( 'P' . $time . 'M' ) );
					break;

				case 'year':
					$age->sub( new DateInterval( 'P' . $time . 'Y' ) );
					break;
			}

			$logs = WPSC_PS_AI_Logs::find(
				array(
					'items_per_page' => 20,
					'orderby'        => 'date_created',
					'order'          => 'ASC',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'date_created',
							'compare' => '<',
							'val'     => $age->format( 'Y-m-d' ),
						),
					),
				)
			);

			if ( $logs['total_items'] > 0 ) {
				foreach ( $logs['results'] as $log ) {
					WPSC_PS_AI_Logs::destroy( $log );
				}
			}

			if ( $logs['has_next_page'] ) {
				wp_schedule_single_event( time(), 'wpsc_delete_aia_logs' );
			} else {
				wp_schedule_single_event( time() + DAY_IN_SECONDS, 'wpsc_delete_aia_logs' );
			}
		}
	}
endif;
WPSC_PS_AI_Setting_Logs::init();
