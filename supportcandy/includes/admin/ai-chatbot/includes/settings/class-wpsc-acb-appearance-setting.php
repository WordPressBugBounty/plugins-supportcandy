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
		 * WPML String Translation context that all chatbot appearance text strings
		 * are registered under, so WPML shows them together under one group and
		 * `wpml_translate_single_string` can look them up by name.
		 *
		 * @var string
		 */
		const WPML_CONTEXT = 'wpsc-ai-chatbot';

		/**
		 * Get chatbot appearance settings, merged with defaults so older saved
		 * options (from before header/greeting text existed) keep working.
		 *
		 * @return array
		 */
		public static function get_appearance_settings() {

			$settings = wp_parse_args( get_option( 'wpsc-acb-appearance-general', array() ), self::get_defaults() );

			// Defensive re-registration on every read (not only on save/reset) so a
			// site imported/restored straight into the options table - never passing
			// through save_settings() - still has these strings registered with WPML.
			self::register_string( 'Chatbot header text', $settings['header-text'] );
			self::register_string( 'Chatbot greeting message', $settings['greeting-text'] );
			self::register_string( 'Chatbot warning message', $settings['warning-text'] );

			return $settings;
		}

		/**
		 * Default appearance settings.
		 *
		 * @return array
		 */
		private static function get_defaults() {

			return array(
				'background-color' => '#2271b1',
				'icon-color'       => '#ffffff',
				'header-text'      => __( 'AI Chatbot', 'supportcandy' ),
				'greeting-text'    => __( 'Hey, I\'m your assistant. How can I help you today?', 'supportcandy' ),
				'warning-text'     => __( 'AI can make mistakes. Check important info.', 'supportcandy' ),
			);
		}

		/**
		 * Register a source-language string with WPML String Translation, so it
		 * shows up for translators under WPML > String Translation. No-op when
		 * WPML (or its String Translation add-on) isn't active.
		 *
		 * @param string $name  Unique-per-context string name.
		 * @param string $value Current source-language value.
		 * @return void
		 */
		private static function register_string( $name, $value ) {

			if ( has_action( 'wpml_register_single_string' ) ) {
				do_action( 'wpml_register_single_string', self::WPML_CONTEXT, $name, $value );
			}
		}

		/**
		 * Get the translation of an appearance text string for the current
		 * language, via WPML String Translation. Falls back to the given
		 * (source-language) value untouched when WPML isn't active or no
		 * translation exists yet.
		 *
		 * @param string $name  Unique-per-context string name, matching register_string().
		 * @param string $value Source-language value.
		 * @return string
		 */
		public static function translate( $name, $value ) {

			if ( has_filter( 'wpml_translate_single_string' ) ) {
				return apply_filters( 'wpml_translate_single_string', $value, self::WPML_CONTEXT, $name );
			}

			return $value;
		}

		/**
		 * Reset default settings
		 *
		 * @return void
		 */
		public static function reset() {

			$defaults = self::get_defaults();

			update_option( 'wpsc-acb-appearance-general', $defaults );

			self::register_string( 'Chatbot header text', $defaults['header-text'] );
			self::register_string( 'Chatbot greeting message', $defaults['greeting-text'] );
			self::register_string( 'Chatbot warning message', $defaults['warning-text'] );
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
			$settings = self::get_appearance_settings();
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
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-acb-header-text"><?php esc_attr_e( 'Chatbot header text', 'supportcandy' ); ?></label>
					</div>
					<input type="text" id="wpsc-acb-header-text" name="header-text" value="<?php echo esc_attr( $settings['header-text'] ); ?>" maxlength="60">
					<span class="extra-info">
						<?php esc_attr_e( 'Shown in the chat window header, e.g. "AI Chatbot". To translate this for other languages, use WPML > String Translation (context: wpsc-ai-chatbot).', 'supportcandy' ); ?>
					</span>
				</div>
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-acb-greeting-text"><?php esc_attr_e( 'Chatbot greeting message', 'supportcandy' ); ?></label>
					</div>
					<textarea id="wpsc-acb-greeting-text" name="greeting-text" rows="3" maxlength="500"><?php echo esc_textarea( $settings['greeting-text'] ); ?></textarea>
					<span class="extra-info">
						<?php esc_attr_e( 'The assistant\'s first message, shown when a chat starts and in the greeting bubble. To translate this for other languages, use WPML > String Translation (context: wpsc-ai-chatbot).', 'supportcandy' ); ?>
					</span>
				</div>
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-acb-warning-text"><?php esc_attr_e( 'Chatbot warning message', 'supportcandy' ); ?></label>
					</div>
					<input type="text" id="wpsc-acb-warning-text" name="warning-text" value="<?php echo esc_attr( $settings['warning-text'] ); ?>" maxlength="150">
					<span class="extra-info">
						<?php esc_attr_e( 'Small disclaimer shown above the message box, e.g. "AI can make mistakes. Check important info." To translate this for other languages, use WPML > String Translation (context: wpsc-ai-chatbot).', 'supportcandy' ); ?>
					</span>
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

			if ( ! check_ajax_referer( 'wpsc_set_acb_appearance_setting', '_ajax_nonce', false ) ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$background_color = isset( $_POST['background-color'] ) ? sanitize_text_field( wp_unslash( $_POST['background-color'] ) ) : '';
			$icon_color = isset( $_POST['icon-color'] ) ? sanitize_text_field( wp_unslash( $_POST['icon-color'] ) ) : '';
			$header_text = isset( $_POST['header-text'] ) ? sanitize_text_field( wp_unslash( $_POST['header-text'] ) ) : '';
			$greeting_text = isset( $_POST['greeting-text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['greeting-text'] ) ) : '';
			$warning_text = isset( $_POST['warning-text'] ) ? sanitize_text_field( wp_unslash( $_POST['warning-text'] ) ) : '';

			if ( ! $background_color || ! $icon_color || ! $header_text || ! $greeting_text ) {
				wp_send_json_error( 'Bad request', 400 );
			}

			update_option(
				'wpsc-acb-appearance-general',
				array(
					'background-color' => $background_color,
					'icon-color'       => $icon_color,
					'header-text'      => $header_text,
					'greeting-text'    => $greeting_text,
					'warning-text'     => $warning_text,
				)
			);

			// Keep WPML String Translation's source strings in sync with what the
			// admin just saved, so translators always see the current text.
			self::register_string( 'Chatbot header text', $header_text );
			self::register_string( 'Chatbot greeting message', $greeting_text );
			self::register_string( 'Chatbot warning message', $warning_text );

			wp_die();
		}

		/**
		 * Reset settings to default
		 *
		 * @return void
		 */
		public static function reset_settings() {

			if ( ! check_ajax_referer( 'wpsc_reset_acb_appearance_setting', '_ajax_nonce', false ) ) {
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
