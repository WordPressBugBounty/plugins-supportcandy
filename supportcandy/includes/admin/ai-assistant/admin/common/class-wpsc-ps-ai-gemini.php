<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Gemini' ) ) :

	final class WPSC_PS_AI_Gemini {

		/**
		 * Mark a training file as failed with an error message
		 *
		 * @param string $api_key API key for authentication.
		 * @return mixed
		 */
		public static function wpsc_provider_store_id( $api_key ) {

			// Try fetching stored ID.
			$stored = get_option( 'wpsc_gemini_file_search_store_id', '' );
			if ( ! empty( $stored ) && is_string( $stored ) ) {
				return sanitize_text_field( $stored );
			}

			// API endpoint (IMPORTANT: API key in URL).
			$url = add_query_arg(
				array( 'key' => $api_key ),
				'https://generativelanguage.googleapis.com/v1beta/fileSearchStores'
			);

			// Request body.
			$body = array(
				'displayName' => 'SupportCandy_KB_' . wp_generate_password( 6, false ),
			);

			$response = self::wpsc_remote_post( $url, $body );

			// Handle error.
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Validate response.
			if ( empty( $response['name'] ) ) {
				return new WP_Error( 'invalid_response', __( 'Invalid API response: missing store ID.', 'wpsc-ps' ) );
			}

			$file_search_store_id = sanitize_text_field( $response['name'] );

			// Save option (no autoload for performance).
			update_option( 'wpsc_gemini_file_search_store_id', $file_search_store_id, false );
			return $file_search_store_id;
		}

		/**
		 * Clear the cached file search store ID so the next call to wpsc_provider_store_id()
		 * creates a fresh store under whichever API key/project is currently configured.
		 * Needed because file search stores are project-scoped in Google AI: a cached ID
		 * created under one project is not reachable from a key belonging to a different
		 * project (e.g. after rotating to a new API key), and it is never re-validated on
		 * its own.
		 *
		 * @return void
		 */
		public static function clear_stored_file_search_store_id() {

			delete_option( 'wpsc_gemini_file_search_store_id' );
		}

		/**
		 * Resolve the model to use for retry attempts based on the current attempt number.
		 *
		 * @param string $model The original model name.
		 * @param int    $attempt The current attempt number (1-based).
		 * @return string The resolved model name for the retry attempt.
		 */
		public static function resolve_retry_model( string $model, int $attempt ): string {

			switch ( $attempt ) {
				case 1:
					return 'gemini-2.5-flash-lite';
				case 2:
					return 'gemini-2.5-flash';
				default:
					return 'gemini-2.5-pro';
			}
		}

		/**
		 * Determine if the response from the API is retryable based on status code or error.
		 *
		 * @param array $response The response from the API.
		 * @return bool True if the response is retryable, false otherwise.
		 */
		public static function is_retryable_response( $response ) {

			if ( is_wp_error( $response ) ) {
				return true;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( $status_code >= 500 ) {
				return true;
			}

			if ( 429 === $status_code ) {
				return true;
			}

			return false;
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
	}
endif;
