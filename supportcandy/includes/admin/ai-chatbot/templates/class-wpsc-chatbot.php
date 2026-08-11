<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Chatbot' ) ) :

	final class WPSC_Chatbot {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			add_action( 'init', array( __CLASS__, 'get_template' ), 1 );
		}

		/**
		 * Get chatbot template
		 *
		 * @return string
		 */
		public static function get_template() {

			$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );
			$cookie_name = 'wpsc_acb_session_id';
			$session_id = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';
			ob_start();
			?>
			<div class="wpsc-chatbot-launcher" data-sessionid="<?php echo esc_attr( $session_id ); ?>" >
				<?php WPSC_Icons::get( 'headphone' ); ?>
			</div>
			<div class="wpsc-chatbot">
				<div class="wpsc-chatbot__header">
					<div class="wpsc-chatbot__header-title">
						<?php
						WPSC_Icons::get( 'headphone' );
						esc_html_e( 'AI Chatbot', 'wpsc-ps' );
						?>
					</div>
					<div class="wpsc-chatbot__header-actions">
						<button type="button" class="wpsc-chatbot__header-btn wpsc-chatbot__expand" aria-label="Expand" title="<?php esc_attr_e( 'Full screen view', 'wpsc-ps' ); ?>" >
							<?php WPSC_Icons::get( 'expand' ); ?>
						</button>
						<button type="button" class="wpsc-chatbot__header-btn wpsc-chatbot__minimize" aria-label="Minimize" title="<?php esc_attr_e( 'Minimize chat', 'wpsc-ps' ); ?>" >
							<?php WPSC_Icons::get( 'minimize' ); ?>
						</button>
						<button type="button" class="wpsc-chatbot__header-btn wpsc-chatbot__compress" aria-label="Compress" title="<?php esc_attr_e( 'Exit full screen view', 'wpsc-ps' ); ?>" >
							<?php WPSC_Icons::get( 'compress' ); ?>
						</button>
						<button type="button" class="wpsc-chatbot__header-btn wpsc-chatbot__close" aria-label="Close" data-sessionid="<?php echo esc_attr( $session_id ); ?>" title="<?php esc_attr_e( 'Exit chat', 'wpsc-ps' ); ?>" >
							<?php WPSC_Icons::get( 'poweroff' ); ?>
						</button>
					</div>
				</div>

				<div class="wpsc-chatbot__body">
					<div class="wpsc-chatbot__system__message">
						<?php esc_html_e( 'Hey, I\'m your assistant. How can I help you today?', 'wpsc-ps' ); ?>
						<div class="wpsc-chatbot__message-meta">
							<span><?php esc_html_e( 'Assistant', 'wpsc-ps' ); ?></span>
							<span><?php echo esc_html( wp_date( 'H:i' ) ); ?></span>
						</div>
					</div>
				</div>

				<div class="wpsc-chatbot__footer">
					<div class="wpsc-chatbot__input-group">
						<textarea id="wpsc-chatbot-input" class="wpsc-chatbot__input" placeholder="<?php esc_attr_e( 'Type your message...', 'wpsc-ps' ); ?>" ></textarea>
						<span class="wpsc-chatbot__send"><?php WPSC_Icons::get( 'send' ); ?></span>
					</div>
					<?php
					if ( isset( $acb_settings['show-footer-branding'] ) && $acb_settings['show-footer-branding'] ) {
						?>
						<div class="wpsc-chatbot__powered-by">
							<?php esc_html_e( 'Powered by', 'wpsc-ps' ); ?>
							<a href="https://supportcandy.net" target="_blank" rel="noopener noreferrer">
								<?php WPSC_Icons::get( 'sc_logo' ); ?>
							</a>
						</div>
						<?php
					}
					?>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}
	}
endif;
WPSC_Chatbot::init();
