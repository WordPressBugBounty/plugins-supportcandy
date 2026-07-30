<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Chatbot_Ticket_Form' ) ) :

	final class WPSC_Chatbot_Ticket_Form {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			add_action( 'init', array( __CLASS__, 'get_ticket_form_template' ), 1 );
		}

		/**
		 * Get chatbot ticket form template
		 *
		 * @return string
		 */
		public static function get_ticket_form_template() {

			$cookie_name = 'wpsc_acb_session_id';
			$session_id = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';
			ob_start();
			?>
			<div class="wpsc-chatbot__ticket-form">
				<div class="wpsc-chatbot__ticket-message">
					<p><?php esc_html_e( 'Please fill out the form below.', 'wpsc-ps' ); ?></p>
				</div>
				<div class="wpsc-chatbot__field">
					<input type="text" id="wpsc-chatbot-ticket-form-name" class="wpsc-chatbot__ticket-form-name" placeholder="<?php esc_attr_e( 'Enter your name', 'wpsc-ps' ); ?>" autocomplete="off" >
				</div>
				<div class="wpsc-chatbot__field">
					<input type="email" id="wpsc-chatbot-ticket-form-email" class="wpsc-chatbot__ticket-form-email" placeholder="<?php esc_attr_e( 'Enter your email', 'wpsc-ps' ); ?>" autocomplete="off" >
				</div>
				<button type="button" class="wpsc-chatbot__ticket-submit" data-source="ticket-form" > <?php esc_html_e( 'Create Ticket', 'wpsc-ps' ); ?> </button>
				<button type="button" class="wpsc-chatbot__ticket-form-cancel" data-source="ticket-form" data-sessionid="<?php echo esc_attr( $session_id ); ?>" > <?php esc_html_e( 'Cancel', 'wpsc-ps' ); ?> </button>
			</div>
			<?php
			return ob_get_clean();
		}
	}
endif;
WPSC_Chatbot_Ticket_Form::init();
