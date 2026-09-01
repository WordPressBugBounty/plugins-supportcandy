<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AIT_Google_Gemini' ) ) :

	final class WPSC_PS_AIT_Google_Gemini implements WPSC_PS_AIT_Provider_Interface {

		/**
		 * Mark a training file as failed with an error message
		 *
		 * @param string $api_key API key for authentication.
		 * @return mixed
		 */
		public function wpsc_provider_store_id( $api_key ) {

			return WPSC_PS_AI_Gemini::wpsc_provider_store_id( $api_key );
		}

		/**
		 * Clear the cached file search store ID. See interface docblock.
		 *
		 * @return void
		 */
		public function wpsc_clear_provider_store_id() {

			WPSC_PS_AI_Gemini::clear_stored_file_search_store_id();
		}

		/**
		 * Attach a file to a file search store
		 *
		 * @param string $file_search_id ID of the file search store.
		 * @param string $file_id ID of the file to attach.
		 * @param string $api_key API key for authentication.
		 * @return array Response from Google Gemini API.
		 */
		public function wpsc_attach_file( $file_search_id, $file_id, $api_key ) {

			if ( empty( $file_search_id ) || ! is_string( $file_search_id ) ) {
				return false;
			}

			if ( empty( $file_id ) || ! is_string( $file_id ) ) {
				return false;
			}

			$file_search_id = sanitize_text_field( $file_search_id );
			$file_id        = sanitize_text_field( $file_id );

			$normalized_file_search_id = trim( $file_search_id, '/' );
			if ( 0 !== strpos( $file_id, $normalized_file_search_id . '/documents/' ) ) {
				return false;
			}

			return array( 'name' => $file_id );
		}

		/**
		 * Send a POST request to a remote URL with JSON-encoded body and API key authentication.
		 *
		 * @param string $url The URL to send the request to.
		 * @param array  $body The body of the request.
		 * @return array The decoded JSON response or an empty array on error.
		 */
		private static function wpsc_remote_post( $url, $body = array() ) {

			$args = array(
				'headers'     => array(
					'Content-Type' => 'application/json',
				),
				'body'        => ! empty( $body ) ? wp_json_encode( $body ) : null,
				'timeout'     => 60,
				'data_format' => 'body',
			);

			$response = wp_remote_post( esc_url_raw( $url ), $args );

			// Transport error.
			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'http_request_failed',
					$response->get_error_message()
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			// Decode JSON.
			$data = json_decode( $response_body, true );

			// Invalid JSON.
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error(
					'invalid_json',
					__( 'Invalid JSON response from API.', 'wpsc-ps' )
				);
			}

			// Handle API errors.
			if ( $status_code < 200 || $status_code >= 300 ) {

				$error_message = isset( $data['error']['message'] )
					? $data['error']['message']
					: __( 'Unknown API error.', 'wpsc-ps' );

				return new WP_Error(
					'api_error',
					sprintf(
						/* translators: %1$d: HTTP status code, %2$s: Error message */
						__( 'API request failed with status %1$d: %2$s', 'wpsc-ps' ),
						$status_code,
						sanitize_text_field( $error_message )
					)
				);
			}

			return $data;
		}

		/**
		 * Maximum training file size Google Gemini's File API accepts per file. See
		 * interface docblock.
		 *
		 * @return int Maximum file size in bytes (2 GB, per Gemini's documented File API limit).
		 */
		public function wpsc_max_training_file_size() {

			return 2 * 1024 * 1024 * 1024;
		}

		/**
		 * Upload a file to Google Gemini using multipart/form-data
		 *
		 * @param string $file_path The local path to the file to upload.
		 * @param string $api_key   The API key for authentication.
		 * @return array|WP_Error The response data on success, or WP_Error on failure.
		 */
		public function wpsc_upload_file( $file_path, $api_key ) {

			// Validate file.
			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				return new WP_Error( 'invalid_file', __( 'File does not exist.', 'wpsc-ps' ) );
			}

			// Get store ID.
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			$store_id = $this->wpsc_provider_store_id( $ai_settings['api_key'] );

			// A real auth/quota/network failure fetching or creating the store surfaces
			// here as a WP_Error - propagate it as-is instead of collapsing it into the
			// generic "missing" message below, so a systemic outage stays distinguishable
			// from a one-off content problem in the Training Data list.
			if ( is_wp_error( $store_id ) ) {
				return $store_id;
			}

			if ( empty( $store_id ) || ! is_string( $store_id ) ) {
				return new WP_Error( 'invalid_store_id', __( 'File search store ID is missing.', 'wpsc-ps' ) );
			}

			$filename = basename( $file_path );
			$filetype = mime_content_type( $file_path );
			$filedata = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( $filedata === false ) {
				return new WP_Error( 'file_read_error', __( 'Unable to read file.', 'wpsc-ps' ) );
			}

			// Detect MIME type.
			$filetype  = wp_check_filetype_and_ext( $file_path, $filename );
			$mime_type = ! empty( $filetype['type'] )
			? $filetype['type']
			: ( function_exists( 'mime_content_type' ) ? mime_content_type( $file_path ) : 'application/octet-stream' );

			// Read file (keeping your approach).
			$file_contents = file_get_contents( $file_path ); // phpcs:ignore

			if ( false === $file_contents ) {
				return new WP_Error( 'file_read_error', __( 'Unable to read file.', 'wpsc-ps' ) );
			}

			// Boundary.
			$boundary = wp_generate_password( 24, false );

			// Build multipart body.
			$body  = '';
			$body .= '--' . $boundary . "\r\n";
			$body .= "Content-Disposition: form-data; name=\"metadata\"\r\n";
			$body .= "Content-Type: application/json\r\n\r\n";
			$body .= wp_json_encode(
				array(
					'displayName' => sanitize_file_name( $filename ),
				)
			);
			$body .= "\r\n";

			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="file"; filename="' . sanitize_file_name( $filename ) . '"' . "\r\n";
			$body .= 'Content-Type: ' . esc_attr( $mime_type ) . "\r\n\r\n";
			$body .= $filedata . "\r\n";
			$body .= '--' . $boundary . '--';

			// Correct API (as per your requirement).
			$url = 'https://generativelanguage.googleapis.com/upload/v1beta/' . $store_id . ':uploadToFileSearchStore?key=' . rawurlencode( $api_key );

			$response = wp_remote_post(
				esc_url_raw( $url ),
				array(
					'headers' => array(
						'Content-Type'           => 'multipart/form-data; boundary=' . $boundary,
						'X-Goog-Upload-Protocol' => 'multipart',
					),
					'body'    => $body,
					'timeout' => 45,
				)
			);

			// Transport error.
			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'http_request_failed',
					$response->get_error_message()
				);
			}

			$code     = wp_remote_retrieve_response_code( $response );
			$res_body = wp_remote_retrieve_body( $response );

			// Decode JSON.
			$data = json_decode( $res_body, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error(
					'invalid_json',
					__( 'Invalid JSON response from Gemini API.', 'wpsc-ps' ),
					array( 'raw_response' => $res_body )
				);
			}

			// API-level error.
			if ( $code < 200 || $code >= 300 ) {

				$error_message = isset( $data['error']['message'] )
					? $data['error']['message']
					: __( 'Unknown API error.', 'wpsc-ps' );

				// Google returns 404 when the configured file search store doesn't exist for
				// this key/project (e.g. after a key rotation) — flagged with a distinct code
				// so callers can auto-clear the cached store ID instead of just failing.
				if ( 404 === $code ) {
					return new WP_Error(
						'file_search_store_not_found',
						sanitize_text_field( $error_message )
					);
				}

				return new WP_Error(
					'gemini_upload_failed',
					sprintf(
					/* translators: %d: HTTP status code */
						__( 'Upload failed (HTTP %1$d): %2$s', 'wpsc-ps' ),
						$code,
						sanitize_text_field( $error_message )
					),
					array(
						'response' => $data,
					)
				);
			}

			// uploadToFileSearchStore returns a long-running Operation resource, not a
			// finished result - `done` can still be false here (embedding/import still in
			// progress, more likely for larger files or under load), in which case
			// `response` isn't populated yet and isn't a failure. Poll it to completion
			// (bounded) rather than treating an in-progress operation as a hard failure.
			$operation = self::wpsc_poll_upload_operation( $data, $api_key );

			if ( is_wp_error( $operation ) ) {
				return $operation;
			}

			if ( ! empty( $operation['error'] ) ) {
				$error_message = isset( $operation['error']['message'] )
					? $operation['error']['message']
					: __( 'Unknown API error.', 'wpsc-ps' );

				return new WP_Error(
					'gemini_upload_failed',
					sanitize_text_field( $error_message ),
					array( 'response' => $operation )
				);
			}

			// Validate expected response.
			if ( empty( $operation['response']['documentName'] ) ) {
				return new WP_Error(
					'invalid_response',
					__( 'Missing file ID in API response.', 'wpsc-ps' ),
					array(
						'response' => $operation,
					)
				);
			}

			// Success response.
			return array(
				'id'        => sanitize_text_field( $operation['response']['documentName'] ),
				'mime_type' => sanitize_text_field( $mime_type ),
				'size'      => filesize( $file_path ),
			);
		}

		/**
		 * Poll a long-running uploadToFileSearchStore Operation until it reports done.
		 * Google's own documented pattern for this endpoint is to poll
		 * fileSearchStores.upload.operations.get until `done` is true rather than
		 * treating the initial response as a finished result.
		 *
		 * Bounded so a slow-to-process operation doesn't hang this upload call
		 * indefinitely - if it's still not done after the poll window, that's reported
		 * as a distinct error (WPSC_PS_AIT_Controller requeues the row for a fresh
		 * attempt instead of marking it permanently failed) rather than a hard failure,
		 * since the upload itself did succeed and may finish moments later.
		 *
		 * @param array  $operation Initial Operation resource returned by the upload call.
		 * @param string $api_key   API key for authentication.
		 * @return array|WP_Error Completed Operation resource, or WP_Error on failure/timeout.
		 */
		private static function wpsc_poll_upload_operation( $operation, $api_key ) {

			$max_attempts  = 10;
			$poll_interval = 2; // seconds.

			for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {

				if ( ! empty( $operation['done'] ) ) {
					return $operation;
				}

				if ( empty( $operation['name'] ) || ! is_string( $operation['name'] ) ) {
					// Nothing to poll against - fail rather than looping forever.
					return new WP_Error(
						'invalid_response',
						__( 'Missing operation name in Gemini upload response.', 'wpsc-ps' ),
						array( 'response' => $operation )
					);
				}

				sleep( $poll_interval );

				$url = 'https://generativelanguage.googleapis.com/v1beta/' . $operation['name'] . '?key=' . rawurlencode( $api_key );

				$response = wp_remote_get( esc_url_raw( $url ), array( 'timeout' => 30 ) );

				if ( is_wp_error( $response ) ) {
					return new WP_Error( 'http_request_failed', $response->get_error_message() );
				}

				$code = wp_remote_retrieve_response_code( $response );
				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $body ) ) {
					return new WP_Error( 'invalid_json', __( 'Invalid JSON response from Gemini API.', 'wpsc-ps' ) );
				}

				if ( $code < 200 || $code >= 300 ) {
					$error_message = isset( $body['error']['message'] )
						? $body['error']['message']
						: __( 'Unknown API error.', 'wpsc-ps' );

					return new WP_Error(
						'gemini_upload_failed',
						sprintf(
						/* translators: %1$d: HTTP status code, %2$s: error message */
							__( 'Upload failed (HTTP %1$d): %2$s', 'wpsc-ps' ),
							$code,
							sanitize_text_field( $error_message )
						),
						array( 'response' => $body )
					);
				}

				$operation = $body;
			}

			return new WP_Error(
				'gemini_operation_pending',
				__( 'Gemini upload is still processing.', 'wpsc-ps' ),
				array( 'response' => $operation )
			);
		}

		/**
		 * Generate a polished reply using the AI provider.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $system_prompt The system prompt used for AI training.
		 * @param string $prompt The user prompt containing the draft reply and context for the AI.
		 * @param int    $ticket_id The ID of the ticket being processed.
		 * @return string The polished reply generated by the AI provider.
		 */
		public function wpsc_generate_polished_reply( $ai_settings, $system_prompt, $prompt, $ticket_id ) {

			// Validate inputs.
			if ( empty( $system_prompt ) || empty( $prompt ) ) {
				return false;
			}

			$api_key     = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';
			$model       = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gemini-2.5-flash-lite';
			$max_tokens  = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 500;

			$body = array(
				'system_instruction' => array(
					'parts' => array(
						array( 'text' => $system_prompt ),
					),
				),

				'contents'           => array(
					array(
						'role'  => 'user',
						'parts' => array(
							array( 'text' => $prompt ),
						),
					),
				),
				'generationConfig'   => array(
					'maxOutputTokens' => $max_tokens,
				),
			);

			for ( $attempt = 1; $attempt <= 3; $attempt++ ) {

				$attempt_model = WPSC_PS_AI_Gemini::resolve_retry_model(
					$model,
					$attempt
				);

				$attempt_url = sprintf(
					'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
					rawurlencode( $attempt_model ),
					rawurlencode( $ai_settings['api_key'] )
				);

				$response = wp_remote_post(
					$attempt_url,
					array(
						'method'    => 'POST',
						'timeout'   => 60,
						'sslverify' => true,
						'headers'   => array(
							'Content-Type' => 'application/json',
						),
						'body'      => wp_json_encode( $body ),
					)
				);

				if ( WPSC_PS_AI_Gemini::is_retryable_response( $response ) ) {

					$status_code = wp_remote_retrieve_response_code( $response );
					if ( $attempt < 3 ) {
						sleep( 1 );
						continue;
					}
				}

				return $this->process_polished_reply_response( $response );
			}
			return false;
		}

		/**
		 * Process the response from the Gemini API for a polished reply request.
		 *
		 * @param array|WP_Error $response The response from wp_remote_post.
		 * @return array|false An array containing 'reply' and 'tokens' on success, or false on failure.
		 */
		private function process_polished_reply_response( $response ) {

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			if ( $response_code === 200 ) {

				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				$tokens = 0;
				if ( ! empty( $data['usageMetadata']['totalTokenCount'] ) ) {
					$tokens = (int) $data['usageMetadata']['totalTokenCount'];
				} elseif ( ! empty( $data['usage']['totalTokens'] ) ) {
					$tokens = (int) $data['usage']['totalTokens'];
				}
				if ( ! empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
					return array(
						'reply'  => WPSC_PS_AI_Functions::wpsc_strip_ai_markdown_fences( trim( $data['candidates'][0]['content']['parts'][0]['text'] ) ),
						'tokens' => $tokens,
					);
				}
			}
			return false;
		}

		/**
		 * Call Google API to get improved reply.
		 *
		 * @param string $context      The prompt containing the draft reply and context for the AI.
		 * @param int    $ticket_id   The ID of the ticket being processed.
		 * @return string|false The improved reply from the AI or false on failure.
		 */
		public function wpsc_improve_draft_content( $context, $ticket_id ) {

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings' );

			// Extract only last user question + last assistant reply (token optimization).
			list( $last_user, $last_assistant ) = WPSC_PS_AI_Functions::wpsc_extract_last_user_and_assistant_blocks( $context );

			$optimized_context = "User: {$last_user}\nAssistant: {$last_assistant}";

			// ✅ STEP 3: Strong Prompt (no NO_KB_MATCH issue)
			$system_prompt = WPSC_PS_AIT_Controller::wpsc_prompt_to_improve_auto_draft_reply_on_user_instruction( $ai_settings );
			$response = $this->gemini_auto_reply_request( $ai_settings, $system_prompt, $optimized_context );
			return $response;
		}

		/**
		 * Auto delete expired records from provider
		 *
		 * @param WPSC_RAG_Training_File $file The training data model instance.
		 * @param array                  $ai_settings AI settings array.
		 * @return bool True if the file was successfully deleted, false otherwise.
		 */
		public function wpsc_delete_training_record( $file, $ai_settings ) {

			/**
			 * Expected format:
			 * fileSearchStores/{store_id}/documents/{doc_id}
			 */
			// DELETE with force=true.
			$url = 'https://generativelanguage.googleapis.com/v1beta/' . $file->provider_file_id . '?key=' . rawurlencode( $ai_settings['api_key'] ) . '&force=true';

			$response = wp_remote_request(
				esc_url_raw( $url ),
				array(
					'method'  => 'DELETE',
					'timeout' => 60,
				)
			);

			// Transport error.
			if ( is_wp_error( $response ) ) {
				return false;
			}

			$code     = wp_remote_retrieve_response_code( $response );
			$res_body = wp_remote_retrieve_body( $response );

			// Decode if exists (usually empty).
			$data = ! empty( $res_body ) ? json_decode( $res_body, true ) : array();

			// Consider already-deleted documents as success.
			if (
				404 === (int) $code &&
				! empty( $data['error']['status'] ) &&
				'NOT_FOUND' === $data['error']['status'] &&
				! empty( $data['error']['message'] ) &&
				false !== stripos( (string) $data['error']['message'], 'does not exist' )
			) {
				return true;
			}

			// API error.
			if ( $code < 200 || $code >= 300 ) {
				return false;
			}

			// Success.
			return true;
		}

		/**
		 * Auto draft AI reply for customer's reply.
		 *
		 * @param array       $ai_settings AI settings array.
		 * @param WPSC_Ticket $ticket The ticket model instance.
		 * @return array|false The AI draft response or false on error.
		 */
		public function wpsc_auto_draft_ticket_reply( $ai_settings, $ticket ) {

			$system_prompt = WPSC_PS_AIT_Controller::wpsc_prompt_to_improve_auto_draft_reply_on_user_instruction( $ai_settings );
			$context = WPSC_PS_AI_AD_Controller::wpsc_build_ticket_context_for_ai_training( $ai_settings, $ticket );
			$response = $this->gemini_auto_reply_request( $ai_settings, $system_prompt, $context );

			return array(
				'reply'  => isset( $response['reply'] ) ? trim( $response['reply'] ) : '',
				'status' => ! empty( $response['reply'] ) ? 'success' : 'error',
			);
		}

		/**
		 * Generate an auto reply using Google Gemini with File Search Store RAG.
		 *
		 * Files are already indexed in the File Search Store via the upload flow.
		 * Gemini retrieves relevant knowledge base context automatically via the
		 * file_search tool — no need to pass individual file URIs or parts.
		 *
		 * @param array  $ai_settings   Must contain 'api_key'. Optionally 'model', 'max-tokens'.
		 * @param string $system_prompt The agent-defined instruction / role prompt.
		 * @param string $context       The ticket context (subject + thread + customer info).
		 *
		 * @return array|false {
		 *     'reply'  => string,  // Generated reply text.
		 *     'tokens' => int,     // Total tokens consumed.
		 * } or false on any failure.
		 */
		public function gemini_auto_reply_request( $ai_settings, $system_prompt, $context ) {

			if ( empty( $system_prompt ) || ! is_string( $system_prompt ) ) {
				return false;
			}

			if ( empty( $context ) || ! is_string( $context ) ) {
				return false;
			}

			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gemini-1.5-pro-latest';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 500;

			$store_name = $this->wpsc_provider_store_id( $ai_settings['api_key'] );
			$store_name = is_string( $store_name ) ? $store_name : '';

			$request_body = array(
				'system_instruction' => array(
					'parts' => array(
						array( 'text' => $system_prompt ),
					),
				),
				'contents'           => array(
					array(
						'role'  => 'user',
						'parts' => array(
							array( 'text' => $context ),
						),
					),
				),
				'generationConfig'   => array(
					'temperature'     => 0.3,
					'maxOutputTokens' => $max_tokens,
				),
			);

			if ( ! empty( $store_name ) ) {
				$request_body['tools'] = array(
					array(
						'file_search' => array(
							'file_search_store_names' => array( $store_name ),
						),
					),
				);
			}

			for ( $attempt = 1; $attempt <= 3; $attempt++ ) {

				$attempt_model = WPSC_PS_AI_Gemini::resolve_retry_model(
					$model,
					$attempt
				);

				$attempt_url = sprintf(
					'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
					rawurlencode( $attempt_model ),
					rawurlencode( $ai_settings['api_key'] )
				);

				$response = wp_remote_post(
					$attempt_url,
					array(
						'timeout' => 60,
						'headers' => array( 'Content-Type' => 'application/json' ),
						'body'    => wp_json_encode( $request_body ),
					)
				);

				if ( WPSC_PS_AI_Gemini::is_retryable_response( $response ) ) {

					$status_code = wp_remote_retrieve_response_code( $response );
					if ( $attempt < 3 ) {
						sleep( 1 );
						continue;
					}
				}

				return $this->process_auto_draft_response( $response );
			}
			return false;
		}

		/**
		 * Process the response from the Gemini API for auto draft replies.
		 *
		 * @param array|WP_Error $response The response from wp_remote_post.
		 * @return array|false {
		 *     'reply'  => string,  // Generated reply text.
		 *     'tokens' => int,     // Total tokens consumed.
		 * } or false on any failure.
		 */
		private function process_auto_draft_response( $response ) {

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$raw_body    = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 || empty( $raw_body ) ) {
				return false;
			}

			$response_body = json_decode( $raw_body, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return false;
			}

			$finish_reason = $response_body['candidates'][0]['finishReason'] ?? '';
			if ( 'SAFETY' === $finish_reason ) {
				return false;
			}

			$reply = '';
			$parts = $response_body['candidates'][0]['content']['parts'] ?? array();

			foreach ( $parts as $part ) {
				if ( ! empty( $part['text'] ) ) {
					$reply .= $part['text'];
				}
			}

			$reply = trim( $reply );

			if ( empty( $reply ) ) {
				return false;
			}

			$allowed_tags = array(
				'a'      => array(
					'href'   => true,
					'title'  => true,
					'target' => true,
					'rel'    => true,
				),
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'br'     => array(),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
				'hr'     => array(),
				'p'      => array(),
			);

			$safe_reply = wp_kses( $reply, $allowed_tags );

			if ( empty( $safe_reply ) ) {
				return false;
			}

			$tokens = isset( $response_body['usageMetadata']['totalTokenCount'] )
				? (int) $response_body['usageMetadata']['totalTokenCount']
				: 0;

			return array(
				'reply'  => $safe_reply,
				'tokens' => $tokens,
			);
		}

		/**
		 * Call Google API to get improved reply.
		 *
		 * @param array  $ai_settings The AI settings array containing API key, model, and max tokens.
		 * @param string $history     The recent ticket history for context (optional).
		 * @param int    $ticket_id   The ID of the ticket being summarized.
		 * @return array|false An array containing the summary and token count, or false on failure.
		 */
		public function wpsc_generate_ticket_summary( $ai_settings, $history = '', $ticket_id = 0 ) {

			// Validate inputs.
			if ( empty( $history ) ) {
				return false;
			}

			$api_key    = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';
			$model      = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gemini-1.5-pro-latest';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 500;

			$prompt = WPSC_PS_AIT_Controller::wpsc_prompt_to_create_ticket_summery( $ai_settings, $history );

			$body = array(
				'contents'         => array(
					array(
						'role'  => 'user',
						'parts' => array(
							array( 'text' => $prompt ),
						),
					),
				),
				'generationConfig' => array(
					'maxOutputTokens' => $max_tokens,
				),
			);

			for ( $attempt = 1; $attempt <= 3; $attempt++ ) {

				$attempt_model = WPSC_PS_AI_Gemini::resolve_retry_model(
					$model,
					$attempt
				);

				$attempt_url = sprintf(
					'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
					rawurlencode( $attempt_model ),
					rawurlencode( $ai_settings['api_key'] )
				);
				$response = wp_remote_post(
					$attempt_url,
					array(
						'method'    => 'POST',
						'timeout'   => 60,
						'sslverify' => true,
						'headers'   => array(
							'Content-Type' => 'application/json',
						),
						'body'      => wp_json_encode( $body ),
					)
				);

				if ( WPSC_PS_AI_Gemini::is_retryable_response( $response ) ) {

					$status_code = wp_remote_retrieve_response_code( $response );
					if ( $attempt < 3 ) {
						sleep( 1 );
						continue;
					}
				}

				return $this->process_ticket_summary_response( $response );
			}

			return false;
		}

		/**
		 * Process the response from the Gemini API for ticket summary requests.
		 *
		 * @param array|WP_Error $response The response from wp_remote_post.
		 * @return array|false An array containing 'summary' and 'tokens' on success, or false on failure.
		 */
		private function process_ticket_summary_response( $response ) {

			if ( is_wp_error( $response ) ) {
				return false;
			}

			if ( wp_remote_retrieve_response_code( $response ) === 200 ) {

				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				$tokens = ! empty( $body['usageMetadata']['totalTokenCount'] ) ? (int) $body['usageMetadata']['totalTokenCount'] : 0;
				if ( ! empty( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
					return array(
						'summary' => trim( $body['candidates'][0]['content']['parts'][0]['text'] ),
						'tokens'  => $tokens,
					);
				}
			}
			return false;
		}

		/**
		 * Ask Gemini to judge whether prepared post content is useful enough to index
		 * into the RAG knowledge base. See interface docblock.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $content Prepared plain-text content to judge.
		 * @return array|false {'quality_score' => int, 'useful_for_rag' => bool} or false on failure.
		 */
		public function wpsc_assess_content_quality_for_rag( $ai_settings, $content ) {

			if ( empty( $content ) || ! is_string( $content ) ) {
				return false;
			}

			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gemini-2.5-flash-lite';

			$prompt = WPSC_PS_AIT_Controller::wpsc_prompt_to_assess_content_quality_for_rag( $content );

			for ( $attempt = 1; $attempt <= 3; $attempt++ ) {

				$attempt_model = WPSC_PS_AI_Gemini::resolve_retry_model( $model, $attempt );

				// Gemini 2.5 models think by default, and thinking tokens are drawn from
				// the same maxOutputTokens budget as the actual reply - left unset, the
				// model can spend the whole budget on internal reasoning and return
				// nothing, which silently disables this quality gate (the caller treats
				// an unparsable result as "unable to judge", not "reject", and lets the
				// content through). Flash/Flash-Lite allow thinking to be disabled
				// entirely (budget 0); Pro enforces a minimum of 128 and rejects 0, so it
				// gets that floor instead, with extra maxOutputTokens headroom to still
				// leave room for the actual JSON reply after that reasoning allowance.
				$is_pro             = false !== stripos( $attempt_model, 'pro' );
				$thinking_budget    = $is_pro ? 128 : 0;
				$max_output_tokens  = $is_pro ? 300 : 100;

				$body = array(
					'contents'         => array(
						array(
							'role'  => 'user',
							'parts' => array(
								array( 'text' => $prompt ),
							),
						),
					),
					'generationConfig' => array(
						'maxOutputTokens'  => $max_output_tokens,
						'responseMimeType' => 'application/json',
						'thinkingConfig'   => array( 'thinkingBudget' => $thinking_budget ),
					),
				);

				$attempt_url = sprintf(
					'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
					rawurlencode( $attempt_model ),
					rawurlencode( $ai_settings['api_key'] )
				);

				$response = wp_remote_post(
					$attempt_url,
					array(
						'method'    => 'POST',
						'timeout'   => 30,
						'sslverify' => true,
						'headers'   => array(
							'Content-Type' => 'application/json',
						),
						'body'      => wp_json_encode( $body ),
					)
				);

				if ( WPSC_PS_AI_Gemini::is_retryable_response( $response ) ) {

					if ( $attempt < 3 ) {
						sleep( 1 );
						continue;
					}
				}

				return $this->process_content_quality_response( $response );
			}

			return false;
		}

		/**
		 * Process the response from the Gemini API for the RAG content-quality check.
		 *
		 * @param array|WP_Error $response The response from wp_remote_post.
		 * @return array|false {'quality_score' => int, 'useful_for_rag' => bool} or false on failure.
		 */
		private function process_content_quality_response( $response ) {

			if ( is_wp_error( $response ) ) {
				return false;
			}

			if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( json_last_error() !== JSON_ERROR_NONE || empty( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
				return false;
			}

			return WPSC_PS_AI_Functions::wpsc_parse_rag_quality_response( $body['candidates'][0]['content']['parts'][0]['text'] );
		}
	}
endif;
