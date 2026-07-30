<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_General_Setting' ) ) :

	final class WPSC_ACB_General_Setting {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// List.
			add_action( 'wp_ajax_wpsc_get_acb_general_setting', array( __CLASS__, 'get_acb_general_setting' ) );

			// Save, reset & test settings.
			add_action( 'wp_ajax_wpsc_set_acb_settings', array( __CLASS__, 'save_settings' ) );
			add_action( 'wp_ajax_wpsc_reset_acb_settings', array( __CLASS__, 'reset_settings' ) );
		}

		/**
		 * Get AI ChatBot general setting
		 *
		 * @return void
		 */
		public static function get_acb_general_setting() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( empty( $acb_settings ) ) {
				wp_send_json_error( __( 'Something went wrong.', 'wpsc-ps' ), 404 );
			}

			if ( ! isset( $acb_settings['status'] ) ) {
				$acb_settings['status'] = '0';
			}
			?>
			<form action="#" onsubmit="return false;" class="wpsc-frm-acb-settings">
				<div class="wpsc-dock-container">
					<?php
					printf(
						/* translators: Click here to see the documentation */
						esc_attr__( '%s to see the documentation!', 'supportcandy' ),
						'<a href="https://supportcandy.net/docs/chatbot-general/" target="_blank">' . esc_attr__( 'Click here', 'supportcandy' ) . '</a>'
					);
					?>
				</div>

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-acb-service-status"><?php esc_attr_e( 'AI Chatbot Status', 'wpsc-ps' ); ?></label>
					</div>
					<select id="wpsc-acb-service-status" name="acb-service-status" style="max-width: 250px;">
						<option value="1" <?php selected( $acb_settings['status'], '1' ); ?>><?php esc_html_e( 'Enable', 'wpsc-ps' ); ?></option>
						<option value="0" <?php selected( $acb_settings['status'], '0' ); ?>><?php esc_html_e( 'Disable', 'wpsc-ps' ); ?></option>
					</select>
					<span class="extra-info">
						<?php esc_attr_e( 'Enable this to power the AI chatbot on your website.', 'wpsc-ps' ); ?>
					</span>
				</div>
				

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-acb-delete-session"><?php esc_attr_e( 'Auto delete AI chatbot sessions', 'wpsc-ps' ); ?></label>
					</div>
					<div class="divide-bar">
						<input type="number" class="wpsc-acb-delete-session" id="wpsc-acb-delete-session" name="delete-acb-session-time" value="<?php echo esc_attr( $acb_settings['delete-acb-session-time'] ); ?>" style="max-width: 100px;">
						<select id="wpsc-acb-auto-delete-logs-unit" name="delete-acb-session-unit" class="wpsc-acb-auto-delete-logs-unit" style="max-width: 250px;">
							<option <?php selected( $acb_settings['delete-acb-session-unit'], 'days' ); ?> value="days"><?php esc_attr_e( 'Day(s)', 'wpsc-ps' ); ?></option>
							<option <?php selected( $acb_settings['delete-acb-session-unit'], 'month' ); ?> value="month"><?php esc_attr_e( 'Month(s)', 'wpsc-ps' ); ?></option>
							<option <?php selected( $acb_settings['delete-acb-session-unit'], 'year' ); ?> value="year"><?php esc_attr_e( 'Year(s)', 'wpsc-ps' ); ?></option>
						</select>
					</div>
					<span class="extra-info">
						<?php esc_attr_e( 'Specify the duration after which AI chatbot logs should be automatically deleted.', 'wpsc-ps' ); ?>
					</span>
				</div>

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-acb-chat-show-footer-branding"><?php esc_attr_e( 'Show footer branding', 'wpsc-ps' ); ?></label>
					</div>
					<div class="divide-bar">
						<select id="wpsc-acb-chat-show-footer-branding" name="show-footer-branding" class="wpsc-acb-chat-show-footer-branding" style="max-width: 250px;">
							<option <?php selected( $acb_settings['show-footer-branding'], '1' ); ?> value="1"><?php esc_attr_e( 'Yes', 'wpsc-ps' ); ?></option>
							<option <?php selected( $acb_settings['show-footer-branding'], '0' ); ?> value="0"><?php esc_attr_e( 'No', 'wpsc-ps' ); ?></option>
						</select>
					</div>
					<span class="extra-info">
						<?php esc_attr_e( 'Display the "Powered by SupportCandy" message in the chatbot footer.', 'wpsc-ps' ); ?>
					</span>
				</div>

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-acb-sessions-per-page"><?php esc_attr_e( 'Number of sessions per page', 'wpsc-ps' ); ?></label>
					</div>
					<input type="number" id="wpsc-acb-sessions-per-page" name="sessions-per-page" value="<?php echo esc_attr( $acb_settings['sessions-per-page'] ); ?>" min="1" placeholder="<?php esc_attr_e( 'Enter number', 'wpsc-ps' ); ?> " style="max-width: 100px;" />
					<span class="extra-info">
						<?php esc_attr_e( 'Set the number of chat sessions to display per page.', 'wpsc-ps' ); ?>
					</span>
				</div>
				<input type="hidden" name="action" value="wpsc_set_acb_settings">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_acb_settings' ) ); ?>">
			
				<div class="setting-footer-actions">
					<button 
						class="wpsc-button normal primary margin-right"
						onclick="wpsc_set_acb_settings(this);">
						<?php esc_attr_e( 'Submit', 'wpsc-ps' ); ?>
					</button>
					<button 
						class="wpsc-button normal secondary margin-right"
						onclick="wpsc_reset_acb_settings(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_reset_acb_settings' ) ); ?>');">
						<?php esc_attr_e( 'Reset', 'wpsc-ps' ); ?>
					</button>
				</div>
			</form>
			<?php
			if ( ! $ai_settings['is-active'] ) {
				?>
				<div style="margin-top: 15px; color: #ff0000;"><?php esc_html_e( 'Your AI provider is not connected. Please connect AI provider to use AI chatbot', 'wpsc-ps' ); ?></div>
				<?php
			}
			?>
			<?php
			wp_die();
		}

		/**
		 * Save AI assistant settings
		 *
		 * @return void
		 */
		public static function save_settings() {

			if ( check_ajax_referer( 'wpsc_set_acb_settings', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );

			$status = isset( $_POST['acb-service-status'] ) ? sanitize_text_field( wp_unslash( $_POST['acb-service-status'] ) ) : '';
			if ( '' === $status || ! in_array( $status, array( '1', '0' ), true ) ) {
				wp_send_json_error( __( 'Invalid or missing AI chatbot status!', 'wpsc-ps' ), 400 );
			}

			$retention_policy_time = isset( $_POST['delete-acb-session-time'] ) ? intval( $_POST['delete-acb-session-time'] ) : 0;
			if ( $retention_policy_time < 0 ) {
				wp_send_json_error( __( 'Retention time must be zero or a positive integer.', 'wpsc-ps' ), 400 );
			}

			$retention_policy_unit = isset( $_POST['delete-acb-session-unit'] ) ? sanitize_text_field( wp_unslash( $_POST['delete-acb-session-unit'] ) ) : '';
			if ( empty( $retention_policy_unit ) ) {
				wp_send_json_error( __( 'Invalid or missing retention policy unit!', 'wpsc-ps' ), 400 );
			}

			$show_footer_branding = isset( $_POST['show-footer-branding'] ) ? intval( $_POST['show-footer-branding'] ) : 1;
			if ( $show_footer_branding !== 0 && $show_footer_branding !== 1 ) {
				wp_send_json_error( __( 'Invalid or missing footer branding option!', 'wpsc-ps' ), 400 );
			}

			$sessions_per_page = isset( $_POST['sessions-per-page'] ) ? sanitize_text_field( wp_unslash( $_POST['sessions-per-page'] ) ) : '';
			if ( empty( $sessions_per_page ) || ! is_numeric( $sessions_per_page ) || $sessions_per_page < 1 ) {
				wp_send_json_error( __( 'Invalid or missing sessions per page!', 'wpsc-ps' ), 400 );
			}

			$acb_settings = array(
				'status'                  => $status,
				'delete-acb-session-time' => $retention_policy_time,
				'delete-acb-session-unit' => $retention_policy_unit,
				'show-footer-branding'    => $show_footer_branding,
				'sessions-per-page'       => $sessions_per_page,
			);
			update_option( 'wpsc-ps-acb-chatbot-settings', $acb_settings );

			wp_send_json_success(
				array(
					'message' => __( 'Settings saved successfully.', 'wpsc-ps' ),
				)
			);
			wp_die();
		}

		/**
		 * Reset AI chatbot settings
		 *
		 * @return void
		 */
		public static function reset_settings() {

			if ( check_ajax_referer( 'wpsc_reset_acb_settings', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			update_option(
				'wpsc-ps-acb-chatbot-settings',
				array(
					'status'                  => '0',
					'delete-acb-session-time' => 1,
					'delete-acb-session-unit' => 'year',
					'show-footer-branding'    => '1',
					'sessions-per-page'       => 30,
				)
			);

			wp_send_json_success(
				array(
					'message' => __( 'Settings reset successfully.', 'wpsc-ps' ),
				)
			);
			wp_die();
		}
	}
endif;
WPSC_ACB_General_Setting::init();
