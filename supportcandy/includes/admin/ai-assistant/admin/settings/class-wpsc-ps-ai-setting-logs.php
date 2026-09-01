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
			add_action( 'wp_ajax_wpsc_get_aia_logs_data', array( __CLASS__, 'get_ai_logs_data' ) );
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
		 * Load the AI Logs tab: table skeleton plus a server-side-paginated DataTable
		 * initialization. The one-time initial filter (if any) is baked into the
		 * ajax.data sent with every subsequent page/draw request - see get_ai_logs_data().
		 *
		 * @return void
		 */
		public static function get_ai_logs() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$agent_id = '';
			$from_date = '';
			$to_date = '';

			if ( isset( $_POST['filter'] ) && is_array( $_POST['filter'] ) ) { // phpcs:ignore
				$filter_raw = wp_unslash( $_POST['filter'] ); // phpcs:ignore
				$filter     = array_map( 'sanitize_text_field', $filter_raw );

				if ( ! empty( $filter['agent_id'] ) ) {
					$agent_id = $filter['agent_id'];
				}

				if ( ! empty( $filter['date_range'] ) ) {
					$date_range = WPSC_Functions::get_dashboard_date_range( $filter['date_range'] );
					$from_date  = $date_range[0];
					$to_date    = $date_range[1];
				}
			}

			$nonce = wp_create_nonce( 'wpsc_get_aia_logs_data' );
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
				<tbody></tbody>
			</table>
			<script>
				jQuery('table.wpsc-ai-logs').DataTable({
					processing: true,
					serverSide: true,
					serverMethod: 'post',
					ajax: {
						url: supportcandy.ajax_url,
						data: {
							action: 'wpsc_get_aia_logs_data',
							_ajax_nonce: '<?php echo esc_js( $nonce ); ?>',
							agent_id: '<?php echo esc_js( $agent_id ); ?>',
							from_date: '<?php echo esc_js( $from_date ); ?>',
							to_date: '<?php echo esc_js( $to_date ); ?>'
						}
					},
					columns: [
						{ data: 'id' },
						{ data: 'agent' },
						{ data: 'ticket' },
						{ data: 'model' },
						{ data: 'tokens' },
						{ data: 'prompt' }
					],
					ordering: false,
					searching: false,
					bLengthChange: false,
					pageLength: 20,
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
		 * DataTables server-side data source for the AI Logs tab - called once per
		 * page/draw, unlike get_ai_logs() which only loads the tab shell once. Log rows
		 * are always included regardless of whether their ticket is still active, so the
		 * reported record counts stay exact (the generic model layer has no way to filter
		 * by a joined ticket's is_active flag at the SQL level); the Ticket column instead
		 * shows '(deleted)' when the referenced ticket no longer resolves at all.
		 *
		 * @return void
		 */
		public static function get_ai_logs_data() {

			if ( ! check_ajax_referer( 'wpsc_get_aia_logs_data', '_ajax_nonce', false ) ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$agent_id  = isset( $_POST['agent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_id'] ) ) : '';
			$from_date = isset( $_POST['from_date'] ) ? sanitize_text_field( wp_unslash( $_POST['from_date'] ) ) : '';
			$to_date   = isset( $_POST['to_date'] ) ? sanitize_text_field( wp_unslash( $_POST['to_date'] ) ) : '';

			$draw       = isset( $_POST['draw'] ) ? intval( $_POST['draw'] ) : 1;
			$start      = isset( $_POST['start'] ) ? intval( $_POST['start'] ) : 0;
			$rowperpage = isset( $_POST['length'] ) ? intval( $_POST['length'] ) : 20;
			if ( $rowperpage < 1 ) {
				$rowperpage = 20;
			}
			$page_no = ( $start / $rowperpage ) + 1;

			$meta_query = array( 'relation' => 'AND' );

			if ( ! empty( $agent_id ) ) {
				$meta_query[] = array(
					'slug'    => 'customer',
					'compare' => '=',
					'val'     => $agent_id,
				);
			}

			if ( ! empty( $from_date ) && ! empty( $to_date ) ) {
				$meta_query[] = array(
					'slug'    => 'date_created',
					'compare' => 'BETWEEN',
					'val'     => array( $from_date, $to_date ),
				);
			}

			$logs = WPSC_PS_AI_Logs::find(
				array(
					'items_per_page' => $rowperpage,
					'page_no'        => $page_no,
					'orderby'        => 'date_created',
					'order'          => 'DESC',
					'meta_query'     => $meta_query,
				)
			);

			$data = array();
			foreach ( $logs['results'] as $log ) {

				$ticket = $log->ticket;
				$prompt = '';
				$clean_prompt = trim( preg_replace( '/\s+/', ' ', (string) $log->prompt ) );
				if ( $clean_prompt !== '' ) {
					$prompt = wp_trim_words( $clean_prompt, 7, '...' );
				}

				if ( $ticket->id ) {
					$subject = isset( $ticket->subject ) ? wp_trim_words( $ticket->subject, 4, '...' ) : '';
					$ticket_cell = sprintf(
						'<a href="%s" target="_blank" style="text-decoration: none;"><div>%s</div></a>',
						esc_url( admin_url( 'admin.php?page=wpsc-tickets&section=ticket-list&id=' . $ticket->id ) ),
						esc_html( '#' . $ticket->id . ' ' . $subject )
					);
				} else {
					$ticket_cell = esc_html__( '(deleted)', 'wpsc-ps' );
				}

				$data[] = array(
					'id'     => $log->id,
					'agent'  => esc_html( $log->customer->name ),
					'ticket' => $ticket_cell,
					'model'  => esc_html( $log->model ),
					'tokens' => intval( $log->tokens ),
					'prompt' => $prompt ? sprintf( '<div style="word-break:break-word;">%s</div>', esc_html( $prompt ) ) : '',
				);
			}

			wp_send_json(
				array(
					'draw'                 => $draw,
					'iTotalRecords'        => $logs['total_items'],
					'iTotalDisplayRecords' => $logs['total_items'],
					'data'                 => $data,
				)
			);
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
