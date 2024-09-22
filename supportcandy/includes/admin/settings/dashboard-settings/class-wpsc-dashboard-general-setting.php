<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Dashboard_General_Setting' ) ) :

	final class WPSC_Dashboard_General_Setting {

		/**
		 * List of allowed custom field types in report
		 *
		 * @var array
		 */
		public static $ignore_cft = array();

		/**
		 * Initialize this class
		 */
		public static function init() {

			add_action( 'init', array( __CLASS__, 'ignore_dbw_cf' ) );

			// General settings widget.
			add_action( 'wp_ajax_wpsc_get_dashboard_general_settings', array( __CLASS__, 'get_dashboard_general_widgets' ) );
			add_action( 'wp_ajax_wpsc_set_dashboard_general_settings', array( __CLASS__, 'save_settings' ) );
			add_action( 'wp_ajax_wpsc_reset_dashboard_general_settings', array( __CLASS__, 'reset_settings' ) );
		}

		/**
		 * Reset settings
		 *
		 * @return void
		 */
		public static function reset() {

			$gs = apply_filters(
				'wpsc_db_gs_settings',
				array(
					'default-date-range'           => 'last-week',
					'allowed-recent-activity-logs' => array( 'report', 'reply', 'note', 'assigned_agent' ),
					'dash-auto-refresh'            => 0,
				)
			);
			update_option( 'wpsc-db-gs-settings', $gs );
		}

		/**
		 * List of ignored widgets in reports
		 *
		 * @return void
		 */
		public static function ignore_dbw_cf() {

			self::$ignore_cft = apply_filters(
				'wpsc_recent_activities_ignore_cf',
				array(
					'cf_html',
					'df_ip_address',
					'df_browser',
					'df_prev_assignee',
					'df_date_closed',
					'df_date_created',
					'df_date_updated',
					'df_add_recipients',
					'df_agent_created',
					'df_customer_email',
					'df_customer_name',
					'df_customer',
					'df_description',
					'df_id',
					'df_ip_address',
					'df_source',
					'df_tags',
					'df_os',
					'df_sla',
					'df_last_reply_on',
					'df_last_reply_by',
					'df_sf_date',
					'df_sf_feedback',
					'df_sf_rating',
					'df_time_spent',
					'df_usergroups',
					'df_user_type',
					'cf_edd_order',
					'cf_edd_product',
					'cf_woo_order',
					'cf_woo_subscription',
					'cf_woo_product',
					'cf_tutor_lms',
					'cf_tutor_order',
					'cf_learnpress_lms',
					'cf_learnpress_order',
					'cf_lifter_lms',
					'cf_lifter_order',
				)
			);
		}

		/**
		 * Load ticket widgets
		 */
		public static function get_dashboard_general_widgets() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$db_gs = get_option( 'wpsc-db-gs-settings', array() );
			ob_start();
			?>
			<div class="wpsc-dock-container">
				<?php
				printf(
					/* translators: Click here to see the documentation */
					esc_attr__( '%s to see the documentation!', 'supportcandy' ),
					'<a href="https://supportcandy.net/docs/general-3/" target="_blank">' . esc_attr__( 'Click here', 'supportcandy' ) . '</a>'
				);
				?>
			</div>
			<form action="#" onsubmit="return false;" class="wpsc-frm-db-gs">
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Default date range', 'supportcandy' ); ?></label>
					</div>
					<select name="default-date-range" id="wpsc-ddr">
						<option <?php selected( $db_gs['default-date-range'], 'today' ); ?> value="today"><?php esc_attr_e( 'Today', 'supportcandy' ); ?></option>
						<option <?php selected( $db_gs['default-date-range'], 'yesterday' ); ?> value="yesterday"><?php esc_attr_e( 'Yesterday', 'supportcandy' ); ?></option>
						<option <?php selected( $db_gs['default-date-range'], 'this-week' ); ?> value="this-week"><?php esc_attr_e( 'This week', 'supportcandy' ); ?></option>
						<option <?php selected( $db_gs['default-date-range'], 'last-week' ); ?> value="last-week"><?php esc_attr_e( 'Last week', 'supportcandy' ); ?></option>
						<option <?php selected( $db_gs['default-date-range'], 'last-30-days' ); ?> value="last-30-days"><?php esc_attr_e( 'Last 30 days', 'supportcandy' ); ?></option>
						<option <?php selected( $db_gs['default-date-range'], 'this-month' ); ?> value="this-month"><?php esc_attr_e( 'This month', 'supportcandy' ); ?></option>
						<option <?php selected( $db_gs['default-date-range'], 'last-month' ); ?> value="last-month"><?php esc_attr_e( 'Last month', 'supportcandy' ); ?></option>
					</select>
					<script>
						jQuery('#wpsc-ddr').selectWoo({
							allowClear: true,
							placeholder: ""
						});
					</script>
				</div>
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Allowed recent activity logs', 'supportcandy' ); ?></label>
					</div>
					<select id="wpsc-allowed-recent-activity-logs"  multiple name="allowed-recent-activity-logs[]">
						<option <?php echo esc_attr( in_array( 'report', $db_gs['allowed-recent-activity-logs'] ) ? 'selected' : '' ); ?> value="report"><?php esc_attr_e( 'Report', 'supportcandy' ); ?></option>
						<option <?php echo esc_attr( in_array( 'reply', $db_gs['allowed-recent-activity-logs'] ) ? 'selected' : '' ); ?> value="reply"><?php esc_attr_e( 'Reply', 'supportcandy' ); ?></option>
						<option <?php echo esc_attr( in_array( 'note', $db_gs['allowed-recent-activity-logs'] ) ? 'selected' : '' ); ?> value="note"><?php esc_attr_e( 'Note', 'supportcandy' ); ?></option>
						<?php
						foreach ( WPSC_Custom_Field::$custom_fields as $cf ) {
							if ( $cf->field != 'ticket' || in_array( $cf->type::$slug, self::$ignore_cft ) ) {
								continue;
							}
							$selected = in_array( $cf->slug, $db_gs['allowed-recent-activity-logs'] ) ? 'selected' : '';
							?>
							<option <?php echo esc_attr( $selected ); ?> value="<?php echo esc_attr( $cf->slug ); ?>"><?php echo esc_attr( $cf->name ); ?></option>
							<?php
						}
						?>
					</select>
					<script>
						jQuery('#wpsc-allowed-recent-activity-logs').selectWoo({
							allowClear: true,
							placeholder: ""
						});
					</script>
				</div>
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Dashboard auto refresh', 'supportcandy' ); ?></label>
					</div>
					<select id="wpsc-dash-auto-refresh" name="wpsc-dash-auto-refresh">
						<option <?php selected( $db_gs['dash-auto-refresh'], '1' ); ?> value="1"><?php esc_attr_e( 'On', 'supportcandy' ); ?></option>
						<option <?php selected( $db_gs['dash-auto-refresh'], '0' ); ?> value="0"><?php esc_attr_e( 'Off', 'supportcandy' ); ?></option>
					</select>
				</div>
				<?php do_action( 'wpsc_db_gs_settings' ); ?>
				<input type="hidden" name="action" value="wpsc_set_dashboard_general_settings">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_dashboard_general_settings' ) ); ?>">
			</form>
			<div class="setting-footer-actions">
				<button 
					class="wpsc-button normal primary margin-right"
					onclick="wpsc_set_dashboard_general_settings(this);">
					<?php esc_attr_e( 'Submit', 'supportcandy' ); ?></button>
				<button 
					class="wpsc-button normal secondary"
					onclick="wpsc_reset_dashboard_general_settings(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_reset_dashboard_general_settings' ) ); ?>');">
					<?php esc_attr_e( 'Reset default', 'supportcandy' ); ?></button>
			</div>
			<?php
			wp_die();
		}

		/**
		 * Save settings
		 *
		 * @return void
		 */
		public static function save_settings() {

			if ( check_ajax_referer( 'wpsc_set_dashboard_general_settings', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$gs = apply_filters(
				'wpsc_set_db_gs_settings',
				array(
					'default-date-range'           => isset( $_POST['default-date-range'] ) ? sanitize_text_field( wp_unslash( $_POST['default-date-range'] ) ) : 'last-week',
					'allowed-recent-activity-logs' => isset( $_POST['allowed-recent-activity-logs'] ) ? array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['allowed-recent-activity-logs'] ) ) ) : array(),
					'dash-auto-refresh'            => isset( $_POST['wpsc-dash-auto-refresh'] ) ? intval( $_POST['wpsc-dash-auto-refresh'] ) : 0,
				)
			);
			update_option( 'wpsc-db-gs-settings', $gs );
			wp_die();
		}

		/**
		 * Reset settings to default
		 *
		 * @return void
		 */
		public static function reset_settings() {

			if ( check_ajax_referer( 'wpsc_reset_dashboard_general_settings', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}
			self::reset();
			wp_die();
		}
	}

endif;

WPSC_Dashboard_General_Setting::init();
