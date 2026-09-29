<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Chatbot_Welcome' ) ) :

	final class WPSC_Chatbot_Welcome {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// get_welcome_template() is only ever needed on demand, via
			// WPSC_ACB_Admin::frontend_config() - no separate 'init' hook call needed.
		}

		/**
		 * Get chatbot greeting/welcome message text.
		 *
		 * Single source for the assistant's greeting - reused by the welcome
		 * message bubble inside an open chat, the initial page-load markup, and
		 * the auto-popup greeting tooltip anchored to the launcher, so they never
		 * drift out of sync. The admin-configured value (Appearance settings)
		 * is run through WPML String Translation, falling back to the plugin's
		 * default text (and plain WP translation) when nothing's been saved yet.
		 *
		 * @return string
		 */
		public static function get_greeting_text() {

			$appearance_settings = WPSC_ACB_Appearance_Setting::get_appearance_settings();
			return WPSC_ACB_Appearance_Setting::translate( 'Chatbot greeting message', $appearance_settings['greeting-text'] );
		}

		/**
		 * Get chatbot welcome template
		 *
		 * @return string
		 */
		public static function get_welcome_template() {

			ob_start();
			?>
			<div class="wpsc-chatbot__system__message">
				<?php echo esc_html( self::get_greeting_text() ); ?>
				<div class="wpsc-chatbot__message-meta">
					<span><?php esc_html_e( 'Assistant', 'supportcandy' ); ?></span>
					<span class="wpsc-chatbot__welcome-time"></span>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}
	}
endif;
WPSC_Chatbot_Welcome::init();
