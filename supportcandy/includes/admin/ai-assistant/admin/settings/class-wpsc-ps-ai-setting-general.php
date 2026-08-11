<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Setting_General' ) ) :

	final class WPSC_PS_AI_Setting_General {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// List.
			add_action( 'wp_ajax_wpsc_get_aia_general_setting', array( __CLASS__, 'get_aia_general_setting' ) );

			// Save, reset & test settings.
			add_action( 'wp_ajax_wpsc_set_ai_settings', array( __CLASS__, 'save_settings' ) );
			add_action( 'wp_ajax_wpsc_reset_ai_settings', array( __CLASS__, 'reset_settings' ) );
		}

		/**
		 * Get AI assistant general setting
		 *
		 * @return void
		 */
		public static function get_aia_general_setting() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( empty( $ai_settings ) ) {
				wp_send_json_error( __( 'Something went wrong.', 'wpsc-ps' ), 404 );
			}

			?>
			<form action="#" onsubmit="return false;" class="wpsc-frm-ai-settings">
				<div class="wpsc-dock-container">
					<?php
					printf(
						/* translators: Click here to see the documentation */
						esc_attr__( '%s to see the documentation!', 'supportcandy' ),
						'<a href="https://supportcandy.net/docs/ai-general-settings/" target="_blank">' . esc_attr__( 'Click here', 'supportcandy' ) . '</a>'
					);
					?>
				</div>
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-ai-service-provider"><?php esc_attr_e( 'AI Service Provider', 'wpsc-ps' ); ?></label>
					</div>
					<select id="wpsc-ai-service-provider" name="wpsc-ai-service-provider">
						<option value="openai" <?php selected( $ai_settings['provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'wpsc-ps' ); ?></option>
						<option value="google-gemini" <?php selected( $ai_settings['provider'], 'google-gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'wpsc-ps' ); ?></option>
					</select>
					<span class="extra-info">
						<?php esc_attr_e( 'Select your preferred AI service provider.', 'wpsc-ps' ); ?>
					</span>
				</div>

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-ai-api-key"><?php esc_attr_e( 'API Key', 'wpsc-ps' ); ?></label>
						<span class="required-indicator">*</span>
					</div>
					<input type="text" id="wpsc-ai-api-key" name="wpsc-ai-api-key" value="<?php echo esc_attr( $ai_settings['api_key'] ); ?>" placeholder="<?php esc_attr_e( 'Enter your API Key', 'wpsc-ps' ); ?> "/>
					<span class="extra-info">
						<?php esc_attr_e( 'Enter your API key for the selected provider.', 'wpsc-ps' ); ?>
					</span>
				</div>
				
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-ai-max-tokens"><?php esc_attr_e( 'Max Tokens', 'wpsc-ps' ); ?></label>
					</div>
					<input type="number" id="wpsc-ai-max-tokens" value="<?php echo esc_attr( $ai_settings['max-tokens'] ); ?>" name="wpsc-ai-max-tokens" placeholder="<?php esc_attr_e( 'Enter the maximum number of tokens', 'wpsc-ps' ); ?> "/>
					<span class="extra-info">
						<?php esc_attr_e( 'Sets the maximum length of AI responses. Higher values allow longer outputs if needed and may increase cost depending on usage.', 'wpsc-ps' ); ?>
					</span>
				</div>

				<input type="hidden" name="action" value="wpsc_set_ai_settings">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_ai_settings' ) ); ?>">
			
				<div class="setting-footer-actions">
					<button 
						class="wpsc-button normal primary margin-right"
						onclick="wpsc_set_ai_settings(this);">
						<?php esc_attr_e( 'Submit', 'wpsc-ps' ); ?>
					</button>
					<button 
						class="wpsc-button normal secondary margin-right"
						onclick="wpsc_reset_ai_settings(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_reset_ai_settings' ) ); ?>');">
						<?php esc_attr_e( 'Reset', 'wpsc-ps' ); ?>
					</button>
				</div>
			</form>
			<?php
			if ( isset( $ai_settings['is-active'] ) && $ai_settings['is-active'] ) {
				?>
				<div style="margin-top: 15px; color: #009432;"><?php esc_html_e( 'Connected!', 'wpsc-ps' ); ?></div>
				<?php
			} else {
				?>
				<div style="margin-top: 15px; color: #ff0000;"><?php esc_html_e( 'Not Connected!', 'wpsc-ps' ); ?></div>
				<div style="margin-top: 15px; color: #ff0000;"><?php echo esc_html( $ai_settings['last-error'] ); ?></div>
				<?php
			}
			wp_die();
		}

		/**
		 * Save AI assistant settings
		 *
		 * @return void
		 */
		public static function save_settings() {

			if ( check_ajax_referer( 'wpsc_set_ai_settings', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );

			$service_provider = isset( $_POST['wpsc-ai-service-provider'] ) ? sanitize_text_field( wp_unslash( $_POST['wpsc-ai-service-provider'] ) ) : '';
			if ( empty( $service_provider ) || ! in_array( $service_provider, array( 'openai', 'google-gemini' ), true ) ) {
				wp_send_json_error( __( 'Invalid or missing service provider!', 'wpsc-ps' ), 400 );
			}

			$api_key = isset( $_POST['wpsc-ai-api-key'] ) ? sanitize_text_field( wp_unslash( $_POST['wpsc-ai-api-key'] ) ) : '';
			if ( empty( $api_key ) || strlen( $api_key ) < 10 ) {
				wp_send_json_error( __( 'Invalid or missing API key!', 'wpsc-ps' ), 400 );
			}

			$max_tokens = isset( $_POST['wpsc-ai-max-tokens'] ) ? intval( $_POST['wpsc-ai-max-tokens'] ) : 0;
			if ( $max_tokens < 500 || $max_tokens > 16384 ) {
				wp_send_json_error( __( 'Max tokens must be between 500 and 16384.', 'wpsc-ps' ), 400 );
			}

			$temperature = isset( $_POST['wpsc-ai-temperature'] ) ? floatval( $_POST['wpsc-ai-temperature'] ) : 0;
			if ( $temperature < 0 || $temperature > 1 ) {
				wp_send_json_error( __( 'Temperature must be between 0 and 1.', 'wpsc-ps' ), 400 );
			}

			$model = $service_provider === 'openai' ? 'gpt-4o-mini' : 'gemini-2.5-flash-lite';

			$old_api_key   = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';
			$old_provider  = isset( $ai_settings['provider'] ) ? trim( $ai_settings['provider'] ) : '';
			$is_active     = ! empty( $ai_settings['is-active'] );

			// Check if API key OR provider changed.
			$is_key_changed = ( $api_key !== $old_api_key ) || ( $service_provider !== $old_provider );

			// A changed key may belong to a different provider project. Vector/file-search
			// stores are project-scoped and never re-validated once cached, so a stale
			// store ID left over from the old key would silently fail every sync/upload
			// (Website tab shows no records, file uploads fail with no visible error).
			// Clear both caches here so the next use recreates a store under the key
			// that's actually being configured now.
			if ( $is_key_changed ) {
				self::clear_cached_provider_store_ids();
			}

			$test_result = array(
				'success' => true,
				'message' => '',
			);
			// Only test connection if not already active.
			if ( $is_key_changed ) {
				if ( $service_provider === 'openai' ) {
					$test_result = self::internal_openai_test_connection( $api_key );
					if ( $test_result['success'] ) {
						$test_result = self::internal_openai_test_rag_permission( $api_key );
					}
				} elseif ( $service_provider === 'google-gemini' ) {
					$test_result = self::internal_google_test_connection( $api_key );
					if ( $test_result['success'] ) {
						$test_result = self::internal_google_test_rag_permission( $api_key );
					}
				}
				if ( $test_result['success'] ) {
					$is_active = true;
				} else {
					$is_active = false;
				}
			}

			// Only update fields owned by this tab; leave other tabs' settings untouched.
			$ai_settings['provider']     = $service_provider;
			$ai_settings['model']        = $model;
			$ai_settings['api_key']      = $api_key;
			$ai_settings['max-tokens']   = $max_tokens;
			$ai_settings['is-active']    = $is_active;
			$ai_settings['last-error']   = $test_result['success'] ? '' : $test_result['message'];
			if ( ! isset( $ai_settings['ai-max-upload-file-size'] ) ) {
				$ai_settings['ai-max-upload-file-size'] = 10; // default 10 MB.
			}
			update_option( 'wpsc-ps-ai-assistant-settings', $ai_settings );

			wp_send_json_success(
				array(
					'message' => __( 'Settings saved successfully.', 'wpsc-ps' ),
				)
			);
			wp_die();
		}

		/**
		 * Reset AI assistant settings
		 *
		 * @return void
		 */
		public static function reset_settings() {

			if ( check_ajax_referer( 'wpsc_reset_ai_settings', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			// Reset should give a genuinely clean slate: also drop any cached vector/file-search
			// store ID, otherwise re-entering a new key later silently reuses a store that may
			// belong to a different project (see clear_stored_vector_store_id() docblock).
			self::clear_cached_provider_store_ids();

			update_option(
				'wpsc-ps-ai-assistant-settings',
				array(
					'status'                   => '0',
					'provider'                 => 'openai',
					'api_key'                  => '',
					'model'                    => 'gpt-4o-mini',
					'max-tokens'               => 500,
					'auto-delete-ai-logs-time' => 1,
					'auto-delete-ai-logs-unit' => 'year',
					'custom-prompt'            => '',
					'summary-custom-prompt'    => '',
					'auto-draft-custom-prompt' => '',
					'is-active'                => 0,
					'last-error'               => '',
					'ai-max-upload-file-size'  => 10,
				)
			);

			wp_send_json_success(
				array(
					'message' => __( 'Settings reset successfully.', 'wpsc-ps' ),
				)
			);
			wp_die();
		}

		/**
		 * Clear both providers' cached vector/file-search store IDs. Called whenever the
		 * configured API key/provider changes or settings are reset, since a store ID cached
		 * under a previous key/project is never re-validated on its own (see
		 * WPSC_PS_AI_OpenAI::clear_stored_vector_store_id() docblock).
		 *
		 * @return void
		 */
		private static function clear_cached_provider_store_ids() {

			WPSC_PS_AI_OpenAI::clear_stored_vector_store_id();
			WPSC_PS_AI_Gemini::clear_stored_file_search_store_id();
		}

		/**
		 * Test OpenAI connection
		 *
		 * @param string $api_key API key to test.
		 * @return array Associative array with 'success' (bool) and 'message' (string).
		 */
		private static function internal_openai_test_connection( $api_key ) {

			// Validate API key.
			$api_key = is_string( $api_key ) ? trim( $api_key ) : '';
			if ( empty( $api_key ) ) {
				return array(
					'success' => false,
					'message' => __( 'API key is missing.', 'wpsc-ps' ),
				);
			}

			// API request.
			$response = wp_remote_get(
				'https://api.openai.com/v1/models',
				array(
					'method'    => 'GET',
					'timeout'   => 60,
					'sslverify' => true,
					'headers'   => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
				)
			);

			// Handle request error.
			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => $response->get_error_message(),
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			// Success case.
			if ( $code === 200 ) {
				return array(
					'success' => true,
					'message' => __( 'Connection to OpenAI is successful!', 'wpsc-ps' ),
				);
			}

			// Default error message.
			$error_message = '';

			// Try extracting API error message.
			if ( ! empty( $body ) ) {

				$data = json_decode( $body, true );

				if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {

					if ( ! empty( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
						$error_message = ' - ' . sanitize_text_field( $data['error']['message'] );
					}
				}
			}

			return array(
				'success' => false,
				'message' => __( 'Connection to OpenAI failed: ', 'wpsc-ps' ) . $error_message,
			);
		}

		/**
		 * Test Google AI connection
		 *
		 * @param string $api_key API key to test.
		 * @return array Associative array with 'success' (bool) and 'message' (string).
		 */
		private static function internal_google_test_connection( $api_key ) {

			$url = 'https://generativelanguage.googleapis.com/v1/models?key=' . $api_key;
			$args = array(
				'method'    => 'GET',
				'timeout'   => 60,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
				),
			);

			$response = wp_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => $response->get_error_message(),
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code === 200 ) {
				return array(
					'success' => true,
					'message' => __( 'Connection to Google AI is successful!', 'wpsc-ps' ),
				);
			} else {
				$error_message = '';
				$body = wp_remote_retrieve_body( $response );
				if ( ! empty( $body ) ) {
					$data = json_decode( $body, true );
					if ( isset( $data['error']['message'] ) ) {
						$error_message = ' - ' . $data['error']['message'];
					}
				}
				return array(
					'success' => false,
					'message' => __( 'Connection to Google AI failed: ', 'wpsc-ps' ) . $error_message,
				);
			}
		}

		/**
		 * Verify the OpenAI API key has permission to use Vector Stores (required for RAG file uploads).
		 * A key can pass the basic /v1/models check yet still be restricted/scoped to disallow this endpoint.
		 *
		 * @param string $api_key API key to test.
		 * @return array Associative array with 'success' (bool) and 'message' (string).
		 */
		private static function internal_openai_test_rag_permission( $api_key ) {

			$response = wp_remote_get(
				'https://api.openai.com/v1/vector_stores?limit=1',
				array(
					'method'    => 'GET',
					'timeout'   => 60,
					'sslverify' => true,
					'headers'   => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => $response->get_error_message(),
				);
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code === 200 ) {
				return array(
					'success' => true,
					'message' => __( 'Connection to OpenAI is successful!', 'wpsc-ps' ),
				);
			}

			$error_message = '';
			$body = wp_remote_retrieve_body( $response );
			if ( ! empty( $body ) ) {
				$data = json_decode( $body, true );
				if ( json_last_error() === JSON_ERROR_NONE && ! empty( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
					$error_message = ' - ' . sanitize_text_field( $data['error']['message'] );
				}
			}

			return array(
				'success' => false,
				'message' => __( 'API key is valid, but it does not have permission to use Vector Stores, which is required for RAG file uploads. Check your API key\'s scope/restrictions in your OpenAI account.', 'wpsc-ps' ) . $error_message,
			);
		}

		/**
		 * Verify the Google Gemini API key has permission to use File Search Stores (required for RAG file uploads).
		 * A key can pass the basic /v1/models check yet still be restricted to disallow this v1beta endpoint.
		 *
		 * @param string $api_key API key to test.
		 * @return array Associative array with 'success' (bool) and 'message' (string).
		 */
		private static function internal_google_test_rag_permission( $api_key ) {

			$url = 'https://generativelanguage.googleapis.com/v1beta/fileSearchStores?pageSize=1&key=' . $api_key;
			$args = array(
				'method'    => 'GET',
				'timeout'   => 60,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
				),
			);

			$response = wp_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => $response->get_error_message(),
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code === 200 ) {
				return array(
					'success' => true,
					'message' => __( 'Connection to Google AI is successful!', 'wpsc-ps' ),
				);
			}

			$error_message = '';
			$body = wp_remote_retrieve_body( $response );
			if ( ! empty( $body ) ) {
				$data = json_decode( $body, true );
				if ( isset( $data['error']['message'] ) ) {
					$error_message = ' - ' . $data['error']['message'];
				}
			}
			return array(
				'success' => false,
				'message' => __( 'API key is valid, but it does not have permission to use File Search Stores, which is required for RAG file uploads. Check your API key\'s API restrictions in Google Cloud console.', 'wpsc-ps' ) . $error_message,
			);
		}
	}
endif;
WPSC_PS_AI_Setting_General::init();
