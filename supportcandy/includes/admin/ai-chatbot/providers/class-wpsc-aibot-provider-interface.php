<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! interface_exists( 'WPSC_PS_AIBOT_Provider_Interface' ) ) :

	interface WPSC_PS_AIBOT_Provider_Interface {

		/**
		 * Get the store ID for a given provider.
		 *
		 * @param string $api_key The API key of the provider.
		 * @return string The store ID associated with the provider.
		 */
		public function wpsc_provider_store_id( $api_key );

		/**
		 * Get a chat response from OpenAI API based on the provided message.
		 *
		 * Supports a multi-iteration agentic tool-calling loop via $tool_context.
		 * On the first call (no $tool_context), the provider builds a fresh
		 * request and returns its provider-native running conversation state in
		 * the response (e.g. 'input' for OpenAI, 'contents' for Gemini). To
		 * continue the loop after executing a tool, the caller passes that state
		 * back along with the executed tool call and its structured result via
		 * $tool_context = array('input'|'contents' => ..., 'tool_call' => array,
		 * 'tool_result' => array, 'tool_choice' => 'auto'|'none', 'max_retries' => int).
		 * The provider appends the tool call/result turn in its own native
		 * format and calls the model again.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $message The user message to send to the AI.
		 * @param string $system_prompt The system prompt to guide the AI's response.
		 * @param array  $conversation_history The conversation history to provide context to the AI.
		 * @param array  $tools Optional tool/function definitions for providers that support function-calling.
		 * @param array  $tool_context Optional agentic-loop continuation state (see above). Empty for the first call in a turn.
		 * @return array|false The response payload (e.g. success/response/token usage) from the AI provider or false on failure.
		 */
		public function wpsc_get_chat_response( $ai_settings, $message, $system_prompt = '', $conversation_history = array(), $tools = array(), $tool_context = array() );

		/**
		 * Generate a subject line for a chat conversation based on the provided system prompt and conversation history.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $system_prompt The system prompt to guide the AI's response.
		 * @param string $conversation_text The conversation history to provide context to the AI.
		 * @return array The result array containing success status, subject, and provider.
		 */
		public function generate_chat_conversation_subject_and_summary( $ai_settings, $system_prompt, $conversation_text );

		/**
		 * Analyze the conversation subject and status based on the provided system prompt and conversation history.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $system_prompt The system prompt to guide the AI's response.
		 * @param string $conversation_text The conversation history to provide context to the AI.
		 * @return array|false The analysis result with subject and status or false on failure.
		 */
		public function wpsc_analyze_conversation_subject_status( $ai_settings, $system_prompt, $conversation_text );

		/**
		 * Format the conversation history and user message for API consumption.
		 *
		 * @param array  $conversation_history The conversation history to provide context to the AI.
		 * @param string $message The user message to send to the AI.
		 * @return array The formatted chat messages ready for API consumption.
		 */
		public function wpsc_get_api_formatted_chat_messages( $conversation_history, $message );

		/**
		 * Search the knowledge base using the Gemini API.
		 *
		 * @param array  $ai_settings AI settings array.
		 * @param string $prompt The search prompt to send to the AI.
		 * @param string $query The search query derived from the user request.
		 * @return array The search results or an error message.
		 */
		public function search_knowledge_base( $ai_settings, $prompt, $query );
	}
endif;
