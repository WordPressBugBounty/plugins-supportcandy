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
		 * Get chatbot welcome template
		 *
		 * @return string
		 */
		public static function get_welcome_template() {

			ob_start();
			?>
			<div class="wpsc-chatbot__system__message">
				<?php esc_html_e( 'Hey, I\'m your assistant. How can I help you today?', 'wpsc-ps' ); ?>
				<div class="wpsc-chatbot__message-meta">
					<span><?php esc_html_e( 'Assistant', 'wpsc-ps' ); ?></span>
					<span class="wpsc-chatbot__welcome-time"></span>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}
	}
endif;
WPSC_Chatbot_Welcome::init();
