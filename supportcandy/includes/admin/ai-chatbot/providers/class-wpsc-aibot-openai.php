<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AIBOT_OpenAI' ) ) :

	final class WPSC_PS_AIBOT_OpenAI implements WPSC_PS_AIBOT_Provider_Interface {

		/**
		 * Get the store ID for a given provider.
		 *
		 * @param string $api_key The API key for authentication.
		 * @return string The store ID associated with the provider.
		 */
		public function wpsc_provider_store_id( $api_key ) {

			return WPSC_PS_AI_OpenAI::wpsc_provider_store_id( $api_key );
		}

		/**
		 * Get a chat response from OpenAI API based on the provided message.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $message The user message to send to the AI.
		 * @param string $system_prompt The system prompt to guide the AI's response.
		 * @param array  $conversation_history The conversation history to provide context to the AI.
		 * @param array  $tools Optional tools to include in the request (e.g., function calling).
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

			$api_key = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';
			if ( '' === $api_key ) {
				return $fallback;
			}

			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gpt-4o-mini';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 500;

			$store_id = $this->wpsc_provider_store_id( $api_key );
			if ( is_wp_error( $store_id ) || empty( $store_id ) ) {
				return $fallback;
			}

			$openai_tools = class_exists( 'WPSC_AIBOT_Tool_Utils' ) ? WPSC_AIBOT_Tool_Utils::build_openai_tools( $store_id, $tools ) : array();

			$is_continuation = is_array( $tool_context ) && is_array( $tool_context['input'] ?? null );

			if ( $is_continuation ) {

				// Continue an agentic tool-calling loop: replay the prior turn's
				// input, echo back the model's own function call, then append the
				// tool's structured result so the model can observe it.
				$input = $tool_context['input'];
				$tool_call = is_array( $tool_context['tool_call'] ?? null ) ? $tool_context['tool_call'] : array();
				$call_id = ! empty( $tool_call['call_id'] ) ? sanitize_text_field( (string) $tool_call['call_id'] ) : '';

				if ( '' !== $call_id ) {
					$input[] = array(
						'type'      => 'function_call',
						'call_id'   => $call_id,
						'name'      => sanitize_key( (string) ( $tool_call['name'] ?? '' ) ),
						'arguments' => wp_json_encode( is_array( $tool_call['arguments'] ?? null ) ? $tool_call['arguments'] : array() ),
					);
					$input[] = array(
						'type'    => 'function_call_output',
						'call_id' => $call_id,
						'output'  => wp_json_encode( is_array( $tool_context['tool_result'] ?? null ) ? $tool_context['tool_result'] : array() ),
					);
				}

				$requested_choice = $tool_context['tool_choice'] ?? 'auto';
				$tool_choice = 'none' === $requested_choice ? 'none' : 'auto';
			} else {

				$input = $this->wpsc_get_api_formatted_chat_messages( $conversation_history, $message );
				array_unshift(
					$input,
					array(
						'role'    => 'system',
						'content' => array(
							array(
								'type' => 'input_text',
								'text' => (string) $system_prompt,
							),
						),
					)
				);
				$input[] = array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $message,
						),
					),
				);

				$tool_choice = ! empty( $openai_tools ) ? 'required' : 'auto';
			}

			// 'none' tells the model it must not call a function on this turn -
			// used to force final synthesis once the agentic loop must stop.
			$request_body = array(
				'model'             => $model,
				'input'             => $input,
				'text'              => array(
					'format' => array(
						'type'   => 'json_schema',
						'name'   => 'chat_response',
						'schema' => array(
							'type'                 => 'object',
							'properties'           => array(
								'response'      => array(
									'type' => 'string',
								),
								'create_ticket' => array(
									'type' => 'boolean',
								),
							),
							'required'             => array(
								'response',
								'create_ticket',
							),
							'additionalProperties' => false,
						),
					),
				),
				'tool_choice'       => $tool_choice,
				'tools'             => $openai_tools,
				'max_output_tokens' => $max_tokens,
			);

			$max_retries = isset( $tool_context['max_retries'] ) ? max( 1, (int) $tool_context['max_retries'] ) : 3;

			for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {

				$attempt_model = WPSC_PS_AI_OpenAI::resolve_retry_model(
					$model,
					$attempt
				);

				$attempt_body = $request_body;
				$attempt_body['model'] = $attempt_model;

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
						'body'        => wp_json_encode( $attempt_body ),
						'data_format' => 'body',
					)
				);

				if ( WPSC_PS_AI_OpenAI::is_retryable_response( $response ) ) {
					if ( $attempt < $max_retries ) {
						sleep( 1 );
						continue;
					}
				}

				$result = $this->process_chat_response(
					$response,
					$fallback
				);

				if ( ! empty( $result['success'] ) ) {
					$result['input'] = $input;
				}

				return $result;
			}

			return $fallback;
		}

		/**
		 * Process the response from the OpenAI API and extract relevant information.
		 *
		 * @param array $response The response from the OpenAI API.
		 * @param array $fallback The fallback response in case of errors.
		 * @return array The processed response or the fallback response on error.
		 */
		public function process_chat_response( $response, $fallback ) {

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( is_wp_error( $response ) || 200 !== $status_code ) {
				return $fallback;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $body ) ) {
				return $fallback;
			}

			if ( ! empty( $body['error']['message'] ) ) {
				return $fallback;
			}

			$prompt_tokens = ! empty( $body['usage']['input_tokens'] ) ? (int) $body['usage']['input_tokens'] : 0;
			$completion_tokens = ! empty( $body['usage']['output_tokens'] ) ? (int) $body['usage']['output_tokens'] : 0;
			$total_tokens = ! empty( $body['usage']['total_tokens'] ) ? (int) $body['usage']['total_tokens'] : 0;

			$tool_call = class_exists( 'WPSC_AIBOT_Tool_Utils' )
				? WPSC_AIBOT_Tool_Utils::extract_openai_tool_call( $body )
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

			$parsed = self::extract_structured_chat_reply( $this->extract_text_from_openai_response( $body ) );

			return array(
				'success'           => true,
				'response'          => $parsed['response'],
				'create_ticket'     => $parsed['create_ticket'],
				'tool_call'         => '',
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'total_tokens'      => $total_tokens,
			);
		}

		/**
		 * Parse the model's final text output against the 'chat_response'
		 * json_schema requested in the request body ({response, create_ticket}).
		 * Falls back to treating the raw text as the response if it isn't
		 * valid JSON (defensive - shouldn't happen given the enforced schema).
		 *
		 * @param string $text Raw text output from the OpenAI response.
		 * @return array{response: string, create_ticket: bool}
		 */
		private static function extract_structured_chat_reply( $text ) {

			$text = trim( (string) $text );
			$decoded = json_decode( $text, true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) && isset( $decoded['response'] ) && is_string( $decoded['response'] ) ) {
				return array(
					'response'      => $decoded['response'],
					'create_ticket' => ! empty( $decoded['create_ticket'] ),
				);
			}

			return array(
				'response'      => $text,
				'create_ticket' => false,
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

			// The current turn's message is cached before wpsc_get_chat_response()
			// runs, so it is typically already the last history entry. Drop it here
			// so the caller's own explicit "current message" turn isn't duplicated.
			$message = is_string( $message ) ? trim( $message ) : '';
			if ( '' !== $message && ! empty( $conversation_history ) ) {

				$last_item = end( $conversation_history );
				$last_role = sanitize_key( (string) ( $last_item['role'] ?? ( $last_item['sender'] ?? '' ) ) );
				$last_content = trim( (string) ( $last_item['content'] ?? ( $last_item['message'] ?? '' ) ) );

				if ( 'user' === $last_role && $last_content === $message ) {
					array_pop( $conversation_history );
				}
			}

			$input = array();

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
				$role = 'assistant' === $role ? 'assistant' : 'user';
				$content_type = 'assistant' === $role ? 'output_text' : 'input_text';

				$text = trim( (string) $content );
				if ( '' === $text ) {
					continue;
				}

				$input[] = array(
					'role'    => $role,
					'content' => array(
						array(
							'type' => $content_type,
							'text' => $text,
						),
					),
				);
			}

			return $input ?? array();
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
					'provider' => WPSC_PS_AIT_Provider::OPENAI,
				);
			}

			$api_key = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';
			if ( '' === $api_key ) {
				return array(
					'success'  => false,
					'subject'  => __( 'OpenAI API key is missing.', 'wpsc-ps' ),
					'provider' => WPSC_PS_AIT_Provider::OPENAI,
				);
			}

			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gpt-4o-mini';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 100;

			$request_body = array(
				'model'             => $model,
				'input'             => array(
					array(
						'role'    => 'system',
						'content' => array(
							array(
								'type' => 'input_text',
								'text' => $system_prompt,
							),
						),
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'input_text',
								'text' => $conversation_text,
							),
						),
					),
				),
				'max_output_tokens' => $max_tokens,
			);

			for ( $attempt = 1; $attempt <= 3; $attempt++ ) {

				$attempt_model = WPSC_PS_AI_OpenAI::resolve_retry_model(
					$model,
					$attempt
				);

				$attempt_body = $request_body;
				$attempt_body['model'] = $attempt_model;

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
						'body'        => wp_json_encode( $attempt_body ),
						'data_format' => 'body',
					)
				);

				if ( WPSC_PS_AI_OpenAI::is_retryable_response( $response ) ) {
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
				'provider' => WPSC_PS_AIT_Provider::OPENAI,
			);
		}

		/**
		 * Process the response from the OpenAI API for generating a summary and extract relevant information.
		 *
		 * @param array $response The response from the OpenAI API.
		 * @return array The processed response containing success status, subject, and provider.
		 */
		private function process_generate_summary_response( $response ) {

			if ( is_wp_error( $response ) ) {
				return array(
					'success'  => false,
					'subject'  => $response->get_error_message(),
					'provider' => WPSC_PS_AIT_Provider::OPENAI,
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				$error_message = ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'OpenAI API request failed.', 'wpsc-ps' );
				return array(
					'success'  => false,
					'subject'  => $error_message,
					'provider' => WPSC_PS_AIT_Provider::OPENAI,
				);
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$subject = $this->extract_text_from_openai_response( $body );
			if ( '' === $subject ) {
				return array(
					'success'  => false,
					'subject'  => __( 'No subject generated by OpenAI.', 'wpsc-ps' ),
					'provider' => WPSC_PS_AIT_Provider::OPENAI,
				);
			}

			$subject = preg_replace( '/[\r\n]+/', ' ', $subject );
			$subject = trim( wp_strip_all_tags( $subject ) );

			return array(
				'success'  => true,
				'subject'  => $subject,
				'provider' => WPSC_PS_AIT_Provider::OPENAI,
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

			$api_key = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';

			if ( empty( trim( $conversation_text ) ) ) {
				return array(
					'subject' => __( 'Conversation history is empty.', 'wpsc-ps' ),
					'status'  => 'inactive',
				);
			}

			if ( '' === $api_key ) {
				return array(
					'subject' => __( 'OpenAI API key is missing.', 'wpsc-ps' ),
					'status'  => 'inactive',
				);
			}

			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gpt-4o-mini';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 150;
			$prompt = $system_prompt . "\n\nConversation:\n\n" . $conversation_text;

			$request_body = array(
				'model'             => $model,
				'input'             => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'input_text',
								'text' => $prompt,
							),
						),
					),
				),
				'max_output_tokens' => $max_tokens,
			);

			for ( $attempt = 1; $attempt <= 3; $attempt++ ) {

				$attempt_model = WPSC_PS_AI_OpenAI::resolve_retry_model(
					$model,
					$attempt
				);

				$attempt_body = $request_body;
				$attempt_body['model'] = $attempt_model;

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
						'body'        => wp_json_encode( $attempt_body ),
						'data_format' => 'body',
					)
				);

				if ( WPSC_PS_AI_OpenAI::is_retryable_response( $response ) ) {
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
		 * Process the response from the OpenAI API for analyzing conversation subject and status.
		 *
		 * @param array $response The response from the OpenAI API.
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
				$error_message = ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'OpenAI API request failed.', 'wpsc-ps' );
				return array(
					'subject' => $error_message,
					'status'  => 'inactive',
				);
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$reply = $this->extract_text_from_openai_response( $body );
			if ( '' === $reply ) {
				return array(
					'subject' => __( 'No analysis received from OpenAI.', 'wpsc-ps' ),
					'status'  => 'inactive',
				);
			}
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
		 * Search the knowledge base using OpenAI API.
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

			$api_key = isset( $ai_settings['api_key'] ) ? trim( $ai_settings['api_key'] ) : '';
			if ( '' === $api_key ) {
				return $error;
			}

			$store_id = $this->wpsc_provider_store_id( $api_key );
			if ( is_wp_error( $store_id ) || empty( $store_id ) ) {
				return $error;
			}

			$model = ! empty( $ai_settings['model'] ) ? sanitize_text_field( $ai_settings['model'] ) : 'gpt-4o-mini';
			$max_tokens = ! empty( $ai_settings['max-tokens'] ) && (int) $ai_settings['max-tokens'] > 0 ? (int) $ai_settings['max-tokens'] : 450;
			$conversation_history = WPSC_ACB_Chats::get_conversation_history();
			$input = $this->wpsc_get_api_formatted_chat_messages( $conversation_history, '' );

			array_unshift(
				$input,
				array(
					'role'    => 'system',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => (string) $prompt,
						),
					),
				)
			);

			$query = is_string( $query ) ? trim( $query ) : '';
			if ( '' !== $query ) {
				$input[] = array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $query,
						),
					),
				);
			}

			$request_body = array(
				'model'             => $model,
				'input'             => $input,
				'tool_choice'       => 'auto',
				'tools'             => array(
					array(
						'type'             => 'file_search',
						'vector_store_ids' => array( sanitize_text_field( (string) $store_id ) ),
						'max_num_results'  => 8,
						'ranking_options'  => array(
							'score_threshold' => 0.4,
						),
					),
				),
				'max_output_tokens' => $max_tokens,
			);

			for ( $attempt = 1; $attempt <= 3; $attempt++ ) {

				$attempt_model = WPSC_PS_AI_OpenAI::resolve_retry_model(
					$model,
					$attempt
				);

				$attempt_body = $request_body;
				$attempt_body['model'] = $attempt_model;

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
						'body'        => wp_json_encode( $attempt_body ),
						'data_format' => 'body',
					)
				);

				if ( WPSC_PS_AI_OpenAI::is_retryable_response( $response ) ) {
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
		 * Process the knowledge base response from the OpenAI API and extract relevant information.
		 *
		 * Returns structured data only (no pre-rendered HTML) - this is itself a
		 * nested LLM call whose synthesized answer is fed back as a tool result
		 * into the outer agentic loop, which composes the final user-facing
		 * reply in the user's own language.
		 *
		 * @param array $response The response from the OpenAI API.
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
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $body ) ) {
				return $error;
			}

			if ( ! empty( $body['error']['message'] ) ) {
				return $error;
			}

			$reply = $this->extract_text_from_openai_response( $body );
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
		 * Extract assistant text from OpenAI response body.
		 *
		 * @param array $body OpenAI response body.
		 * @return string
		 */
		private function extract_text_from_openai_response( $body ) {

			$reply = '';

			if ( ! empty( $body['output_text'] ) && is_string( $body['output_text'] ) ) {
				$reply = trim( $body['output_text'] );
			}

			if ( '' !== $reply ) {
				return $reply;
			}

			foreach ( $body['output'] ?? array() as $output ) {
				if ( ! empty( $output['type'] ) && 'message' !== $output['type'] ) {
					continue;
				}

				foreach ( $output['content'] ?? array() as $content ) {
					if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
						$reply .= $content['text'];
					} elseif ( isset( $content['output_text'] ) && is_string( $content['output_text'] ) ) {
						$reply .= $content['output_text'];
					}
				}
			}

			return trim( $reply );
		}
	}
endif;
