<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Functions' ) ) :

	final class WPSC_PS_AI_Functions {

		/**
		 * Build the prompt for the AI model from a conversation array.
		 *
		 * @param array $row_description_reply An array of messages with 'role' and 'content' keys representing the conversation history.
		 * @return string The formatted prompt.
		 */
		public static function wpsc_build_prompt_using_agent_ticket_conversation( $row_description_reply ) {

			$prompt = '';
			if ( ! is_array( $row_description_reply ) ) {
				return '';
			}
			foreach ( $row_description_reply as $msg ) {
				$prompt .= ucfirst( $msg['role'] ) . ': ' . trim( $msg['content'] ) . "\n";
			}
			$prompt = trim( $prompt );
			return '"""' . "\n" . $prompt . "\n" . '"""';
		}

		/**
		 * Build the prompt for the AI model by combining the agent's draft reply and recent ticket history,
		 * while cleaning the content for better AI understanding.
		 *
		 * @param string $draft   The agent's draft reply that needs improvement.
		 * @param string $history The recent ticket history for context.
		 * @return string The final prompt to be sent to the AI model.
		 */
		public static function wpsc_prompt_to_generate_ai_reply( $draft, $history ) {

			$prompt = '';
			$draft = self::wpsc_clean_thread_content( $draft );
			if ( ! empty( $history ) ) {
				$prompt .= "Ticket Context:\n\"\"\"\n" . $history . "\n\"\"\"\n\n";
			}
			$prompt .= "Agent Draft Reply:\n\"\"\"\n" . $draft . "\n\"\"\"";
			return $prompt;
		}


		/**
		 * Clean and retrieve the recent ticket history for AI context.
		 * Removing HTML, quoted text, signatures, and masking sensitive data.
		 *
		 * @param int $ticket_id The ID of the ticket.
		 * @param int $limit_threads The number of recent threads to include in the history.
		 * @return string
		 */
		public static function wpsc_get_clean_ticket_history( $ticket_id, $limit_threads = 2 ) {

			if ( empty( $ticket_id ) ) {
				return '';
			}

			$threads = WPSC_Thread::find(
				array(
					'items_per_page' => $limit_threads,
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'ticket',
							'compare' => '=',
							'val'     => $ticket_id,
						),
						array(
							'slug'    => 'type',
							'compare' => 'IN',
							'val'     => array( 'report', 'reply' ),
						),
						array(
							'slug'    => 'is_active',
							'compare' => '=',
							'val'     => 1,
						),
					),
					'orderby'        => 'id',
					'order'          => 'DESC',
				)
			);

			if ( empty( $threads['total_items'] ) ) {
				return '';
			}

			$history = array();
			foreach ( $threads['results'] as $thread ) {

				$clean_body = self::wpsc_clean_thread_content( $thread->body );
				if ( empty( $clean_body ) ) {
					continue;
				}

				$agent = WPSC_Agent::get_by_customer( $thread->customer );
				$author = $agent->id ? 'Agent' : 'Customer';
				$history[] = sprintf(
					"%s (%s):\n%s",
					$author . ' (' . $thread->customer->name . ')',
					$thread->date_created->format( 'Y-m-d H:i' ),
					$clean_body
				);
			}

			$history = array_reverse( $history );
			$final = implode( "\n\n", $history );
			return trim( $final );
		}

		/**
		 * Strip a leading/trailing markdown code fence (e.g. ```html ... ``` or ``` ... ```) that an AI
		 * model may wrap its reply in, despite being instructed not to.
		 *
		 * @param string $content The AI-generated content to clean.
		 * @return string The content with any surrounding markdown code fence removed.
		 */
		public static function wpsc_strip_ai_markdown_fences( $content ) {

			if ( empty( $content ) || ! is_string( $content ) ) {
				return $content;
			}

			$content = trim( $content );
			$content = preg_replace( '/^```[a-zA-Z]*\s*\n?/', '', $content );
			$content = preg_replace( '/\n?```\s*$/', '', $content );
			return trim( $content );
		}

		/**
		 * Clean thread content by removing HTML tags, quoted replies, signatures, and masking sensitive data.
		 *
		 * @param string $content The original thread content.
		 * @return string The cleaned content.
		 */
		public static function wpsc_clean_thread_content( $content ) {

			if ( empty( $content ) ) {
				return '';
			}

			$content = wp_strip_all_tags( $content );
			$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$content = preg_replace( "/\r\n|\r/", "\n", $content );

			$content = preg_split( '/On .* wrote:/i', $content )[0];
			$content = preg_split( '/From: .*$/mi', $content )[0];
			$content = preg_split( '/^>.*$/m', $content )[0];

			$signature_patterns = array(
				'/--\s*\n.*/s',
				'/Thanks.*$/is',
				'/Regards.*$/is',
				'/Best regards.*$/is',
			);

			foreach ( $signature_patterns as $pattern ) {
				$content = preg_replace( $pattern, '', $content );
			}

			$content = trim( $content );
			$content = self::wpsc_mask_sensitive_content( $content );
			$content = preg_replace( "/\n{3,}/", "\n\n", $content );
			return trim( $content );
		}

		/**
		 * Mask sensitive content such as emails, URLs, IP addresses, and potential passwords in the content.
		 *
		 * @param string $content The content to be masked.
		 * @return string The content with sensitive content masked.
		 */
		public static function wpsc_mask_sensitive_content( $content ) {
			if ( empty( $content ) || ! is_string( $content ) ) {
				return $content;
			}

			$patterns = array(
				'email'                => array(
					'pattern'     => '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i',
					'replacement' => '[email]',
				),
				'url_http'             => array(
					'pattern'     => '/\bhttps?:\/\/[^\s<>"\']+/i',
					'replacement' => '[url]',
				),
				'url_www'              => array(
					'pattern'     => '/\bwww\.[a-z0-9\-]+\.[^\s<>"\']+/i',
					'replacement' => '[url]',
				),
				'ipv4'                 => array(
					'pattern'     => '/\b(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\b/',
					'replacement' => '[ip]',
				),
				'ipv6'                 => array(
					'pattern'     => '/\b(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}\b/i',
					'replacement' => '[ip]',
				),
				'jwt'                  => array(
					'pattern'     => '/\beyJ[a-z0-9\-_]+\.[a-z0-9\-_]+\.[a-z0-9\-_]+/i',
					'replacement' => '[jwt]',
				),
				'password_kv'          => array(
					'pattern'     => '/\b(password|passwd|pwd|pass)\s*[:=]\s*\S+/i',
					'replacement' => '$1: [hidden]',
				),
				'password_json'        => array(
					'pattern'     => '/"(password|passwd|pwd|pass)"\s*:\s*"[^"]*"/i',
					'replacement' => '"$1": "[hidden]"',
				),
				'username_kv'          => array(
					'pattern'     => '/\b(username|uname)\s*[:=]\s*\S+/i',
					'replacement' => '$1: [hidden]',
				),
				'username_json'        => array(
					'pattern'     => '/"(username|uname)"\s*:\s*"[^"]*"/i',
					'replacement' => '"$1": "[hidden]"',
				),
				'api_key_kv'           => array(
					'pattern'     => '/\b(api[_\-]?key|api[_\-]?token|auth[_\-]?token|access[_\-]?token|secret[_\-]?key|client[_\-]?secret|bearer)\s*[:=]\s*\S+/i',
					'replacement' => '$1: [hidden]',
				),
				'api_key_json'         => array(
					'pattern'     => '/"(api[_\-]?key|api[_\-]?token|auth[_\-]?token|access[_\-]?token|secret[_\-]?key|client[_\-]?secret)"\s*:\s*"[^"]*"/i',
					'replacement' => '"$1": "[hidden]"',
				),
				'credit_card'          => array(
					'pattern'     => '/\b(?:4\d{3}|5[1-5]\d{2}|6011|3[47]\d{2})[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/',
					'replacement' => '[card]',
				),
				'ssn'                  => array(
					'pattern'     => '/\b\d{3}[- ]?\d{2}[- ]?\d{4}\b/',
					'replacement' => '[ssn]',
				),
				'phone'                => array(
					'pattern'     => '/\b(?:\+?\d{1,3}[\s.\-]?)?(?:\(?\d{1,4}\)?[\s.\-]?)?\d{3,5}[\s.\-]?\d{4,9}\b/',
					'replacement' => '[phone]',
				),
				'secret'               => array(
					'pattern'     => '/\b(?=[a-z0-9]{32,})(?=[^a-z]*[a-z])(?=[^A-Z]*[A-Z])(?=[^\d]*\d)[A-Za-z0-9]{32,}\b/',
					'replacement' => '[secret]',
				),
				'private_key'          => array(
					'pattern'     => '/-----BEGIN [A-Z ]+KEY-----[\s\S]+?-----END [A-Z ]+KEY-----/',
					'replacement' => '[private-key]',
				),
				'db_connection'        => array(
					'pattern'     => '/\b(mysql|pgsql|mongodb|redis|sqlite):\/\/[^\s]+/i',
					'replacement' => '[db-connection]',
				),
				'url_auth_param'       => array(
					'pattern'     => '/([?&](token|key|auth|secret|api_key|access_token)=)[^\s&"\']+/i',
					'replacement' => '$1[hidden]',
				),
				'street_address'       => array(
					'pattern'     => '/\b\d{1,5}\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\s+(?:St|Ave|Rd|Blvd|Ln|Dr|Ct|Way|Pl|Street|Avenue|Road|Boulevard|Lane|Drive|Court)\b\.?/i',
					'replacement' => '[address]',
				),
				'reference_number'     => array(
					'pattern'     => '/\b(?:order|ticket|invoice|ref|case|txn|transaction)[_\-\s#]*\d{4,}/i',
					'replacement' => '[ref-number]',
				),
				'license_key_flexible' => array(
					'pattern'     => '/\b(?:[A-Z]{2,6}-)+[A-Z0-9]{3,}(?:-[A-Z0-9]{3,})*\b/',
					'replacement' => '[license-key]',
				),
				'signoff_name'         => array(
					'pattern'     => '/\b(thanks|regards|cheers|sincerely|hi|hello|dear),?\s+([A-Z][a-z]+(?:\s+[A-Z]\.?)?(?:\s+[A-Z][a-z]+)?)/i',
					'replacement' => '$1, [name]',
				),
				'cvv'                  => array(
					'pattern'     => '/\b(cvv|cvc|cvv2|security[_\s]?code)\s*[:=]?\s*\d{3,4}\b/i',
					'replacement' => '$1: [cvv]',
				),
				'card_expiry'          => array(
					'pattern'     => '/\b(expiry|expiration|exp|valid\s*(?:thru|through|until))\s*[:=]?\s*\d{2}[\/-]\d{2,4}\b/i',
					'replacement' => '$1: [expiry]',
				),
				'gst_india'            => array(
					'pattern'     => '/\b\d{2}[A-Z]{5}\d{4}[A-Z]{1}[A-Z\d]{1}[Z]{1}[A-Z\d]{1}\b/',
					'replacement' => '[tax-id]',
				),
				'vat_eu'               => array(
					'pattern'     => '/\b[A-Z]{2}[0-9A-Z]{8,12}\b(?=\s*(?:VAT|GST|tax))/i',
					'replacement' => '[tax-id]',
				),
				'ein_us'               => array(
					'pattern'     => '/\b\d{2}-\d{7}\b/',
					'replacement' => '[tax-id]',
				),
				'job_title'            => array(
					'pattern'     => '/\b(CEO|CTO|CFO|COO|CMO|VP|Director|Manager|Engineer|Founder|President|Head\s+of\s+\w+),\s+[A-Z][a-zA-Z\s]{2,30}\b/',
					'replacement' => '[title], [company]',
				),
				'location'             => array(
					'pattern'     => '/\b([A-Z][a-zA-Z\s]{2,20}),\s*(India|USA|UK|Canada|Australia|Germany|France|UAE|Singapore|Pakistan|Bangladesh|[A-Z]{2})\b/',
					'replacement' => '[location]',
				),
				'html_entities'        => array(
					'pattern'     => '/&(?:[a-z]+|#\d+|#x[0-9a-f]+);/i',
					'replacement' => ' ',
				),
			);

			foreach ( $patterns as $key => $rule ) {
				$result = preg_replace( $rule['pattern'], $rule['replacement'], $content );

				if ( null === $result ) {
					return '';
				}

				$content = $result;
			}

			return $content;
		}

		/**
		 * Check if AI training is allowed based on plugin settings and user permissions.
		 *
		 * @return bool True if AI training is allowed, false otherwise.
		 */
		public static function is_allowed_ai_training() {

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			if ( WPSC_Functions::is_site_admin() && ! empty( $ai_settings['is-active'] ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Validate and move uploaded AI files into temp directory.
		 *
		 * @param array $files    The $_FILES['wpsc_ai_file_input'] array.
		 * @param array $settings Plugin settings containing max file size.
		 *
		 * @return array|WP_Error Returns uploaded file data or WP_Error on failure.
		 */
		public static function wpsc_validate_ai_file_uploads( $files, $settings ) {

			if ( empty( $files ) || ! isset( $files['name'] ) ) {
				wp_send_json_error( __( 'No files uploaded.', 'wpsc-ps' ), 400 );
			}

			// Normalize single file to array format.
			if ( ! is_array( $files['name'] ) ) {
				$files = array(
					'name'     => array( $files['name'] ),
					'type'     => array( $files['type'] ),
					'tmp_name' => array( $files['tmp_name'] ),
					'error'    => array( $files['error'] ),
					'size'     => array( $files['size'] ),
				);
			}

			$results = array();

			// Max size in bytes. Enforce a safe hard limit (e.g., 10MB) to avoid memory exhaustion.
			$max_size = isset( $settings['ai-max-upload-file-size'] )
				? (int) $settings['ai-max-upload-file-size'] * 1024 * 1024
				: 10 * 1024 * 1024;

			// Allowed MIME types.
			$allowed_mimes = array(
				'pdf' => 'application/pdf',
				'txt' => 'text/plain',
			);

			$allow_internal_file = ! empty( $settings['allow_internal_file'] );
			if ( $allow_internal_file ) {
				$allowed_mimes['json'] = 'application/json';
			}

			// Upload directory.
			$upload_dir = wp_upload_dir();

			if ( ! empty( $upload_dir['error'] ) ) {
				wp_send_json_error( __( 'Upload directory error: ', 'wpsc-ps' ) . $upload_dir['error'], 400 );
			}

			// Target directory.
			$today = new DateTime( 'now' );
			$base_dir = $upload_dir['basedir'] . '/wpsc/ai-training/' . $today->format( 'Y' ) . '/' . $today->format( 'm' ) . '/';

			// Create directory properly.
			if ( ! file_exists( $base_dir ) ) {
				wp_mkdir_p( $base_dir );
			}

			// Check writable (IMPORTANT).
			if ( ! is_writable( $base_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				wp_send_json_error( __( 'Upload directory is not writable.', 'wpsc-ps' ), 400 );
			}

			foreach ( $files['name'] as $index => $name ) {

				if ( empty( $name ) ) {
					continue;
				}

				$tmp_name = $files['tmp_name'][ $index ];
				$size     = (int) $files['size'][ $index ];
				$error    = (int) $files['error'][ $index ];

				// Check upload error.
				if ( $error !== UPLOAD_ERR_OK ) {
					continue;
				}

				// Validate uploaded file.
				if ( ! $allow_internal_file && ! is_uploaded_file( $tmp_name ) ) {
					continue;
				}

				// File size validation.
				if ( $size < 1 || $size > $max_size ) {
					// Optionally, return a specific error for oversize files.
					continue;
				}

				// Sanitize file name.
				$safe_name = sanitize_file_name( $name );
				$info = pathinfo( $safe_name );
				$filename     = isset( $info['filename'] ) ? $info['filename'] : 'file';
				$original_ext = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';

				// Keep the original extension so that cron-side upload step can correctly detect. the on disk file type. txt json pdf before sending it to the provider.
				$extension = '' !== $original_ext ? '.' . $original_ext : '';
				$safe_name = $filename . '-' . time() . $extension;

				// Validate file type.
				$validation_ext = strtolower( pathinfo( $safe_name, PATHINFO_EXTENSION ) );
				if ( ! isset( $allowed_mimes[ $validation_ext ] ) ) {
					continue;
				}

				$file_ext = strtolower( pathinfo( $safe_name, PATHINFO_EXTENSION ) );

				// Generate unique file name.
				$filename  = wp_unique_filename( $base_dir, $safe_name );
				$file_path = $base_dir . $filename;

				// Move uploaded file or copy if internal .
				$copied = false;
				if ( isset( $settings['allow_internal_file'] ) && $settings['allow_internal_file'] ) {
					$copied = copy( $tmp_name, $file_path );
				}
				if ( ! $copied && ! move_uploaded_file( $tmp_name, $file_path ) ) {
					continue;
				}

				// Prepare URL.
				$file_url = $upload_dir['baseurl'] . '/wpsc/ai-training/' . $today->format( 'Y' ) . '/' . $today->format( 'm' ) . '/' . $filename;

				$results[] = array(
					'file'      => '/wpsc/ai-training/' . $today->format( 'Y' ) . '/' . $today->format( 'm' ) . '/' . $filename,
					'url'       => $file_url,
					'name'      => $filename,
					'source'    => $file_ext,
					'meta_data' => '',
				);
			}

			if ( empty( $results ) ) {
				wp_send_json_error( __( 'No valid files processed.', 'wpsc-ps' ), 400 );
			}

			return $results;
		}

		/**
		 * Validate URLs, fetch content, and save as TXT files in temp directory.
		 *
		 * @param string $urls_string Multiline string containing one URL per line.
		 * @param array  $settings Plugin settings containing max file size.
		 *
		 * @return array|WP_Error Returns array of saved file data or WP_Error on failure.
		 */
		public static function wpsc_validate_ai_urls( $urls_string, $settings ) {

			$urls_string = trim( $urls_string );
			if ( empty( $urls_string ) ) {
				wp_send_json_error( __( 'No URLs provided.', 'wpsc-ps' ), 400 );
			}

			$urls = preg_split( '/\r\n|\r|\n/', trim( $urls_string ) );
			$urls = array_filter( array_map( 'trim', $urls ) );
			$urls = array_unique( $urls );
			$results = array();

			// Upload directory.
			$upload_dir = wp_upload_dir();

			if ( ! empty( $upload_dir['error'] ) ) {
				wp_send_json_error( __( 'Upload directory error: ', 'wpsc-ps' ) . $upload_dir['error'], 400 );
			}

			// Target directory.
			$today = new DateTime( 'now' );
			$base_dir = $upload_dir['basedir'] . '/wpsc/ai-training/' . $today->format( 'Y' ) . '/' . $today->format( 'm' ) . '/';

			// Create directory properly.
			if ( ! file_exists( $base_dir ) ) {
				wp_mkdir_p( $base_dir );
			}

			// Ensure writable.
			if ( ! is_writable( $base_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				wp_send_json_error( __( 'Upload directory is not writable.', 'wpsc-ps' ), 400 );
			}

			foreach ( $urls as $url ) {

				$url = esc_url_raw( trim( $url ) );
				if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
					continue;
				}

				// SSRF protection: Only allow http/https schemes.
				$parsed = wp_parse_url( $url );
				if ( empty( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
					continue;
				}

				// SSRF protection: DNS resolve and check IP is public.
				$host = $parsed['host'] ?? '';
				if ( empty( $host ) ) {
					continue;
				}
				$ips = gethostbynamel( $host );
				if ( empty( $ips ) ) {
					continue;
				}
				$is_public = true;
				foreach ( $ips as $ip ) {
					if ( ! self::wpsc_is_public_ip( $ip ) ) {
						$is_public = false;
						break;
					}
				}
				if ( ! $is_public ) {
					continue;
				}

				$clean_content = WPSC_Content_Extractor::fetch_and_extract_content( $url );

				// Strip HTML tags.
				if ( empty( trim( $clean_content ) ) ) {
					continue;
				}

				// Limit content size (important for AI).
				if ( strlen( $clean_content ) > 9999999 ) { // ~10MB text.
					continue;
				}

				// Generate filename.
				$url_parts = wp_parse_url( $url );

				$host = strtolower( $url_parts['host'] ?? '' );
				$path = urldecode( $url_parts['path'] ?? '' );

				// Combine.
				$full_path = trim( $host . $path, '/' );

				// Normalize.
				$full_path = str_replace( '.', '-', $full_path );
				$full_path = str_replace( array( '+', '&' ), array( 'plus', 'and' ), $full_path );

				// Slug.
				$slug = sanitize_title( $full_path );

				// Fallback.
				if ( empty( $slug ) ) {
					$slug = 'page';
				}

				// Limit length.
				$slug = substr( $slug, 0, 150 );

				// Hash.
				$hash = substr( md5( $url ), 0, 6 );

				$filename = "{$slug}-{$hash}-" . time() . '.txt';
				$filename = sanitize_file_name( $filename );

				// Ensure unique filename.
				$filename  = wp_unique_filename( $base_dir, $filename );
				$file_path = $base_dir . $filename;

				// Save file using native PHP (reliable).
				$saved = file_put_contents( $file_path, trim( $clean_content ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

				if ( false === $saved ) {
					continue;
				}

				// Prepare URL.
				$file_url = $upload_dir['baseurl'] . '/wpsc/ai-training/' . $today->format( 'Y' ) . '/' . $today->format( 'm' ) . '/' . $filename;

				$results[] = array(
					'file' => '/wpsc/ai-training/' . $today->format( 'Y' ) . '/' . $today->format( 'm' ) . '/' . $filename,
					'url'  => $file_url,
					'name' => $filename,
				);
			}
			return $results;
		}

		/**
		 * Check if an IP address is public (not private/reserved).
		 *
		 * @param string $ip The IP address to check.
		 * @return bool True if the IP is public, false otherwise.
		 */
		private static function wpsc_is_public_ip( $ip ) {
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Generate a sanitized string key from a given string.
		 *
		 * This function takes a string, converts it to lowercase,
		 * removes all tags, replaces non-alphanumeric characters with underscores,
		 * collapses multiple underscores into one, trims leading/trailing underscores,
		 * and optionally singularizes simple plurals by removing a trailing 's'.
		 *
		 * @param string $row_string The string to be transformed into a key.
		 * @return string A sanitized, lowercase, underscore-separated string key.
		 */
		public static function wpsc_generate_string_key( $row_string ) {

			$row_string = strtolower( wp_strip_all_tags( $row_string ) );

			// Replace non-alphanumeric chars with underscore.
			$row_string = preg_replace( '/[^a-z0-9]+/', '_', $row_string );

			// Remove duplicate/starting/ending underscores.
			$row_string = trim( preg_replace( '/_+/', '_', $row_string ), '_' );

			// Optional: singularize simple plurals by removing only one trailing 's'.
			$string = ( strlen( $row_string ) > 1 ) ? preg_replace( '/s$/', '', $row_string ) : $row_string;

			return $string;
		}
	}
endif;
