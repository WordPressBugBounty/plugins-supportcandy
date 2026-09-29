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

			// get_template() is only ever needed on demand, via
			// WPSC_ACB_Admin::frontend_config() - no separate 'init' hook call needed.
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
			$appearance_settings = WPSC_ACB_Appearance_Setting::get_appearance_settings();
			$header_text = WPSC_ACB_Appearance_Setting::translate( 'Chatbot header text', $appearance_settings['header-text'] );
			$warning_text = WPSC_ACB_Appearance_Setting::translate( 'Chatbot warning message', $appearance_settings['warning-text'] );
			ob_start();
			?>
			<div class="wpsc-chatbot-launcher" data-sessionid="<?php echo esc_attr( $session_id ); ?>" >
				<?php WPSC_Icons::get( 'headphone' ); ?>
			</div>
			<div class="wpsc-chatbot-tooltip" data-wpsc-tooltip aria-live="polite" aria-hidden="true">
				<button type="button" class="wpsc-chatbot-tooltip__message">
					<?php echo esc_html( WPSC_Chatbot_Welcome::get_greeting_text() ); ?>
				</button>
				<button type="button" class="wpsc-chatbot-tooltip__close" aria-label="<?php esc_attr_e( 'Close', 'supportcandy' ); ?>">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="wpsc-chatbot">
				<div class="wpsc-chatbot__header">
					<div class="wpsc-chatbot__header-title">
						<?php
						WPSC_Icons::get( 'headphone' );
						echo esc_html( $header_text );
						?>
					</div>
					<div class="wpsc-chatbot__header-actions">
						<button type="button" class="wpsc-chatbot__header-btn wpsc-chatbot__expand" aria-label="Expand" title="<?php esc_attr_e( 'Full screen view', 'supportcandy' ); ?>" >
							<?php WPSC_Icons::get( 'expand' ); ?>
						</button>
						<button type="button" class="wpsc-chatbot__header-btn wpsc-chatbot__minimize" aria-label="Minimize" title="<?php esc_attr_e( 'Minimize chat', 'supportcandy' ); ?>" >
							<?php WPSC_Icons::get( 'minimize' ); ?>
						</button>
						<button type="button" class="wpsc-chatbot__header-btn wpsc-chatbot__compress" aria-label="Compress" title="<?php esc_attr_e( 'Exit full screen view', 'supportcandy' ); ?>" >
							<?php WPSC_Icons::get( 'compress' ); ?>
						</button>
						<button type="button" class="wpsc-chatbot__header-btn wpsc-chatbot__close" aria-label="Close" data-sessionid="<?php echo esc_attr( $session_id ); ?>" title="<?php esc_attr_e( 'Exit chat', 'supportcandy' ); ?>" >
							<?php WPSC_Icons::get( 'poweroff' ); ?>
						</button>
					</div>
				</div>

				<div class="wpsc-chatbot__body">
					<div class="wpsc-chatbot__system__message">
						<?php echo esc_html( WPSC_Chatbot_Welcome::get_greeting_text() ); ?>
						<div class="wpsc-chatbot__message-meta">
							<span><?php esc_html_e( 'Assistant', 'supportcandy' ); ?></span>
							<span class="wpsc-chatbot__welcome-time"></span>
						</div>
					</div>
				</div>

				<div class="wpsc-chatbot__footer">
					<?php if ( $warning_text ) { ?>
						<div class="wpsc-chatbot__warning-message"><?php echo esc_html( $warning_text ); ?></div>
					<?php } ?>
					<div class="wpsc-chatbot__input-group">
						<textarea id="wpsc-chatbot-input" class="wpsc-chatbot__input" placeholder="<?php esc_attr_e( 'Type your message...', 'supportcandy' ); ?>" ></textarea>
						<span class="wpsc-chatbot__send"><?php WPSC_Icons::get( 'send' ); ?></span>
					</div>
					<?php
					if ( isset( $acb_settings['show-footer-branding'] ) && $acb_settings['show-footer-branding'] ) {
						?>
						<div class="wpsc-chatbot__powered-by">
							<?php esc_html_e( 'Powered by', 'supportcandy' ); ?>
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
