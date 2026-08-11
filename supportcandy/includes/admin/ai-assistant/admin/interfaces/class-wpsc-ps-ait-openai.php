<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AIT_OpenAI' ) ) :

	final class WPSC_PS_AIT_OpenAI implements WPSC_PS_AIT_Provider_Interface {

		/**
		 * Mark a training file as failed with an error message
		 *
		 * @param string $api_key API key for authentication.
		 * @return mixed
		 */
		public function wpsc_provider_store_id( $api_key ) {

			return WPSC_PS_AI_OpenAI::wpsc_provider_store_id( $api_key );
		}

		/**
		 * Clear the cached vector store ID. See interface docblock.
		 *
		 * @return void
		 */
		public function wpsc_clear_provider_store_id() {

			WPSC_PS_AI_OpenAI::clear_stored_vector_store_id();
		}

		/**
		 * Extract metadata from the prompt for analytics or other purposes.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $system_prompt The system prompt to guide the AI's response.
		 * @param string $user_prompt The user prompt containing the content to analyze.
		 * @return array Extracted metadata such as intent, entities, etc.
		 */
		public function wpsc_get_file_meta_data( $ai_settings, $system_prompt, $user_prompt ) {

			// Validate Settings.
			if ( empty( $ai_settings ) || ! is_array( $ai_settings ) ) {
				return false;
			}

			// Validate prompts.
			if ( empty( $system_prompt ) || empty( $user_prompt ) ) {
				return false;
			}

			$api_key = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';
			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gpt-4.1-mini';

			// Prepare Body.
			$request_body = array(
				'model' => $model,
				'input' => array(
					array(
						'role'    => 'system',
						'content' => array(
							array(
								'type' => 'input_text',
								'text' => (string) $system_prompt,
							),
						),
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'input_text',
								'text' => (string) $user_prompt,
							),
						),
					),
				),
				'text'  => array(
					'format' => array(
						'type'   => 'json_schema',
						'name'   => 'file_metadata',
						'schema' => array(
							'type'                 => 'object',
							'properties'           => array(
								'summary'  => array(
									'type' => 'string',
								),
								'headings' => array(
									'type'  => 'array',
									'items' => array(
										'type' => 'string',
									),
								),
								'topics'   => array(
									'type'  => 'array',
									'items' => array(
										'type' => 'string',
									),
								),
								'keywords' => array(
									'type'  => 'array',
									'items' => array(
										'type' => 'string',
									),
								),
								'intent'   => array(
									'type' => 'string',
								),
							),
							'required'             => array(
								'summary',
								'headings',
								'topics',
								'keywords',
								'intent',
							),
							'additionalProperties' => false,
						),
						'strict' => true,
					),
				),
			);

			// API Request.
			$response = wp_remote_post(
				'https://api.openai.com/v1/responses',
				array(
					'headers'     => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'timeout'     => 60,
					'body'        => wp_json_encode( $request_body ),
					'data_format' => 'body',
				)
			);

			// Handle WP_Error.
			if ( is_wp_error( $response ) ) {
				return false;
			}

			// Validate HTTP Response.
			$status_code = wp_remote_retrieve_response_code( $response );
			$raw_body    = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				return false;
			}

			// Decode Response JSON.
			$body = json_decode( $raw_body, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $body ) ) {
				return false;
			}

			// API-Level Error.
			if ( isset( $body['error'] ) ) {
				$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown API error';
				return false;
			}

			// Extract Structured Output.
			if (
				empty( $body['output'] ) ||
				! is_array( $body['output'] ) ||
				empty( $body['output'][0]['content'][0]['text'] )
			) {
				return false;
			}

			$json_output = $body['output'][0]['content'][0]['text'];

			// Decode Final JSON Output.
			$final_output = json_decode( $json_output, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $final_output ) ) {
				return false;
			}

			if (
				! isset( $final_output['summary'] ) ||
				! isset( $final_output['headings'] ) ||
				! isset( $final_output['topics'] ) ||
				! isset( $final_output['keywords'] ) ||
				! isset( $final_output['intent'] )
			) {
				return false;
			}

			return $final_output;
		}

		/**
		 * Attach a file to a vector store
		 *
		 * @param string $vector_store_id ID of the vector store.
		 * @param string $file_id ID of the file to attach.
		 * @param string $api_key API key for authentication.
		 * @return array|WP_Error|false Response from OpenAI API, or a WP_Error with code
		 *                              'vector_store_not_found' when the store itself is
		 *                              gone/inaccessible (e.g. belongs to another OpenAI
		 *                              project), so callers can tell that failure apart
		 *                              from an ordinary attach error.
		 */
		public function wpsc_attach_file( $vector_store_id, $file_id, $api_key ) {

			// Validate Inputs.
			if ( empty( $api_key ) || ! is_string( $api_key ) ) {
				return false;
			}

			if ( empty( $vector_store_id ) || ! is_string( $vector_store_id ) ) {
				return false;
			}

			if ( empty( $file_id ) || ! is_string( $file_id ) ) {
				return false;
			}

			$vector_store_id = sanitize_text_field( $vector_store_id );
			$file_id         = sanitize_text_field( $file_id );

			$response = self::wpsc_remote_post(
				"https://api.openai.com/v1/vector_stores/{$vector_store_id}/files",
				array( 'file_id' => $file_id ),
				$api_key
			);

			if ( is_wp_error( $response ) ) {

				$status_code = $response->get_error_data()['status_code'] ?? 0;

				// OpenAI returns 404 with a "No vector store found with id ..." message when the
				// configured store doesn't exist for this key/project (e.g. after a key rotation).
				if ( 404 === $status_code && false !== stripos( $response->get_error_message(), 'vector store' ) ) {
					return new WP_Error( 'vector_store_not_found', $response->get_error_message() );
				}

				return false;
			}

			// Validate Success.
			if ( empty( $response['id'] ) ) {
				return false;
			}
			$response['id'] = sanitize_text_field( $response['id'] );
			return $response;
		}

		/**
		 * Upload a file to OpenAI
		 *
		 * @param string $file_path Path to the file.
		 * @param string $api_key API key for authentication.
		 * @return array Response from OpenAI API.
		 */
		public function wpsc_upload_file( $file_path, $api_key ) {

			if ( empty( $file_path ) || ! is_string( $file_path ) ) {
				return false;
			}

			if ( empty( $api_key ) || ! is_string( $api_key ) ) {
				return false;
			}

			$boundary = wp_generate_password( 24, false );

			$filename = basename( $file_path );
			$filetype = mime_content_type( $file_path );
			$filedata = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( $filedata === false ) {
				return false;
			}

			// Build multipart body.
			$body  = '';
			$body .= "--{$boundary}\r\n";
			$body .= 'Content-Disposition: form-data; name="purpose"' . "\r\n\r\n";
			$body .= "assistants\r\n";

			$body .= "--{$boundary}\r\n";
			$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
			$body .= "Content-Type: {$filetype}\r\n\r\n";
			$body .= $filedata . "\r\n";
			$body .= "--{$boundary}--";

			// Request.
			$response = wp_remote_post(
				'https://api.openai.com/v1/files',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . trim( $api_key ),
						'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
					),
					'body'    => $body,
					'timeout' => 45,
				)
			);

			// Handle response.
			if ( is_wp_error( $response ) ) {
				return false;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				return false;
			}

			$data = json_decode( $response_body, true );

			if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['id'] ) ) {
				return false;
			}

			return $data;
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
			$model       = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gpt-4o-mini';
			$max_tokens  = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 500;

			for ( $attempt = 1; $attempt <= 3; $attempt++ ) {

				$attempt_model = WPSC_PS_AI_OpenAI::resolve_retry_model(
					$model,
					$attempt
				);

				// Prepare request body.
				$request_body = array(
					'model'             => $attempt_model,
					'input'             => array(
						array(
							'role'    => 'system',
							'content' => array(
								array(
									'type' => 'input_text',
									'text' => (string) $system_prompt,
								),
							),
						),
						array(
							'role'    => 'user',
							'content' => array(
								array(
									'type' => 'input_text',
									'text' => (string) $prompt,
								),
							),
						),
					),
					'max_output_tokens' => $max_tokens,
				);

				// API request.
				$response = wp_remote_post(
					'https://api.openai.com/v1/responses',
					array(
						'method'      => 'POST',
						'timeout'     => 60,
						'sslverify'   => true,
						'headers'     => array(
							'Authorization' => 'Bearer ' . $api_key,
							'Content-Type'  => 'application/json',
						),
						'body'        => wp_json_encode( $request_body ),
						'data_format' => 'body',
					)
				);

				if ( WPSC_PS_AI_OpenAI::is_retryable_response( $response ) ) {

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
		 * Process the response from the AI provider for a polished reply.
		 *
		 * @param array|WP_Error $response The response from wp_remote_post.
		 * @return array|false An array containing 'reply' and 'tokens' on success, or false on failure.
		 */
		private function process_polished_reply_response( $response ) {

			// Handle request failure.
			if ( is_wp_error( $response ) ) {
				return false;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$raw_body    = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				return false;
			}

			if ( empty( $raw_body ) ) {
				return false;
			}

			// Decode JSON.
			$body = json_decode( $raw_body, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $body ) ) {
				return false;
			}

			// Extract tokens.
			$tokens = 0;
			if ( ! empty( $body['usage']['total_tokens'] ) ) {
				$tokens = (int) $body['usage']['total_tokens'];
			}

			// Extract reply safely.
			$reply = '';

			// Direct path.
			if (
				! empty( $body['output'][0]['content'][0]['text'] ) &&
				is_string( $body['output'][0]['content'][0]['text'] )
			) {
				$reply = trim( $body['output'][0]['content'][0]['text'] );
			}

			// Fallback parsing.
			if ( empty( $reply ) && ! empty( $body['output'] ) && is_array( $body['output'] ) ) {

				foreach ( $body['output'] as $output ) {

					if (
						isset( $output['type'], $output['content'] ) &&
						$output['type'] === 'message' &&
						is_array( $output['content'] )
					) {
						foreach ( $output['content'] as $content ) {
							if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
								$reply = trim( $content['text'] );
								break 2;
							}
						}
					}

					// Secondary fallback.
					if ( isset( $output['text'] ) && is_string( $output['text'] ) ) {
						$reply = trim( $output['text'] );
						break;
					}
				}
			}

			if ( empty( $reply ) ) {
				return false;
			}

			$reply = WPSC_PS_AI_Functions::wpsc_strip_ai_markdown_fences( $reply );

			return array(
				'reply'  => $reply,
				'tokens' => $tokens,
			);
		}

		/**
		 * Send a POST request to a remote URL with JSON-encoded body and API key authentication.
		 *
		 * @param string $url The URL to send the request to.
		 * @param array  $body The body of the request.
		 * @param string $api_key The API key for authentication.
		 * @return array The decoded JSON response or an empty array on error.
		 */
		private static function wpsc_remote_post( $url, $body, $api_key ) {

			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
					'timeout' => 60,
				)
			);

			return self::wpsc_handle_response( $response );
		}

		/**
		 * Handle the response from a remote request.
		 *
		 * @param array|WP_Error $response The response from wp_remote_post or wp_remote_get.
		 * @return array|WP_Error The decoded JSON response, or a WP_Error carrying the HTTP
		 *                        status code (in error data, key 'status_code') and OpenAI's
		 *                        error message on failure, so callers that need to tell
		 *                        specific failures apart (e.g. a 404 "not found") can.
		 */
		private static function wpsc_handle_response( $response ) {

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code < 200 || $code >= 300 || ( is_array( $data ) && isset( $data['error'] ) ) ) {

				$error_message = isset( $data['error']['message'] ) && is_string( $data['error']['message'] )
					? $data['error']['message']
					: __( 'Unknown API error.', 'wpsc-ps' );

				return new WP_Error( 'api_error', $error_message, array( 'status_code' => $code ) );
			}

			return is_array( $data ) ? $data : array();
		}

		/**
		 * Call OpenAI API to get improved reply.
		 *
		 * @param string $context      The prompt containing the draft reply and context for the AI.
		 * @param int    $ticket_id   The ID of the ticket being processed.
		 * @return string|false The improved reply from the AI or false on failure.
		 */
		public function wpsc_improve_draft_content( $context, $ticket_id ) {

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings' );

			$vector_store_id = $this->wpsc_provider_store_id( $ai_settings['api_key'] );
			if ( ! $vector_store_id ) {
				return false;
			}

			// ✅ STEP 1: CLEAN CONTEXT (remove duplicate lines)
			$lines   = explode( "\n", $context );
			$clean   = array();
			$last    = '';

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( empty( $line ) || $line === $last ) {
					continue;
				}
				$clean[] = $line;
				$last    = $line;
			}

			$context = implode( "\n", $clean );

			// ✅ STEP 2: Extract only last user question + last assistant reply (token optimization)
			$last_user = '';
			$last_assistant = '';

			foreach ( array_reverse( $clean ) as $line ) {
				if ( empty( $last_user ) && stripos( $line, 'User:' ) === 0 ) {
					$last_user = trim( substr( $line, 5 ) );
				}
				if ( empty( $last_assistant ) && stripos( $line, 'Assistant:' ) === 0 ) {
					$last_assistant = trim( substr( $line, 10 ) );
				}
				if ( $last_user && $last_assistant ) {
					break;
				}
			}

			$optimized_context = "User: {$last_user}\nAssistant: {$last_assistant}";

			// ✅ STEP 3: Strong Prompt (no NO_KB_MATCH issue)
			$system_prompt = WPSC_PS_AIT_Controller::wpsc_prompt_to_improve_auto_draft_reply_on_user_instruction( $ai_settings );
			$response = self::open_ai_auto_reply_request( $ai_settings, $system_prompt, $optimized_context, $vector_store_id );
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

			$api_key = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';
			$file_id = $file->provider_file_id ? trim( $file->provider_file_id ) : '';
			$vector_store_id = $this->wpsc_provider_store_id( $ai_settings['api_key'] );

			if ( empty( $vector_store_id ) || empty( $file_id ) ) {
				return false;
			}

			$headers = array(
				'Authorization' => 'Bearer ' . $api_key,
			);

			// Step 1: Remove from vector store (ignore 404).
			$vs_response = wp_remote_request(
				'https://api.openai.com/v1/vector_stores/' . rawurlencode( $vector_store_id ) . '/files/' . rawurlencode( $file_id ),
				array(
					'method'  => 'DELETE',
					'headers' => $headers,
					'timeout' => 60,
				)
			);

			if ( is_wp_error( $vs_response ) ) {
				return false;
			}

			$vs_code = (int) wp_remote_retrieve_response_code( $vs_response );

			// If it's not success or 404, fail.
			if ( $vs_code !== 200 && $vs_code !== 204 && $vs_code !== 404 ) {
				return false;
			}

			// Step 2: Delete file from OpenAI.
			$response = wp_remote_request(
				'https://api.openai.com/v1/files/' . rawurlencode( $file_id ),
				array(
					'method'  => 'DELETE',
					'headers' => $headers,
					'timeout' => 60,
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			// ✅ Treat "not found" as success.
			if ( $code === 404 ) {
				return true;
			}

			// ✅ Success responses.
			if ( $code >= 200 && $code < 300 ) {
				return true;
			}

			return false;
		}

		/**
		 * Auto draft AI reply for customer's reply.
		 *
		 * @param array       $ai_settings AI settings array.
		 * @param WPSC_Ticket $ticket The ticket model instance.
		 * @return array|false The AI draft response or false on error.
		 */
		public function wpsc_auto_draft_ticket_reply( $ai_settings, $ticket ) {

			$provider = WPSC_PS_AIT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			$store_id = $provider->wpsc_provider_store_id( $ai_settings['api_key'] );
			if ( empty( $store_id ) ) {
				return false;
			}

			$system_prompt = WPSC_PS_AIT_Controller::wpsc_prompt_to_improve_auto_draft_reply_on_user_instruction( $ai_settings );
			$context = WPSC_PS_AI_AD_Controller::wpsc_build_ticket_context_for_ai_training( $ai_settings, $ticket );
			$response = self::open_ai_auto_reply_request( $ai_settings, $system_prompt, $context, $store_id );

			return array(
				'reply'  => isset( $response['reply'] ) ? trim( $response['reply'] ) : '',
				'status' => ! empty( $response['reply'] ) ? 'success' : 'error',
			);
		}

		/**
		 * Clean ticket for RAG
		 *
		 * @param string $system_prompt The system prompt.
		 * @param array  $ai_settings AI settings array.
		 * @return string The cleaned ticket history.
		 */
		public function wpsc_clean_row_content_for_rag( $system_prompt, $ai_settings ) {

			// Validate inputs.
			if ( empty( $system_prompt ) || empty( $ai_settings ) || ! is_array( $ai_settings ) ) {
				return false;
			}

			$api_key    = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';
			$model      = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gpt-4o-mini';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 500;

			// Prepare request body.
			$request_body = array(
				'model'             => $model,
				'temperature'       => 0,
				'max_output_tokens' => $max_tokens,
				'text'              => array(
					'format' => array(
						'type'   => 'json_schema',
						'name'   => 'clean_ticket_schema',
						'schema' => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'clean_text' => array(
									'type' => 'string',
								),
							),
							'required'             => array( 'clean_text' ),
						),
					),
				),
				'input'             => array(
					array(
						'role'    => 'system',
						'content' => array(
							array(
								'type' => 'input_text',
								'text' => 'You clean support ticket data for RAG. Follow instructions strictly. Do not add new content.',
							),
						),
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'input_text',
								'text' => $system_prompt,
							),
						),
					),
				),
			);

			// API request.
			$response = wp_remote_post(
				'https://api.openai.com/v1/responses',
				array(
					'method'      => 'POST',
					'timeout'     => 60,
					'sslverify'   => true,
					'headers'     => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'body'        => wp_json_encode( $request_body ),
					'data_format' => 'body',
				)
			);

			// Handle request error.
			if ( is_wp_error( $response ) ) {
				return false;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$raw_body    = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				return false;
			}

			if ( empty( $raw_body ) ) {
				return false;
			}

			// Decode JSON.
			$body = json_decode( $raw_body, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $body ) ) {
				return false;
			}

			// Best case.
			if ( ! empty( $body['output_text'] ) && is_string( $body['output_text'] ) ) {

				$decoded = json_decode( $body['output_text'], true );
				if ( json_last_error() === JSON_ERROR_NONE && isset( $decoded['clean_text'] ) ) {
					return trim( $decoded['clean_text'] );
				}
				return trim( $body['output_text'] ); // fallback.
			}

			// Fallback parsing.
			if ( ! empty( $body['output'] ) && is_array( $body['output'] ) ) {

				foreach ( $body['output'] as $item ) {

					if ( isset( $item['content'] ) && is_array( $item['content'] ) ) {

						foreach ( $item['content'] as $content ) {

							if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
								$text = trim( $content['text'] );
								$decoded = json_decode( $text, true );
								if ( json_last_error() === JSON_ERROR_NONE && isset( $decoded['clean_text'] ) ) {
									return trim( $decoded['clean_text'] );
								}
								return $text;
							}
						}
					}

					// Secondary fallback.
					if ( isset( $item['text'] ) && is_string( $item['text'] ) ) {
						return trim( $item['text'] );
					}
				}
			}

			// API error passthrough (frontend visibility).
			if ( ! empty( $body['error'] ) ) {
				return $body['error'];
			}

			return false;
		}

		/**
		 * Send a request to OpenAI API for auto drafting a ticket reply.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $system_prompt The system prompt to guide the AI's response.
		 * @param string $context The context of the ticket for the AI to generate a reply.
		 * @param string $store_id The ID of the vector store to use for retrieval.
		 * @return array|WP_Error The response from OpenAI API or WP_Error on failure.
		 */
		private static function open_ai_auto_reply_request( $ai_settings, $system_prompt, $context, $store_id ) {

			if ( empty( $system_prompt ) || empty( $context ) ) {
				return false;
			}

			if ( empty( $store_id ) || ! is_string( $store_id ) ) {
				return false;
			}

			$api_key = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';
			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gpt-4o-mini';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 500;

			for ( $attempt = 1; $attempt <= 3; $attempt++ ) {

				$attempt_model = WPSC_PS_AI_OpenAI::resolve_retry_model(
					$model,
					$attempt
				);

				// Prepare request body.
				$body = array(
					'model'             => $attempt_model,
					'input'             => array(
						array(
							'role'    => 'system',
							'content' => (string) $system_prompt,
						),
						array(
							'role'    => 'user',
							'content' => (string) $context,
						),
					),
					'tools'             => array(
						array(
							'type'             => 'file_search',
							'vector_store_ids' => array( sanitize_text_field( $store_id ) ),
							'max_num_results'  => 6,
							'ranking_options'  => array(
								'score_threshold' => 0.5,
							),
						),
					),
					'temperature'       => 0.3,
					'max_output_tokens' => $max_tokens,
				);

				// API request.
				$response = wp_remote_post(
					'https://api.openai.com/v1/responses',
					array(
						'headers'     => array(
							'Authorization' => 'Bearer ' . $api_key,
							'Content-Type'  => 'application/json',
						),
						'body'        => wp_json_encode( $body ),
						'timeout'     => 60,
						'data_format' => 'body',
					)
				);

				if ( WPSC_PS_AI_OpenAI::is_retryable_response( $response ) ) {

					if ( $attempt < 3 ) {
						sleep( 1 );
						continue;
					}
				}

				return self::process_auto_draft_response( $response );
			}
			return false;
		}

		/**
		 * Process the response from the OpenAI API for auto drafting a ticket reply.
		 *
		 * @param array|WP_Error $response The response from wp_remote_post.
		 * @return array|false An array containing 'reply' and 'tokens' on success, or false on failure.
		 */
		private static function process_auto_draft_response( $response ) {

			// Handle request failure.
			if ( is_wp_error( $response ) ) {
				return false;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$raw_body    = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				return false;
			}

			if ( empty( $raw_body ) ) {
				return false;
			}

			// Decode response.
			$response_body = json_decode( $raw_body, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $response_body ) ) {
				return false;
			}

			// Extract reply safely.
			$reply = '';

			if ( ! empty( $response_body['output'] ) && is_array( $response_body['output'] ) ) {

				foreach ( $response_body['output'] as $item ) {

					if ( isset( $item['type'] ) && $item['type'] === 'message' && ! empty( $item['content'] ) && is_array( $item['content'] ) ) {

						foreach ( $item['content'] as $content ) {
							if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
								$reply = trim( $content['text'] );
								break 2;
							}
						}
					}

					// Fallback.
					if ( isset( $item['text'] ) && is_string( $item['text'] ) ) {
						$reply = trim( $item['text'] );
						break;
					}
				}
			}

			if ( empty( $reply ) ) {
				return false;
			}

			// Extract tokens safely.
			$tokens = 0;
			if ( ! empty( $response_body['usage']['total_tokens'] ) ) {
				$tokens = (int) $response_body['usage']['total_tokens'];
			}

			// Sanitize output.
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

			return array(
				'reply'  => $safe_reply,
				'tokens' => $tokens,
			);
		}

		/**
		 * Generate a ticket summary using OpenAI API.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $history The conversation history of the ticket.
		 * @param int    $ticket_id The ID of the ticket for which to generate the summary.
		 * @return array|false An array containing the summary and token count, or false on failure.
		 */
		public function wpsc_generate_ticket_summary( $ai_settings, $history = '', $ticket_id = 0 ) {

			// Validate inputs.
			if ( empty( $history ) ) {
				return false;
			}

			$api_key    = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';
			$model      = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gpt-4o-mini';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 500;

			$prompt = WPSC_PS_AIT_Controller::wpsc_prompt_to_create_ticket_summery( $ai_settings, $history );
			if ( empty( $prompt ) ) {
				return false;
			}

			for ( $attempt = 1; $attempt <= 3; $attempt++ ) {

				$attempt_model = WPSC_PS_AI_OpenAI::resolve_retry_model(
					$model,
					$attempt
				);

				// Prepare request body.
				$request_body = array(
					'model'             => $attempt_model,
					'input'             => array(
						array(
							'role'    => 'system',
							'content' => array(
								array(
									'type' => 'input_text',
									'text' => (string) $prompt,
								),
							),
						),
					),
					'max_output_tokens' => $max_tokens,
				);

				// API request.
				$response = wp_remote_post(
					'https://api.openai.com/v1/responses',
					array(
						'method'      => 'POST',
						'timeout'     => 60,
						'sslverify'   => true,
						'headers'     => array(
							'Authorization' => 'Bearer ' . $api_key,
							'Content-Type'  => 'application/json',
						),
						'body'        => wp_json_encode( $request_body ),
						'data_format' => 'body',
					)
				);

				if ( WPSC_PS_AI_OpenAI::is_retryable_response( $response ) ) {

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
		 * Process the response from the OpenAI API for generating a ticket summary.
		 *
		 * @param array|WP_Error $response The response from wp_remote_post.
		 * @return array|false An array containing 'summary' and 'tokens' on success, or false on failure.
		 */
		private function process_ticket_summary_response( $response ) {

			// Handle request failure.
			if ( is_wp_error( $response ) ) {
				return false;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$raw_body    = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				return false;
			}

			if ( empty( $raw_body ) ) {
				return false;
			}

			// Decode JSON.
			$body = json_decode( $raw_body, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $body ) ) {
				return false;
			}

			// Extract tokens.
			$tokens = 0;
			if ( ! empty( $body['usage']['total_tokens'] ) ) {
				$tokens = (int) $body['usage']['total_tokens'];
			}

			// Extract summary safely.
			$summary = '';

			// Direct path.
			if (
				! empty( $body['output'][0]['content'][0]['text'] ) &&
				is_string( $body['output'][0]['content'][0]['text'] )
			) {
				$summary = trim( $body['output'][0]['content'][0]['text'] );
			}

			// Fallback parsing.
			if ( empty( $summary ) && ! empty( $body['output'] ) && is_array( $body['output'] ) ) {

				foreach ( $body['output'] as $output ) {

					if (
						isset( $output['type'], $output['content'] ) &&
						$output['type'] === 'message' &&
						is_array( $output['content'] )
					) {
						foreach ( $output['content'] as $content ) {
							if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
								$summary = trim( $content['text'] );
								break 2;
							}
						}
					}

					// Secondary fallback.
					if ( isset( $output['text'] ) && is_string( $output['text'] ) ) {
						$summary = trim( $output['text'] );
						break;
					}
				}
			}

			if ( empty( $summary ) ) {
				return false;
			}

			return array(
				'summary' => $summary,
				'tokens'  => $tokens,
			);
		}
	}
endif;
