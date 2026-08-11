<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AIBOT_Gemini' ) ) :

	final class WPSC_PS_AIBOT_Gemini implements WPSC_PS_AIBOT_Provider_Interface {

		/**
		 * Get the store ID for a given provider.
		 *
		 * @param string $api_key The API key for authentication.
		 * @return string The store ID associated with the provider.
		 */
		public function wpsc_provider_store_id( $api_key ) {

			return WPSC_PS_AI_Gemini::wpsc_provider_store_id( $api_key );
		}

		/**
		 * Get a chat response from Google Gemini API based on the provided message.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $message The user message to send to the AI.
		 * @param string $system_prompt The system prompt to guide the AI's response.
		 * @param array  $conversation_history The conversation history to provide context to the AI.
		 * @param array  $tools Optional tools to include in the request (e.g., file search).
		 * @param array  $tool_context Optional agentic-loop continuation state; see WPSC_PS_AIBOT_Provider_Interface::wpsc_get_chat_response().
		 * @return string|false The response from the AI provider or false on failure.
		 */
		public function wpsc_get_chat_response( $ai_settings, $message, $system_prompt = '', $conversation_history = array(), $tools = array(), $tool_context = array() ) {

			$fallback = array(
				'success'       => false,
				'response'      => 'Sorry, I am having trouble responding right now. Please try again shortly.',
				'total_tokens'  => 0,
				'create_ticket' => false,
			);

			$message = is_string( $message ) ? trim( $message ) : '';
			if ( '' === $message ) {
				return $fallback;
			}

			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gemini-1.5-pro-latest';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 500;

			$store_name = $this->wpsc_provider_store_id( $ai_settings['api_key'] );
			if ( is_wp_error( $store_name ) ) {
				return $fallback;
			}

			$gemini_tools = class_exists( 'WPSC_AIBOT_Tool_Utils' ) ? WPSC_AIBOT_Tool_Utils::build_gemini_tools( $store_name, $tools ) : array();

			$known_tool_names = array();
			foreach ( $gemini_tools as $tool_group ) {
				foreach ( $tool_group['function_declarations'] ?? array() as $declaration ) {
					if ( ! empty( $declaration['name'] ) ) {
						$known_tool_names[] = sanitize_key( (string) $declaration['name'] );
					}
				}
			}

			$is_continuation = is_array( $tool_context ) && is_array( $tool_context['contents'] ?? null );

			if ( $is_continuation ) {

				// Continue an agentic tool-calling loop: replay the prior turn's
				// contents, echo back the model's own functionCall, then append
				// the tool's structured result as a functionResponse part.
				$contents = $tool_context['contents'];
				$tool_call = is_array( $tool_context['tool_call'] ?? null ) ? $tool_context['tool_call'] : array();
				$tool_name = sanitize_key( (string) ( $tool_call['name'] ?? '' ) );

				if ( '' !== $tool_name ) {

					// Gemini's functionCall.args and functionResponse.response are
					// proto Struct (object) fields, not repeating fields - wp_json_encode()
					// serializes a PHP empty array as JSON `[]`, which the API rejects
					// with "Proto field is not repeating, cannot start list." Force an
					// object cast so an empty array always encodes as `{}` instead.
					$arguments = is_array( $tool_call['arguments'] ?? null ) ? $tool_call['arguments'] : array();
					$tool_result = is_array( $tool_context['tool_result'] ?? null ) ? $tool_context['tool_result'] : array();

					$contents[] = array(
						'role'  => 'model',
						'parts' => array(
							array(
								'functionCall' => array(
									'name' => $tool_name,
									'args' => empty( $arguments ) ? (object) array() : $arguments,
								),
							),
						),
					);
					$contents[] = array(
						'role'  => 'function',
						'parts' => array(
							array(
								'functionResponse' => array(
									'name'     => $tool_name,
									'response' => empty( $tool_result ) ? (object) array() : $tool_result,
								),
							),
						),
					);
				}

				$requested_choice = $tool_context['tool_choice'] ?? 'auto';
				$function_calling_mode = 'none' === $requested_choice ? 'NONE' : 'AUTO';
			} else {

				$contents = $this->wpsc_get_api_formatted_chat_messages( $conversation_history, $message );
				$function_calling_mode = ! empty( $gemini_tools ) ? 'ANY' : 'AUTO';
			}

			$request_body = array(
				'system_instruction' => array(
					'parts' => array(
						array(
							'text' => $system_prompt,
						),
					),
				),
				'contents'           => $contents,
				'generationConfig'   => array(
					'temperature'     => 0.3,
					'maxOutputTokens' => $max_tokens,
				),
				'tools'              => $gemini_tools,
				'toolConfig'         => array(
					'function_calling_config' => array(
						'mode' => $function_calling_mode,
					),
				),
			);

			$max_retries = isset( $tool_context['max_retries'] ) ? max( 1, (int) $tool_context['max_retries'] ) : 3;

			for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {

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
						'body'      => wp_json_encode( $request_body ),
					)
				);

				if ( WPSC_PS_AI_Gemini::is_retryable_response( $response ) ) {

					$status_code = wp_remote_retrieve_response_code( $response );
					if ( $attempt < $max_retries ) {
						sleep( 1 );
						continue;
					}
				}

				$result = $this->process_chat_response(
					$response,
					$fallback,
					$known_tool_names
				);

				if ( ! empty( $result['success'] ) ) {
					$result['contents'] = $contents;
				}

				return $result;
			}

			return $fallback;
		}

		/**
		 * Process the response from the Gemini API and extract relevant information.
		 *
		 * @param array $response The response from the Gemini API.
		 * @param array $fallback The fallback response in case of errors.
		 * @param array $known_tool_names Registered tool names for this turn, used to validate
		 *                                a pseudocode-text tool-call fallback match; see
		 *                                WPSC_AIBOT_Tool_Utils::extract_gemini_tool_call().
		 * @return array The processed response or the fallback response on error.
		 */
		public function process_chat_response( $response, $fallback, $known_tool_names = array() ) {

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( is_wp_error( $response ) || 200 !== $status_code ) {
				return $fallback;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $body ) ) {
				return $fallback;
			}

			$prompt_tokens = ! empty( $body['usageMetadata']['promptTokenCount'] ) ? (int) $body['usageMetadata']['promptTokenCount'] : 0;
			$completion_tokens = ! empty( $body['usageMetadata']['candidatesTokenCount'] ) ? (int) $body['usageMetadata']['candidatesTokenCount'] : 0;
			$total_tokens = ! empty( $body['usageMetadata']['totalTokenCount'] ) ? (int) $body['usageMetadata']['totalTokenCount'] : 0;

			if ( ! empty( $body['promptFeedback']['blockReason'] ) ) {
				return $fallback;
			}

			$tool_call = class_exists( 'WPSC_AIBOT_Tool_Utils' )
				? WPSC_AIBOT_Tool_Utils::extract_gemini_tool_call( $body, $known_tool_names )
				: null;

			if ( ! empty( $tool_call ) ) {
				if ( class_exists( 'WPSC_AIBOT_Tool_Utils' ) ) {
					return WPSC_AIBOT_Tool_Utils::make_tool_call_response( $tool_call, $prompt_tokens, $completion_tokens, $total_tokens );
				}

				return array(
					'success'           => true,
					'response'          => '',
					'create_ticket'     => false,
					'tool_call'         => $tool_call,
					'prompt_tokens'     => $prompt_tokens,
					'completion_tokens' => $completion_tokens,
					'total_tokens'      => $total_tokens,
				);
			}

			return array(
				'success'           => true,
				'response'          => self::extract_text_reply( $body ),
				'create_ticket'     => false,
				'tool_call'         => '',
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'total_tokens'      => $total_tokens,
			);
		}

		/**
		 * Format the conversation history and user message for API consumption.
		 *
		 * @param array  $conversation_history The conversation history to provide context to the AI.
		 * @param string $message The user message to send to the AI.
		 * @return array The formatted chat messages ready for API consumption.
		 */
		public function wpsc_get_api_formatted_chat_messages( $conversation_history, $message ) {

			$conversation_history = is_array( $conversation_history ) ? array_slice( $conversation_history, -6 ) : array();
			$contents = array();

			foreach ( $conversation_history as $history_item ) {

				$role = $history_item['role'] ?? ( $history_item['sender'] ?? '' );
				$content = $history_item['content'] ?? ( $history_item['message'] ?? null );

				if ( is_array( $content ) ) {
					$content = wp_json_encode( $content );
				}

				if ( empty( $role ) || $content === null ) {
					continue;
				}

				$role = sanitize_key( (string) $role );
				$role = 'assistant' === $role ? 'model' : 'user';

				$text = trim( (string) $content );
				if ( '' === $text ) {
					continue;
				}

				$contents[] = array(
					'role'  => $role,
					'parts' => array(
						array(
							'text' => $text,
						),
					),
				);
			}

			return $contents ?? array();
		}

		/**
		 * Generate a subject line for a chat conversation based on the provided system prompt and conversation history.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $system_prompt The system prompt to guide the AI's response.
		 * @param string $conversation_text The conversation history to provide context to the AI.
		 * @return array The result array containing success status, subject, and provider.
		 */
		public function generate_chat_conversation_subject_and_summary( $ai_settings, $system_prompt, $conversation_text ) {

			if ( empty( $conversation_text ) ) {
				return array(
					'success'  => false,
					'subject'  => __( 'Conversation history is empty.', 'wpsc-ps' ),
					'provider' => WPSC_PS_AIT_Provider::GOOGLE_GEMINI,
				);
			}

			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gemini-1.5-pro-latest';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 100;

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
							array(
								'text' => $conversation_text,
							),
						),
					),
				),
				'generationConfig'   => array(
					'temperature'     => 0.2,
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
						'body'      => wp_json_encode( $request_body ),
					)
				);

				if ( WPSC_PS_AI_Gemini::is_retryable_response( $response ) ) {

					$status_code = wp_remote_retrieve_response_code( $response );
					if ( $attempt < 3 ) {
						sleep( 1 );
						continue;
					}
				}

				return $this->process_generate_summary_response( $response );
			}

			return array(
				'success'  => false,
				'subject'  => __( 'Failed to generate subject.', 'wpsc-ps' ),
				'provider' => WPSC_PS_AIT_Provider::GOOGLE_GEMINI,
			);
		}

		/**
		 * Process the response from the Gemini API for generating a summary and extract relevant information.
		 *
		 * @param array $response The response from the Gemini API.
		 * @return array The processed response containing success status, subject, and provider.
		 */
		private function process_generate_summary_response( $response ) {

			if ( is_wp_error( $response ) ) {
				return array(
					'success'  => false,
					'subject'  => $response->get_error_message(),
					'provider' => WPSC_PS_AIT_Provider::GOOGLE_GEMINI,
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				$error_message = ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Google Gemini API request failed.', 'wpsc-ps' );
				return array(
					'success'  => false,
					'subject'  => $error_message,
					'provider' => WPSC_PS_AIT_Provider::GOOGLE_GEMINI,
				);
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
				return array(
					'success'  => false,
					'subject'  => __( 'No subject generated by Gemini.', 'wpsc-ps' ),
					'provider' => WPSC_PS_AIT_Provider::GOOGLE_GEMINI,
				);
			}

			$subject = trim( $body['candidates'][0]['content']['parts'][0]['text'] );
			$subject = preg_replace( '/[\r\n]+/', ' ', $subject );
			$subject = trim( wp_strip_all_tags( $subject ) );

			return array(
				'success'  => true,
				'subject'  => $subject,
				'provider' => WPSC_PS_AIT_Provider::GOOGLE_GEMINI,
			);
		}

		/**
		 * Analyze the conversation subject and status based on the provided system prompt and conversation history.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $system_prompt The system prompt to guide the AI's response.
		 * @param string $conversation_text The conversation history to provide context to the AI.
		 * @return array|false The analysis result with subject and status or false on failure.
		 */
		public function wpsc_analyze_conversation_subject_status( $ai_settings, $system_prompt, $conversation_text ) {

			if ( empty( trim( $conversation_text ) ) ) {
				return array(
					'subject' => __( 'Conversation history is empty.', 'wpsc-ps' ),
					'status'  => 'inactive',
				);
			}

			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gemini-1.5-pro-latest';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 150;
			$prompt = $system_prompt . "\n\nConversation:\n\n" . $conversation_text;

			$request_body = array(
				'contents'         => array(
					array(
						'role'  => 'user',
						'parts' => array(
							array(
								'text' => $prompt,
							),
						),
					),
				),
				'generationConfig' => array(
					'temperature'     => 0.1,
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
						'body'      => wp_json_encode( $request_body ),
					)
				);

				if ( WPSC_PS_AI_Gemini::is_retryable_response( $response ) ) {

					$status_code = wp_remote_retrieve_response_code( $response );
					if ( $attempt < 3 ) {
						sleep( 1 );
						continue;
					}
				}

				return $this->process_subject_status_response( $response );
			}

			return array(
				'subject' => __( 'Failed to analyze conversation.', 'wpsc-ps' ),
				'status'  => 'inactive',
			);
		}

		/**
		 * Process the response from the Gemini API for analyzing conversation subject and status.
		 *
		 * @param array $response The response from the Gemini API.
		 * @return array The processed response containing subject and status.
		 */
		private function process_subject_status_response( $response ) {

			if ( is_wp_error( $response ) ) {
				return array(
					'subject' => $response->get_error_message(),
					'status'  => 'inactive',
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				$error_message = ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Google Gemini API request failed.', 'wpsc-ps' );
				return array(
					'subject' => $error_message,
					'status'  => 'inactive',
				);
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
				return array(
					'subject' => __( 'No analysis received from Gemini.', 'wpsc-ps' ),
					'status'  => 'inactive',
				);
			}
			$reply = trim( $body['candidates'][0]['content']['parts'][0]['text'] );
			$result = json_decode( $reply, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $result ) ) {
				return array(
					'subject' => __( 'Invalid AI response format.', 'wpsc-ps' ),
					'status'  => 'inactive',
				);
			}

			$subject = ! empty( $result['subject'] ) ? sanitize_text_field( $result['subject'] ) : __( 'General Inquiry', 'wpsc-ps' );
			$status = ! empty( $result['status'] ) ? sanitize_key( $result['status'] ) : 'inactive';
			return array(
				'subject' => $subject,
				'status'  => $status,
			);
		}

		/**
		 * Search the knowledge base using the Gemini API.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $prompt The search prompt to send to the AI.
		 * @param string $query The search query derived from the user request.
		 * @return array The search results or an error message.
		 */
		public function search_knowledge_base( $ai_settings, $prompt, $query ) {

			$not_found = array(
				'success' => true,
				'found'   => false,
			);
			$error = array(
				'success' => false,
				'error'   => 'knowledge_base_unavailable',
			);

			$store_name = $this->wpsc_provider_store_id( $ai_settings['api_key'] );
			$store_name = is_string( $store_name ) ? $store_name : '';
			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gemini-1.5-pro-latest';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 450;
			$conversation_history = WPSC_ACB_Chats::get_conversation_history();
			$contents = $this->wpsc_get_api_formatted_chat_messages( $conversation_history, '' );

			$request_body = array(
				'system_instruction' => array(
					'parts' => array(
						array(
							'text' => $prompt,
						),
					),
				),
				'contents'           => $contents,
				'generationConfig'   => array(
					'temperature'     => 0.1,
					'maxOutputTokens' => $max_tokens,
				),
				'tools'              => array(
					array(
						'file_search' => array(
							'file_search_store_names' => array( sanitize_text_field( $store_name ) ),
						),
					),
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
						'body'      => wp_json_encode( $request_body ),
					)
				);

				if ( WPSC_PS_AI_Gemini::is_retryable_response( $response ) ) {
					if ( $attempt < 3 ) {
						sleep( 1 );
						continue;
					}
				}

				return $this->process_knowledge_base_response(
					$response,
					$error,
					$not_found
				);
			}
			return $error;
		}

		/**
		 * Process the knowledge base response from the Gemini API and extract relevant information.
		 *
		 * Returns structured data only (no pre-rendered HTML) - this is itself a
		 * nested LLM call whose synthesized answer is fed back as a tool result
		 * into the outer agentic loop, which composes the final user-facing
		 * reply in the user's own language.
		 *
		 * @param array $response The response from the Gemini API.
		 * @param array $error The error response to use on request/parse failure.
		 * @param array $not_found The response to use when no matching answer was found.
		 * @return array
		 */
		private function process_knowledge_base_response( $response, $error, $not_found ) {

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( is_wp_error( $response ) || 200 !== $status_code ) {
				return $error;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $body['promptFeedback']['blockReason'] ) ) {
				return $error;
			}

			$reply = self::extract_text_reply( $body );
			$reply = trim( wp_strip_all_tags( $reply ) );
			$reply_check = strtoupper( $reply );
			if ( '[NO_KB_FOUND]' === $reply_check || '' === $reply_check ) {
				return $not_found;
			}

			return array(
				'success' => true,
				'found'   => true,
				'answer'  => $reply,
			);
		}

		/**
		 * Extract assistant text from Gemini response body.
		 *
		 * @param array $body Gemini response body.
		 * @return string
		 */
		private static function extract_text_reply( $body ) {

			$reply = '';
			foreach ( $body['candidates'] ?? array() as $candidate ) {
				foreach ( $candidate['content']['parts'] ?? array() as $part ) {
					if ( ! empty( $part['text'] ) && is_string( $part['text'] ) ) {
						$reply .= $part['text'];
					}
				}
			}

			return trim( $reply );
		}
	}
endif;
