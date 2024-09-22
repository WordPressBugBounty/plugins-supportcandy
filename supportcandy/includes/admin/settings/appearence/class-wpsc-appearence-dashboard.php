<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Appearence_Dashboard' ) ) :

	final class WPSC_Appearence_Dashboard {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// user interface.
			add_action( 'wp_ajax_wpsc_get_ap_dashboard', array( __CLASS__, 'load_settings_ui' ) );
			add_action( 'wp_ajax_wpsc_set_ap_dashboard', array( __CLASS__, 'save_settings' ) );
			add_action( 'wp_ajax_wpsc_reset_ap_dashboard', array( __CLASS__, 'reset_settings' ) );
		}

		/**
		 * Reset settings
		 *
		 * @return void
		 */
		public static function reset() {

			update_option(
				'wpsc-ap-dashboard',
				array(
					'card-body-bg-color'     => '#f9f9f9',
					'card-body-svg-color'    => '#777',
					'card-body-text-color'   => '#2c3e50',
					'widget-body-bg-color'   => '#f9f9f9',
					'widget-body-svg-color'  => '#777',
					'widget-body-text-color' => '#2c3e50',

				)
			);
		}

		/**
		 * Get general settings
		 *
		 * @return void
		 */
		public static function load_settings_ui() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$settings = get_option( 'wpsc-ap-dashboard' );
			?>
			<form action="#" onsubmit="return false;" class="wpsc-frm-ap-dash">

				<div class="wpsc-input-group">
						<div class="label-container">
							<label for=""><?php esc_attr_e( 'Cards', 'supportcandy' ); ?></label>
						</div>
						<table class="wpsc-ap-table">
							<tr>
								<td><?php esc_attr_e( 'Background color', 'supportcandy' ); ?></td>
								<td><?php esc_attr_e( 'Text color', 'supportcandy' ); ?></td>
								<td><?php esc_attr_e( 'Svg color', 'supportcandy' ); ?></td>
							</tr>
							<tr>
								<td><input class="wpsc-color-picker" type="text" name="card-body-bg-color" value="<?php echo esc_attr( $settings['card-body-bg-color'] ); ?>"></td>
								<td><input class="wpsc-color-picker" type="text" name="card-body-text-color" value="<?php echo esc_attr( $settings['card-body-text-color'] ); ?>"></td>
								<td><input class="wpsc-color-picker" type="text" name="card-body-svg-color" value="<?php echo esc_attr( $settings['card-body-svg-color'] ); ?>"></td>
							</tr>
						</table>
				</div>

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Widget', 'supportcandy' ); ?></label>
					</div>
					<table class="wpsc-ap-table">
						<tr>
							<td><?php esc_attr_e( 'Background color', 'supportcandy' ); ?></td>
							<td><?php esc_attr_e( 'Text color', 'supportcandy' ); ?></td>
							<td><?php esc_attr_e( 'Svg color', 'supportcandy' ); ?></td>
						</tr>
						<tr>
							<td><input class="wpsc-color-picker" type="text" name="widget-body-bg-color" value="<?php echo esc_attr( $settings['widget-body-bg-color'] ); ?>"></td>
							<td><input class="wpsc-color-picker" type="text" name="widget-body-text-color" value="<?php echo esc_attr( $settings['widget-body-text-color'] ); ?>"></td>
							<td><input class="wpsc-color-picker" type="text" name="widget-body-svg-color" value="<?php echo esc_attr( $settings['widget-body-svg-color'] ); ?>"></td>
						</tr>
					</table>
				</div>

				<input type="hidden" name="action" value="wpsc_set_ap_dashboard">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_ap_dashboard' ) ); ?>">

				<script>jQuery('.wpsc-color-picker').wpColorPicker();</script>
			</form>
			<div class="setting-footer-actions">
				<button 
					class="wpsc-button normal primary margin-right"
					onclick="wpsc_set_ap_dashboard(this);">
					<?php esc_attr_e( 'Submit', 'supportcandy' ); ?></button>
				<button 
					class="wpsc-button normal secondary"
					onclick="wpsc_reset_ap_dashboard(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_reset_ap_dashboard' ) ); ?>');">
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

			if ( check_ajax_referer( 'wpsc_set_ap_dashboard', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			update_option(
				'wpsc-ap-dashboard',
				array(
					'widget-body-bg-color'   => isset( $_POST['widget-body-bg-color'] ) ? sanitize_text_field( wp_unslash( $_POST['widget-body-bg-color'] ) ) : '#f9f9f9',
					'widget-body-svg-color'  => isset( $_POST['widget-body-svg-color'] ) ? sanitize_text_field( wp_unslash( $_POST['widget-body-svg-color'] ) ) : '#777',
					'widget-body-text-color' => isset( $_POST['widget-body-text-color'] ) ? sanitize_text_field( wp_unslash( $_POST['widget-body-text-color'] ) ) : '#2c3e50',
					'card-body-bg-color'     => isset( $_POST['card-body-bg-color'] ) ? sanitize_text_field( wp_unslash( $_POST['card-body-bg-color'] ) ) : '#f9f9f9',
					'card-body-svg-color'    => isset( $_POST['card-body-svg-color'] ) ? sanitize_text_field( wp_unslash( $_POST['card-body-svg-color'] ) ) : '#777',
					'card-body-text-color'   => isset( $_POST['card-body-text-color'] ) ? sanitize_text_field( wp_unslash( $_POST['card-body-text-color'] ) ) : '#2c3e50',
				)
			);
			wp_die();
		}

		/**
		 * Reset settings to default
		 *
		 * @return void
		 */
		public static function reset_settings() {

			if ( check_ajax_referer( 'wpsc_reset_ap_dashboard', '_ajax_nonce', false ) != 1 ) {
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
WPSC_Appearence_Dashboard::init();
