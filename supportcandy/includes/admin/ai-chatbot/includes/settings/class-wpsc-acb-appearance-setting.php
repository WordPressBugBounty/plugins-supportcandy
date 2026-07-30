<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Appearance_Setting' ) ) :

	final class WPSC_ACB_Appearance_Setting {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// user interface.
			add_action( 'wp_ajax_wpsc_get_acb_appearance_setting', array( __CLASS__, 'load_settings_ui' ) );
			add_action( 'wp_ajax_wpsc_set_acb_appearance_setting', array( __CLASS__, 'save_settings' ) );
			add_action( 'wp_ajax_wpsc_reset_acb_appearance_setting', array( __CLASS__, 'reset_settings' ) );
		}

		/**
		 * Reset default settings
		 *
		 * @return void
		 */
		public static function reset() {

			update_option(
				'wpsc-acb-appearance-general',
				array(
					'background-color' => '#2271b1',
					'icon-color'       => '#ffffff',
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
			$settings = get_option( 'wpsc-acb-appearance-general' );
			?>
			<form action="#" onsubmit="return false;" class="wpsc-frm-acb-appearance-general">
				<div class="wpsc-dock-container">
					<?php
					printf(
						/* translators: Click here to see the documentation */
						esc_attr__( '%s to see the documentation!', 'supportcandy' ),
						'<a href="https://supportcandy.net/docs/ai-chatbot-appearance/" target="_blank">' . esc_attr__( 'Click here', 'supportcandy' ) . '</a>'
					);
					?>
				</div>
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Chatbot launcher background color', 'supportcandy' ); ?></label>
					</div>
					<input class="wpsc-color-picker" type="text" name="background-color" value="<?php echo esc_attr( $settings['background-color'] ); ?>">
				</div>
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Chatbot launcher icon color', 'supportcandy' ); ?></label>
					</div>
					<input class="wpsc-color-picker" type="text" name="icon-color" value="<?php echo esc_attr( $settings['icon-color'] ); ?>">
				</div>

				<input type="hidden" name="action" value="wpsc_set_acb_appearance_setting">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_acb_appearance_setting' ) ); ?>">
				<script>jQuery('.wpsc-color-picker').wpColorPicker();</script>
			</form>
			<div class="setting-footer-actions">
				<button 
					class="wpsc-button normal primary margin-right"
					onclick="wpsc_set_acb_appearance_setting(this);">
					<?php esc_attr_e( 'Submit', 'supportcandy' ); ?></button>
				<button 
					class="wpsc-button normal secondary"
					onclick="wpsc_reset_acb_appearance_setting(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_reset_acb_appearance_setting' ) ); ?>');">
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

			if ( check_ajax_referer( 'wpsc_set_acb_appearance_setting', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$background_color = isset( $_POST['background-color'] ) ? sanitize_text_field( wp_unslash( $_POST['background-color'] ) ) : '';
			$icon_color = isset( $_POST['icon-color'] ) ? sanitize_text_field( wp_unslash( $_POST['icon-color'] ) ) : '';

			if ( ! $background_color || ! $icon_color ) {
				wp_send_json_error( 'Bad request', 400 );
			}

			update_option(
				'wpsc-acb-appearance-general',
				array(
					'background-color' => $background_color,
					'icon-color'       => $icon_color,
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

			if ( check_ajax_referer( 'wpsc_reset_acb_appearance_setting', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}
			self::reset();
			wp_die();
		}
	}
endif;

WPSC_ACB_Appearance_Setting::init();
