<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Setting_Assistant' ) ) :

	final class WPSC_PS_AI_Setting_Assistant {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// List.
			add_action( 'wp_ajax_wpsc_get_aia_assistant_setting', array( __CLASS__, 'get_aia_assistant_setting' ) );

			// Save settings.
			add_action( 'wp_ajax_wpsc_set_ai_assistant_settings', array( __CLASS__, 'save_settings' ) );
		}

		/**
		 * Get AI assistant tab setting
		 *
		 * @return void
		 */
		public static function get_aia_assistant_setting() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( empty( $ai_settings ) ) {
				wp_send_json_error( __( 'Something went wrong.', 'wpsc-ps' ), 404 );
			}

			if ( ! isset( $ai_settings['status'] ) ) {
				$ai_settings['status'] = '0';
			}
			?>
			<form action="#" onsubmit="return false;" class="wpsc-frm-ai-assistant-settings">

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-ai-service-status"><?php esc_attr_e( 'AI Assistant Status', 'wpsc-ps' ); ?></label>
					</div>
					<select id="wpsc-ai-service-status" name="wpsc-ai-service-status">
						<option value="1" <?php selected( $ai_settings['status'], '1' ); ?>><?php esc_html_e( 'Enable', 'wpsc-ps' ); ?></option>
						<option value="0" <?php selected( $ai_settings['status'], '0' ); ?>><?php esc_html_e( 'Disable', 'wpsc-ps' ); ?></option>
					</select>
					<span class="extra-info">
						<?php esc_attr_e( 'Enable this to help agents draft responses, polish replies, and summarize tickets faster.', 'wpsc-ps' ); ?>
					</span>
				</div>

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="custom-prompt"><?php esc_attr_e( 'Polish (AI) Custom Prompt (Additional instructions)', 'wpsc-ps' ); ?></label>
					</div>
					<textarea class="wpsc_textarea" id="custom-prompt" name="custom-prompt" style="height: 100px !important;"><?php echo esc_textarea( $ai_settings['custom-prompt'] ?? '' ); ?></textarea>
					<span class="extra-info">
						<?php esc_attr_e( 'Add optional instructions to refine how the AI improves (polishes) content. The default prompt already handles this, so only add text here if you need specific adjustments.', 'wpsc-ps' ); ?>
					</span>
				</div>

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="summary-custom-prompt"><?php esc_attr_e( 'Summary Custom Prompt (Additional instructions)', 'wpsc-ps' ); ?></label>
					</div>
					<textarea class="wpsc_textarea" id="summary-custom-prompt" name="summary-custom-prompt" style="height: 100px !important;"><?php echo esc_textarea( $ai_settings['summary-custom-prompt'] ?? '' ); ?></textarea>
					<span class="extra-info">
						<?php esc_attr_e( 'Provide extra instructions to customize how summaries are generated. This is optional—leave empty to use the default behavior.', 'wpsc-ps' ); ?>
					</span>
				</div>

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="auto-draft-custom-prompt"><?php esc_attr_e( 'Auto Draft Custom Prompt (Additional instructions)', 'wpsc-ps' ); ?></label>
					</div>
					<textarea class="wpsc_textarea" id="auto-draft-custom-prompt" name="auto-draft-custom-prompt" style="height: 100px !important;"><?php echo esc_textarea( $ai_settings['auto-draft-custom-prompt'] ?? '' ); ?></textarea>
					<span class="extra-info">
						<?php esc_attr_e( 'Add optional guidance to influence how the AI generates draft replies. The default prompt is sufficient for most cases, so use this only for specific needs.', 'wpsc-ps' ); ?>
					</span>
				</div>

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-ai-auto-delete-logs-time"><?php esc_attr_e( 'Auto delete AI logs', 'wpsc-ps' ); ?></label>
					</div>
					<div class="divide-bar">
						<input type="number" class="wpsc-ai-auto-delete-logs-time" id="wpsc-ai-auto-delete-logs-time" name="auto-delete-ai-logs-time" value="<?php echo esc_attr( $ai_settings['auto-delete-ai-logs-time'] ?? '' ); ?>">
						<select id="wpsc-ai-auto-delete-logs-unit" name="auto-delete-ai-logs-unit" class="wpsc-ai-auto-delete-logs-unit">
							<option <?php selected( $ai_settings['auto-delete-ai-logs-unit'] ?? '', 'days' ); ?> value="days"><?php esc_attr_e( 'Day(s)', 'wpsc-ps' ); ?></option>
							<option <?php selected( $ai_settings['auto-delete-ai-logs-unit'] ?? '', 'month' ); ?> value="month"><?php esc_attr_e( 'Month(s)', 'wpsc-ps' ); ?></option>
							<option <?php selected( $ai_settings['auto-delete-ai-logs-unit'] ?? '', 'year' ); ?> value="year"><?php esc_attr_e( 'Year(s)', 'wpsc-ps' ); ?></option>
						</select>
					</div>
					<span class="extra-info">
						<?php esc_attr_e( 'Specify the duration after which AI logs should be automatically deleted.', 'wpsc-ps' ); ?>
					</span>
				</div>

				<input type="hidden" name="action" value="wpsc_set_ai_assistant_settings">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_ai_assistant_settings' ) ); ?>">

				<div class="setting-footer-actions">
					<button
						class="wpsc-button normal primary margin-right"
						onclick="wpsc_set_ai_assistant_settings(this);">
						<?php esc_attr_e( 'Submit', 'wpsc-ps' ); ?>
					</button>
				</div>
			</form>
			<?php
			wp_die();
		}

		/**
		 * Save AI assistant tab setting
		 *
		 * @return void
		 */
		public static function save_settings() {

			if ( check_ajax_referer( 'wpsc_set_ai_assistant_settings', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );

			$status = isset( $_POST['wpsc-ai-service-status'] ) ? sanitize_text_field( wp_unslash( $_POST['wpsc-ai-service-status'] ) ) : '';
			if ( '' === $status || ! in_array( $status, array( '1', '0' ), true ) ) {
				wp_send_json_error( __( 'Invalid or missing AI assistant status!', 'wpsc-ps' ), 400 );
			}

			$auto_delete_ai_logs_time = isset( $_POST['auto-delete-ai-logs-time'] ) ? intval( $_POST['auto-delete-ai-logs-time'] ) : 0;
			if ( $auto_delete_ai_logs_time < 0 ) {
				wp_send_json_error( __( 'Auto delete AI logs time must be zero or a positive integer.', 'wpsc-ps' ), 400 );
			}

			$auto_delete_ai_logs_unit = isset( $_POST['auto-delete-ai-logs-unit'] ) ? sanitize_text_field( wp_unslash( $_POST['auto-delete-ai-logs-unit'] ) ) : '';
			if ( empty( $auto_delete_ai_logs_unit ) ) {
				wp_send_json_error( __( 'Invalid or missing auto delete AI logs unit!', 'wpsc-ps' ), 400 );
			}

			$custom_prompt            = isset( $_POST['custom-prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom-prompt'] ) ) : '';
			$summary_custom_prompt    = isset( $_POST['summary-custom-prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary-custom-prompt'] ) ) : '';
			$auto_draft_custom_prompt = isset( $_POST['auto-draft-custom-prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['auto-draft-custom-prompt'] ) ) : '';

			// Only update fields owned by this tab; leave other tabs' settings untouched.
			$ai_settings['status'] = $status;
			$ai_settings['auto-delete-ai-logs-time'] = $auto_delete_ai_logs_time;
			$ai_settings['auto-delete-ai-logs-unit'] = $auto_delete_ai_logs_unit;
			$ai_settings['custom-prompt'] = $custom_prompt;
			$ai_settings['summary-custom-prompt'] = $summary_custom_prompt;
			$ai_settings['auto-draft-custom-prompt'] = $auto_draft_custom_prompt;

			update_option( 'wpsc-ps-ai-assistant-settings', $ai_settings );

			wp_send_json_success(
				array(
					'message' => __( 'Settings saved successfully.', 'wpsc-ps' ),
				)
			);
			wp_die();
		}
	}
endif;
WPSC_PS_AI_Setting_Assistant::init();
