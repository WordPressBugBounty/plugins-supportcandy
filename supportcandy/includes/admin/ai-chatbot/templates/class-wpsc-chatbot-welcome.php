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

			add_action( 'init', array( __CLASS__, 'get_welcome_template' ), 1 );
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
					<span><?php echo esc_html( wp_date( 'H:i' ) ); ?></span>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}
	}
endif;
WPSC_Chatbot_Welcome::init();
