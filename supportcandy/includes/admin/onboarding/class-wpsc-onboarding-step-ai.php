<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Onboarding_Step_AI' ) ) :

	/**
	 * Onboarding step 2: AI Assistant & Chatbot.
	 *
	 * Renders its own markup (not the existing Settings screen's form), but posts
	 * to the existing 'wpsc_set_ai_settings' AJAX action so the API key is still
	 * stored, validated and connection-tested by the existing, single code path -
	 * no duplicate key storage or validation is introduced here.
	 */
	final class WPSC_Onboarding_Step_AI {

		const STEP_ID = 'ai-assistant';

		/**
		 * Render step body
		 *
		 * @param string $step_id - step id.
		 * @return void
		 */
		public static function render( $step_id ) {

			$available   = class_exists( 'WPSC_PS_AI_Setting_General' );
			$ai_settings = $available ? get_option( 'wpsc-ps-ai-assistant-settings', array() ) : array();
			$is_active   = ! empty( $ai_settings['is-active'] );
			$provider    = isset( $ai_settings['provider'] ) ? $ai_settings['provider'] : 'openai';
			$api_key     = isset( $ai_settings['api_key'] ) ? $ai_settings['api_key'] : '';
			$max_tokens  = ! empty( $ai_settings['max-tokens'] ) ? intval( $ai_settings['max-tokens'] ) : 4096;
			?>
			<div class="wpsc-onboarding-step">
				<h2><?php esc_attr_e( 'AI Assistant & Chatbot', 'supportcandy' ); ?></h2>
				<p class="wpsc-onboarding-step-desc">
					<?php esc_attr_e( 'AI Assistant and Chatbot can help your support team summarize tickets, draft replies, answer customer questions, and provide AI-powered support using your configured knowledge. This is entirely optional and can be set up anytime later from Settings.', 'supportcandy' ); ?>
				</p>

				<?php if ( ! $available ) : ?>
					<div class="wpsc-onboarding-status pending"><?php esc_attr_e( 'AI Assistant is not available on this site.', 'supportcandy' ); ?></div>
				<?php elseif ( $is_active ) : ?>
					<div class="wpsc-onboarding-status success">
						<?php WPSC_Icons::get( 'check' ); ?>
						<?php
						printf(
							/* translators: %s: AI provider name */
							esc_html__( 'AI Assistant is connected using %s.', 'supportcandy' ),
							esc_html( 'google-gemini' === $provider ? __( 'Google Gemini', 'supportcandy' ) : __( 'OpenAI', 'supportcandy' ) )
						);
						?>
					</div>
				<?php else : ?>
					<form action="#" onsubmit="return false;" class="wpsc-frm-onboarding-ai">
						<div class="wpsc-input-group">
							<div class="label-container">
								<label><?php esc_attr_e( 'AI provider', 'supportcandy' ); ?></label>
							</div>
							<select name="wpsc-ai-service-provider">
								<option value="openai" <?php selected( $provider, 'openai' ); ?>><?php esc_attr_e( 'OpenAI', 'supportcandy' ); ?></option>
								<option value="google-gemini" <?php selected( $provider, 'google-gemini' ); ?>><?php esc_attr_e( 'Google Gemini', 'supportcandy' ); ?></option>
							</select>
						</div>
						<div class="wpsc-input-group">
							<div class="label-container">
								<label><?php esc_attr_e( 'API key', 'supportcandy' ); ?></label>
							</div>
							<input type="text" name="wpsc-ai-api-key" value="<?php echo esc_attr( self::mask_api_key( $api_key ) ); ?>" placeholder="<?php esc_attr_e( 'Enter your API key', 'supportcandy' ); ?>" autocomplete="off">
						</div>
						<input type="hidden" name="wpsc-ai-max-tokens" value="<?php echo esc_attr( $max_tokens ); ?>">
						<input type="hidden" name="action" value="wpsc_set_ai_settings">
						<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_ai_settings' ) ); ?>">
					</form>
					<div class="wpsc-onboarding-inline-message"></div>
					<div class="wpsc-onboarding-inline-actions">
						<button type="button" class="wpsc-button normal primary" onclick="wpsc_onboarding_save_ai(this);">
							<?php esc_attr_e( 'Connect', 'supportcandy' ); ?>
						</button>
					</div>
				<?php endif; ?>

				<?php WPSC_Onboarding::render_step_footer( $step_id ); ?>
			</div>
			<?php
			wp_die();
		}

		/**
		 * Mask an API key for display, e.g. "sk-1********2345" - mirrors the format
		 * of the existing (private) masking helper so an unchanged resubmission is
		 * still recognized as "unchanged" by the existing save handler.
		 *
		 * @param string $key - raw API key.
		 * @return string
		 */
		private static function mask_api_key( $key ) {

			$key    = trim( (string) $key );
			$length = strlen( $key );

			if ( 0 === $length ) {
				return '';
			}
			if ( $length <= 8 ) {
				return str_repeat( '*', $length );
			}
			return substr( $key, 0, 4 ) . str_repeat( '*', 8 ) . substr( $key, -4 );
		}
	}
endif;
