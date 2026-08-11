<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AIT_Controller' ) ) :

	final class WPSC_PS_AIT_Controller {

		/**
		 * Process file uploads to AI provider for training.
		 * This function is designed to be called via a scheduled cron event to handle the processing of training files in batches.
		 * It retrieves pending training files, gets the necessary data from their sources, uploads them to the AI provider, and updates their status accordingly.
		 *
		 * @param array $ai_settings The AI settings including API keys and provider information needed for uploading files.
		 * @return void
		 */
		public static function upload_file_to_training( $ai_settings ) {

			// Database synchronization takes priority over uploading to the AI provider.
			// A source's sync can start concurrently with an already-scheduled/in-flight
			// upload tick (see WPSC_PS_AI_Setting_AI_Training_Actions::start_sync_for_source()),
			// so this is checked here too rather than only at schedule time - without it, a
			// row could still be picked up and uploaded while its own sync is still inserting
			// the rest of that post type's pages. Nothing has been touched yet at this point,
			// so deferring is safe: no row, no cron state. The event that triggered this call
			// was a one-off wp_schedule_single_event() and is already consumed by WP-Cron, so
			// simply returning here does not lose it or leave a duplicate behind - the pending
			// records get picked up again once every active sync finishes (see the finalize
			// step of WPSC_PS_AI_Setting_AI_Training_Actions::process_sync_tick()).
			if ( WPSC_PS_AI_Setting_AI_Training_Actions::is_any_sync_active() ) {
				return;
			}

			$provider = WPSC_PS_AIT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			$store_id = $provider->wpsc_provider_store_id( $ai_settings['api_key'] );

			$results = WPSC_RAG_Training_File::find(
				array(
					'order'      => 'ASC',
					'orderby'    => 'date_created',
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'status',
							'compare' => '=',
							'val'     => WPSC_PS_AIT_Status::NEW,
						),
						array(
							'slug'    => 'provider',
							'compare' => '=',
							'val'     => $ai_settings['provider'],
						),
					),
				)
			)['results'];

			if ( empty( $results ) ) {
				// Unschedule if no more pending files to process.
				wp_clear_scheduled_hook( 'wpsc_ai_training_upload' );
				return;
			}
			$training = $results[0]; // Process one file at a time.

			try {

				// Ticket/file training items already have name + file_path set when inserted.
				// URL and post-type (website sync) sources need their content fetched here.
				if ( ! in_array( $training->source, array( WPSC_PS_AIT_Source::TICKET, WPSC_PS_AIT_Source::FILE ), true ) ) {

					$data = ( WPSC_PS_AIT_Source::URL === $training->source )
						? self::get_url_data_for_training( $training )
						: self::get_post_type_data_for_training( $training ); // Any other source is a post type synced from a training source (website sync).

					if ( empty( $data ) ) {
						// Mark as deleted, do not output JSON in cron context.
						self::wpsc_mark_delete( $training, 'Failed to get training data from source' );
						return;
					} elseif ( isset( $data['error'] ) && $data['error'] === 'NO_RAG_CONTENT_FOUND' ) {
						// Mark as deleted with specific message, do not output JSON in cron context.
						$result = WPSC_RAG_Training_File::safe_delete( $training );
						if ( $result && ! wp_next_scheduled( 'wpsc_delete_ai_training_record' ) ) {
							wp_schedule_single_event( time(), 'wpsc_delete_ai_training_record' );
						}
						return;
					}
					$training->name = $data['name'];
					$training->file_path = $data['file_path'];
				}

				$training->status = WPSC_PS_AIT_Status::PROCESSING;
				$training->save();

				$upload_dir = wp_upload_dir();
				$file_path = $upload_dir['basedir'] . $training->file_path;
				if ( ! file_exists( $file_path ) ) {
					// Mark as deleted, do not output JSON in cron context.
					self::wpsc_mark_delete( $training, 'File not found' );
					return;
				}

				// Convert supported text files to JSON so RAG receives structured metadata + content.
				$file_extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
				if ( ! in_array( $file_extension, array( 'txt', 'json', 'pdf' ), true ) ) {
					self::wpsc_mark_delete( $training, 'Unsupported file format for training upload' );
					return;
				}

				// Upload file to provider.
				$upload = array();
				$upload = $provider->wpsc_upload_file( $file_path, $ai_settings['api_key'] );

				// The store this upload targeted no longer exists for the configured key/project
				// (e.g. an API key rotation moved to a different project). Vector/file-search
				// stores are never re-validated on their own, so without this the row would just
				// fail forever. Clear the cached store so a fresh one gets created, and requeue
				// this row instead of discarding it.
				if ( is_wp_error( $upload ) && 'file_search_store_not_found' === $upload->get_error_code() ) {
					$provider->wpsc_clear_provider_store_id();
					self::wpsc_requeue_for_stale_store( $training, $upload->get_error_message() );
					return;
				}

				if ( is_wp_error( $upload ) || empty( $upload['id'] ) ) {
					// Mark as deleted, do not output JSON in cron context.
					self::wpsc_mark_delete( $training, 'Failed to upload file to ' . ucfirst( $ai_settings['provider'] ) );
					return;
				}

				$file_id = $upload['id'];

				// Attach file.
				$attach = $provider->wpsc_attach_file( $store_id, $file_id, $ai_settings['api_key'] );

				// Same stale-store scenario as above, but surfaced at attach time (OpenAI).
				if ( is_wp_error( $attach ) && 'vector_store_not_found' === $attach->get_error_code() ) {
					$provider->wpsc_clear_provider_store_id();
					self::wpsc_requeue_for_stale_store( $training, $attach->get_error_message(), $file_id );
					return;
				}

				if ( is_wp_error( $attach ) || empty( $attach ) ) {
					// File was uploaded to the provider but failed to attach to the vector/file search store
					// (e.g. permission-denied on that endpoint). Keep provider_file_id so it can be cleaned up
					// or retried later instead of silently marking this as indexed.
					self::wpsc_mark_failed( $training, 'Failed to attach file to ' . ucfirst( $ai_settings['provider'] ) . ' vector store / file search store', $file_id );
					return;
				}

				$training->status           = WPSC_PS_AIT_Status::INDEXED;
				$training->provider_file_id = $file_id;
				$training->save();

				// After successful upload and attach, delete the local file to save space.
				self::wpsc_delete_local_file( $file_path );
			} catch ( \Throwable $e ) {
				$training->status = WPSC_PS_AIT_Status::NEW; // Reset to new for retry.
				$training->save();
			} finally {
				// Always re-check pending queue and reschedule, regardless of how the item above was handled.
				self::wpsc_schedule_training_upload_if_pending( $ai_settings['provider'] );
			}
		}

		/**
		 * Get URL data for training based on the provided training object.
		 *
		 * @param WPSC_RAG_Training_File $training The training file object containing details about the training data source and type.
		 * @return array The structured data ready for AI training.
		 */
		public static function get_url_data_for_training( $training ) {

			$meta_data = json_decode( $training->meta_data, true );
			if ( ! $meta_data ) {
				return array();
			}

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			$files_results = WPSC_PS_AI_Functions::wpsc_validate_ai_urls( $meta_data['url'], $ai_settings );

			if ( is_wp_error( $files_results ) || empty( $files_results ) ) {
				WPSC_RAG_Training_File::safe_delete( $training );
				return array();
			}

			$data = array();
			foreach ( $files_results as $file_upload ) {

				if ( empty( $file_upload['file'] ) ) {
					continue;
				}

				$data = array(
					'name'      => $file_upload['name'],
					'file_path' => $file_upload['file'],
				);
			}
			return $data;
		}

		/**
		 * Mark a training file as deleted with an error message
		 *
		 * @param WPSC_RAG_Training_File $data The training data model instance.
		 * @param string                 $message The error message to log and save.
		 * @return void
		 */
		private static function wpsc_mark_delete( $data, $message = '' ) {

			self::wpsc_set_failure_reason( $data, $message );
			$data->status = WPSC_PS_AIT_Status::DELETE;
			$data->save();
		}

		/**
		 * Mark a training file as failed with an error message. Used when the file was
		 * uploaded to the provider but a later step (e.g. attaching to the vector/file
		 * search store) failed, so the provider-side file may still need cleanup/retry.
		 *
		 * @param WPSC_RAG_Training_File $data The training data model instance.
		 * @param string                 $message The error message to log.
		 * @param string                 $provider_file_id Provider-side file ID, if one was created.
		 * @return void
		 */
		private static function wpsc_mark_failed( $data, $message = '', $provider_file_id = '' ) {

			self::wpsc_set_failure_reason( $data, $message );
			$data->status = WPSC_PS_AIT_Status::FAILED;
			if ( $provider_file_id ) {
				$data->provider_file_id = $provider_file_id;
			}
			$data->save();
		}

		/**
		 * Requeue a training row after detecting that its target vector/file-search store no
		 * longer exists for the currently configured API key (e.g. the key was rotated to a
		 * different provider project — stores are project-scoped and never re-validated on
		 * their own, see WPSC_PS_AI_OpenAI::clear_stored_vector_store_id()). The caller is
		 * expected to have already cleared the stale cached store ID, so a fresh store gets
		 * created under the currently configured key on retry.
		 *
		 * Capped like reset_stale_processing_files()'s stall retries, so a permanently broken
		 * key/project (e.g. lacking permission to create a store at all) eventually lands on
		 * FAILED instead of requeuing to NEW forever. Rescheduling the upload cron is left to
		 * the caller's surrounding finally block (wpsc_schedule_training_upload_if_pending()).
		 *
		 * @param WPSC_RAG_Training_File $training The training data model instance.
		 * @param string                 $message The reason to persist for admin visibility.
		 * @param string                 $provider_file_id Provider-side file ID, if one was created.
		 * @return void
		 */
		private static function wpsc_requeue_for_stale_store( $training, $message = '', $provider_file_id = '' ) {

			$retry_count = self::wpsc_get_training_meta( $training, 'stale_store_retry_count', 0 ) + 1;

			if ( $retry_count > 3 ) {
				self::wpsc_mark_failed( $training, $message, $provider_file_id );
				return;
			}

			self::wpsc_set_training_meta( $training, 'stale_store_retry_count', $retry_count );
			self::wpsc_set_failure_reason( $training, $message );
			if ( $provider_file_id ) {
				$training->provider_file_id = $provider_file_id;
			}
			$training->status = WPSC_PS_AIT_Status::NEW;
			$training->save();
		}

		/**
		 * Persist a human-readable failure/status reason into the row's meta_data. Surfaced as
		 * a tooltip in the training list UI — see
		 * WPSC_PS_AI_Setting_AI_Training::get_aia_file_upload_training_list().
		 *
		 * @param WPSC_RAG_Training_File $training The training data model instance.
		 * @param string                 $message Reason to store; ignored if empty.
		 * @return void
		 */
		private static function wpsc_set_failure_reason( $training, $message ) {

			if ( '' === trim( (string) $message ) ) {
				return;
			}

			self::wpsc_set_training_meta( $training, 'failure_reason', sanitize_text_field( $message ) );
		}

		/**
		 * Read a single key out of a training row's meta_data JSON.
		 *
		 * @param WPSC_RAG_Training_File $training The training data model instance.
		 * @param string                 $key Meta key to read.
		 * @param mixed                  $default_value Value to return if the key isn't set.
		 * @return mixed
		 */
		private static function wpsc_get_training_meta( $training, $key, $default_value = null ) {

			$meta = json_decode( $training->meta_data, true );
			return ( is_array( $meta ) && isset( $meta[ $key ] ) ) ? $meta[ $key ] : $default_value;
		}

		/**
		 * Write a single key into a training row's meta_data JSON, preserving any other keys
		 * already stored there (e.g. stale_retry_count alongside failure_reason). Does not
		 * save() — callers set this alongside other field changes and save once.
		 *
		 * @param WPSC_RAG_Training_File $training The training data model instance.
		 * @param string                 $key Meta key to write.
		 * @param mixed                  $value Value to store.
		 * @return void
		 */
		private static function wpsc_set_training_meta( $training, $key, $value ) {

			$meta = json_decode( $training->meta_data, true );
			if ( ! is_array( $meta ) ) {
				$meta = array();
			}
			$meta[ $key ]         = $value;
			$training->meta_data = wp_json_encode( $meta );
		}

		/**
		 * Reset training files stuck in PROCESSING status back to NEW for retry, or to FAILED
		 * after repeated stalls. A row is set to PROCESSING before the outbound upload/attach
		 * calls run; if that request hangs long enough to hit a PHP execution timeout, OOM kill,
		 * or a host/web-server process kill, the try/catch/finally never runs and the row is
		 * otherwise orphaned in PROCESSING forever (nothing else re-queries "processing" rows).
		 *
		 * @param int $stale_minutes Minutes after which a PROCESSING row is considered stalled.
		 * @return void
		 */
		public static function reset_stale_processing_files( $stale_minutes = 15 ) {

			$stale_before = gmdate( 'Y-m-d H:i:s', time() - ( $stale_minutes * MINUTE_IN_SECONDS ) );

			$stalled = WPSC_RAG_Training_File::find(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'status',
							'compare' => '=',
							'val'     => WPSC_PS_AIT_Status::PROCESSING,
						),
						array(
							'slug'    => 'date_updated',
							'compare' => '<',
							'val'     => $stale_before,
						),
					),
				)
			)['results'];

			if ( empty( $stalled ) ) {
				return;
			}

			foreach ( $stalled as $training ) {

				$retry_count = self::wpsc_get_stale_retry_count( $training ) + 1;

				// Give up after repeated stalls (e.g. a persistent outbound connectivity issue)
				// instead of bouncing the same row between new/processing indefinitely.
				if ( $retry_count > 3 ) {
					self::wpsc_mark_failed( $training, 'Upload stalled repeatedly (possible network/timeout issue while contacting provider)' );
					continue;
				}

				self::wpsc_set_stale_retry_count( $training, $retry_count );
				$training->status = WPSC_PS_AIT_Status::NEW;
				$training->save();
			}

			if ( ! wp_next_scheduled( 'wpsc_ai_training_upload' ) && ! WPSC_PS_AI_Setting_AI_Training_Actions::is_any_sync_active() ) {
				wp_schedule_single_event( time(), 'wpsc_ai_training_upload' );
			}
		}

		/**
		 * Get the number of times a training row has been reset out of a stalled PROCESSING state.
		 *
		 * @param WPSC_RAG_Training_File $training The training data model instance.
		 * @return int
		 */
		private static function wpsc_get_stale_retry_count( $training ) {

			return (int) self::wpsc_get_training_meta( $training, 'stale_retry_count', 0 );
		}

		/**
		 * Persist the stale-retry counter into the row's meta_data, preserving any existing keys.
		 *
		 * @param WPSC_RAG_Training_File $training The training data model instance.
		 * @param int                    $count Updated retry count.
		 * @return void
		 */
		private static function wpsc_set_stale_retry_count( $training, $count ) {

			self::wpsc_set_training_meta( $training, 'stale_retry_count', $count );
		}

		/**
		 * Schedule next upload run when there are pending NEW records.
		 *
		 * @param string $provider Provider slug.
		 * @return void
		 */
		private static function wpsc_schedule_training_upload_if_pending( $provider ) {

			$pending = WPSC_RAG_Training_File::count(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'status',
							'compare' => '=',
							'val'     => WPSC_PS_AIT_Status::NEW,
						),
						array(
							'slug'    => 'provider',
							'compare' => '=',
							'val'     => $provider,
						),
					),
				)
			);

			// Do not requeue while a sync is active - if one started while this file was
			// uploading, the finalize step of process_sync_tick() will schedule the next
			// upload run once every source's sync has finished instead.
			if ( $pending > 0 && ! wp_next_scheduled( 'wpsc_ai_training_upload' ) && ! WPSC_PS_AI_Setting_AI_Training_Actions::is_any_sync_active() ) {
				wp_schedule_single_event( time(), 'wpsc_ai_training_upload' );
			}
		}

		/**
		 * Build a short, filesystem-safe training file name.
		 *
		 * @param string $title Base title for filename.
		 * @param int    $id Fallback identifier.
		 * @param string $extension File extension without dot.
		 * @return string
		 */
		private static function wpsc_build_short_training_file_name( $title, $id, $extension = 'txt' ) {

			$base_name = sanitize_file_name( (string) $title );

			if ( function_exists( 'mb_substr' ) ) {
				$base_name = mb_substr( $base_name, 0, 80, 'UTF-8' );
			} else {
				$base_name = substr( $base_name, 0, 80 );
			}

			$base_name = trim( $base_name, '-_.' );
			if ( '' === $base_name ) {
				$base_name = 'file-' . intval( $id ) . '-' . time();
			}

			$extension = ltrim( sanitize_key( (string) $extension ), '.' );
			if ( '' === $extension ) {
				$extension = 'txt';
			}

			return $base_name . '.' . $extension;
		}

		/**
		 * Safely delete a local file if it exists and is within the expected directory.
		 *
		 * @param string $file_path The path to the file to delete.
		 * @return void
		 */
		public static function wpsc_delete_local_file( $file_path ) {

			if ( empty( $file_path ) ) {
				return;
			}

			$upload_dir = wp_upload_dir();
			$base_dir = $upload_dir['basedir'];

			$real_base = realpath( $base_dir );
			$real_file = realpath( $file_path );

			// check (VERY IMPORTANT).
			if ( ! $real_file || strpos( $real_file, $real_base ) !== 0 ) {
				return;
			}

			if ( file_exists( $real_file ) ) {
				wp_delete_file( $real_file );
			}
		}

		/**
		 * Delete AI training record
		 *
		 * @param array $ai_settings AI settings array.
		 * @return void
		 */
		public static function delete_ai_training_record( $ai_settings ) {

			$provider = WPSC_PS_AIT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			$training_response = WPSC_RAG_Training_File::find(
				array(
					'items_per_page' => 10,
					'orderby'        => 'date_updated',
					'order'          => 'ASC',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'status',
							'compare' => '=',
							'val'     => WPSC_PS_AIT_Status::DELETE,
						),
						array(
							'slug'    => 'provider',
							'compare' => '=',
							'val'     => $ai_settings['provider'],
						),
					),
				)
			);
			$training_data = $training_response['results'] ?? array();

			if ( ! empty( $training_data ) ) {
				foreach ( $training_data as $training ) {
					if ( empty( $training->provider_file_id ) ) {
						WPSC_RAG_Training_File::destroy( $training );
						continue;
					}
					$flag = $provider->wpsc_delete_training_record( $training, $ai_settings );
					if ( $flag ) {
						WPSC_RAG_Training_File::destroy( $training );
					}
				}
			}

			// Schedule next run.
			if ( ! empty( $training_response['has_next_page'] ) && $training_response['has_next_page'] && ! wp_next_scheduled( 'wpsc_delete_ai_training_record' ) ) {
				wp_schedule_single_event( time(), 'wpsc_delete_ai_training_record' );
			}
		}

		/**
		 * Clean ticket for RAG
		 *
		 * @param string $row_content The row content.
		 * @param array  $meta_data   Additional metadata for cleaning, such as ticket ID.
		 * @return string The cleaned ticket history.
		 */
		public static function wpsc_proccess_and_clean_raw_content_for_rag( $row_content, $meta_data = array() ) {

			// Check capability and setting.
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			$provider = WPSC_PS_AIT_Provider_Factory::get_current_provider( $ai_settings['provider'] );

			$system_prompt = '
				You are an AI assistant responsible for preparing support ticket data for a Retrieval-Augmented Generation (RAG) system.

				STRICT RULES — DO NOT VIOLATE:
				- Do NOT write like an email or reply; no greetings, closings, or conversational/polite language, and do not address anyone
				- Preserve original meaning EXACTLY — do NOT summarize, compress, shorten, or add new information
				- Do NOT omit technical details, error messages, steps, logs, or outcomes — keep them intact

				PII HANDLING (MANDATORY):
				- Replace PII with placeholders, DO NOT remove

				PLACEHOLDERS:
				[NAME], [EMAIL], [PHONE], [URL], [IP_ADDRESS], [ORDER_ID], 
				[LICENSE_KEY], [API_KEY], [USERNAME], [PASSWORD], [CREDIT_CARD], [GST_NUMBER], [TAX_ID]

				EXAMPLES:
				- john@gmail.com → [EMAIL]
				- 192.168.1.1 → [IP_ADDRESS]
				- LIC-123 → [LICENSE_KEY]

				OUTPUT FORMAT:
				- Plain text
				- Cleaned and normalized
				- Structured with timestamps and roles

				Input:
				"""
				' . wp_strip_all_tags( $row_content ) . '
				"""

				Output:
				Cleaned structured text ready for RAG ingestion.
				';

			return $provider->wpsc_clean_row_content_for_rag( $system_prompt, $ai_settings );
		}

		/**
		 * Upload ticket content to a file.
		 *
		 * @param string $cleaned_content The cleaned content of the ticket.
		 * @param array  $meta_data       Additional metadata for the file, such as ticket ID.
		 * @return array|WP_Error The file path (absolute server path) or WP_Error on failure.
		 */
		public static function wpsc_create_file_from_cleaned_content( $cleaned_content, $meta_data = array() ) {

			// Validate input content.
			if ( empty( $cleaned_content ) || ! is_string( $cleaned_content ) ) {
				wp_send_json_error( __( 'Ticket content is empty or invalid.', 'wpsc-ps' ), 400 );
			}

			$id = isset( $meta_data['id'] ) ? intval( $meta_data['id'] ) : 0;
			$title = isset( $meta_data['title'] ) ? sanitize_text_field( $meta_data['title'] ) : 'file-' . $id . '-' . time();

			$upload_dir = wp_upload_dir();

			// Validate upload directory.
			if ( empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
				wp_send_json_error( __( 'Upload directory not available.', 'wpsc-ps' ), 400 );
			}

			$today = new DateTime( 'now' );
			$base_dir = $upload_dir['basedir'] . '/wpsc/ai-training/' . $today->format( 'Y' ) . '/' . $today->format( 'm' );
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );

			// Create directory if not exists.
			if ( ! file_exists( $base_dir ) ) {
				if ( ! wp_mkdir_p( $base_dir ) ) {
					wp_send_json_error( __( 'Failed to create directory.', 'wpsc-ps' ), 400 );
				}
			}

			// Check writable.
			if ( ! is_writable( $base_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				wp_send_json_error( __( 'Directory is not writable: ', 'wpsc-ps' ) . $base_dir, 400 );
			}

			// Normalize content.
			$cleaned_content = str_replace( array( "\r\n", "\r" ), "\n", $cleaned_content );
			$cleaned_content = trim( $cleaned_content );

			// Ensure UTF-8 encoding.
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$cleaned_content = mb_convert_encoding( $cleaned_content, 'UTF-8', 'UTF-8' );
			}

			$file_name = self::wpsc_build_short_training_file_name( $title, $id, 'txt' );
			$file_path = rtrim( $base_dir, '/\\' ) . '/' . $file_name;

			// Always overwrite the file if it exists.
			if ( file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}

			// Write file safely.
			$result = file_put_contents( $file_path, $cleaned_content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			if ( false === $result || ! file_exists( $file_path ) ) {
				wp_send_json_error( __( 'Failed to write file.', 'wpsc-ps' ), 400 );
			}

			// Validate file size.
			$file_size = filesize( $file_path );
			if ( false === $file_size || $file_size <= 0 ) {
				wp_delete_file( $file_path );
				wp_send_json_error( __( 'Generated file is empty.', 'wpsc-ps' ), 400 );
			}

			// Get file type.
			$file_type_data = wp_check_filetype( $file_name );
			$file_type = ! empty( $file_type_data['type'] ) ? $file_type_data['type'] : 'text/plain';

			// Create temp file for upload simulation.
			$tmp_file = tempnam( $base_dir . '/', $file_name );
			if ( ! $tmp_file || ! copy( $file_path, $tmp_file ) ) {
				wp_delete_file( $file_path );
				wp_send_json_error( __( 'Failed to create temp file.', 'wpsc-ps' ), 400 );
			}

			// Prepare files array.
			$files = array(
				'name'      => array( $file_name ),
				'full_path' => array( '/wpsc/ai-training/' . $today->format( 'Y' ) . '/' . $today->format( 'm' ) . '/' . $file_name ),
				'type'      => array( $file_type ),
				'tmp_name'  => array( $tmp_file ),
				'error'     => array( 0 ),
				'size'      => array( $file_size ),
			);

			// Add a flag to allow internal file validation bypassing is_uploaded_file check.
			$ai_settings['allow_internal_file'] = true;
			// Validate file using existing validator.
			$validate = WPSC_PS_AI_Functions::wpsc_validate_ai_file_uploads( $files, $ai_settings );

			// Always delete the initial temp file after validation to avoid orphaned files.
			wp_delete_file( $file_path );

			if ( is_wp_error( $validate ) ) {
				wp_delete_file( $tmp_file );
				return $validate;
			}

			// Cleanup temp file.
			wp_delete_file( $tmp_file );

			// If $validate is an array, ensure it returns the absolute file path (not URL).
			if ( is_array( $validate ) && isset( $validate[0]['file'] ) ) {
				// Return only the file path (absolute path) and other info, not the URL.
				return $validate;
			}
			return $validate;
		}

		/**
		 * Get system prompt for improving auto draft reply based on user instructions.
		 *
		 * @param array $ai_settings The AI settings including any custom prompts defined by the user.
		 * @return string The system prompt to guide the AI's response for improving auto draft replies.
		 */
		public static function wpsc_prompt_to_improve_auto_draft_reply_on_user_instruction( $ai_settings ) {

			$base_prompt = 'You are a professional support assistant.

				TASK:
				Generate a clear, concise, and helpful support reply using the ticket conversation and the knowledge base.

				CONTEXT PRIORITY:
				- Knowledge base is the primary source of truth
				- Ticket conversation provides context and user-specific details
				- Combine both when relevant

				BEHAVIOR RULES:
				- Do NOT hallucinate features, fixes, or capabilities
				- If knowledge base is relevant → use it
				- If knowledge base is partially relevant → enhance using conversation context
				- If knowledge base is not relevant → answer using conversation context only
				- Do NOT repeat previous replies
				- Focus only on unresolved or latest user intent
				- Keep response short, clear, and actionable
				- Prefer step-by-step guidance when troubleshooting

				CITATION RULES:
				- Do NOT include references, citations, or source links
				- Do NOT mention documents, files, or knowledge base sources
				- Return only the final clean answer

				CONTENT RULES:
				- Ignore greetings, signatures, and irrelevant text
				- Preserve technical accuracy (errors, logs, configurations)
				- Do NOT mention PII or placeholders

				HTML OUTPUT RULES:
				- Return clean HTML suitable for TinyMCE editor
				- Use <p> for paragraphs
				- Use <ul> and <li> for steps or lists
				- Use <strong> for important points
				- Keep HTML minimal and clean
				- Avoid excessive <br> tags
				- Do NOT use markdown
				- Do NOT wrap output in code blocks

				OUTPUT:
				Return ONLY the final HTML reply.';

			$custom_prompt = isset( $ai_settings['auto-draft-custom-prompt'] ) ? trim( $ai_settings['auto-draft-custom-prompt'] ) : '';
			if ( ! empty( $custom_prompt ) ) {
				$base_prompt .= "\n\nAdditional instructions from user:\n" . $custom_prompt;
			}
			return $base_prompt;
		}

		/**
		 * Build Summary Prompt
		 * This prompt is designed to instruct the AI to generate a concise and
		 * informative summary of a customer support ticket based on the full conversation history.
		 * The prompt emphasizes the importance of recent interactions, meaningful content, and overall customer sentiment.
		 *
		 * @param array  $ai_settings The AI settings including any custom prompts defined by the user.
		 * @param string $history The full conversation history of the ticket.
		 * @return string The constructed prompt to be sent to the AI model.
		 */
		public static function wpsc_prompt_to_create_ticket_summery( $ai_settings, $history ) {

			$base_prompt = "
				You are an AI support assistant summarizing a customer support ticket for internal agent use.

				Context:
				- The Full Conversation History is in chronological order.
				- The most recent messages are more important than older ones.
				- Focus only on meaningful interactions (ignore greetings, signatures, and trivial acknowledgements).

				Your tasks:

				1. Summarize the key conversation points as short, clear bullet points.
				2. Each bullet should describe one meaningful interaction or development.
				3. Mention who said or did what (use names if available).
				4. Keep the summary concise and professional.
				5. After the bullet list, add one final sentence describing overall customer sentiment.
				6. Customer sentiment must be ONLY one of these three values:
				- Unhappy
				- Neutral
				- Happy
				7. Determine sentiment primarily from the customer's tone and latest messages:
				- Complaints or frustration → Unhappy
				- Calm and factual → Neutral
				- Appreciation or satisfaction → Happy

				Return the result EXACTLY in valid HTML using this structure:

				<ul>
				<li>[first bullet point]</li>
				<li>[second bullet point]</li>
				<li>[third bullet point]</li>
				</ul>
				<p>Overall customer sentiment is <strong>[Unhappy|Neutral|Happy]</strong>.</p>

				Rules:
				- Replace every [bracketed placeholder] above with the actual generated content — never output the literal placeholder text or brackets.
				- Return ONLY valid HTML.
				- Do not include markdown.
				- Do not include explanations.
				- Do not wrap the response in code blocks.
				- Do not add anything before or after the HTML.
				- Use ONLY the following HTML tags: <ul>, <li>, <p>, <strong>. Do not use any other HTML tags.

				IMPORTANT: Keep the summary concise and compact.

				Full Conversation History:
				\"\"\"
				{$history}
				\"\"\"
			";

			$custom_prompt = isset( $ai_settings['summary-custom-prompt'] ) ? trim( $ai_settings['summary-custom-prompt'] ) : '';
			if ( ! empty( $custom_prompt ) ) {
				$base_prompt .= "\n\nAdditional instructions from user:\n" . $custom_prompt;
			}

			return $base_prompt;
		}

		/**
		 * Count training data by source
		 *
		 * @param string $source The source of the training data to count (e.g., 'ticket', 'url').
		 * @return int The count of training data for the specified source.
		 */
		public static function count_training_data_by_source( $source ) {

			return WPSC_RAG_Training_File::count(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'source',
							'compare' => '=',
							'val'     => $source,
						),
					),
				)
			) ?? 0;
		}

		/**
		 * Check if there are any training data available for deletion for a specific source.
		 *
		 * @param string $source The source of the training data to check (e.g., 'ticket', 'url').
		 * @return array An array of training data IDs that are available for deletion.
		 */
		public static function check_data_for_delete( $source ) {

			$trainings = WPSC_RAG_Training_File::pluck(
				'id',
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'source',
							'compare' => '=',
							'val'     => $source,
						),
						array(
							'slug'    => 'status',
							'compare' => 'NOT IN',
							'val'     => array( WPSC_PS_AIT_Status::DELETE ),
						),
					),
				)
			);
			return $trainings;
		}

		/**
		 * Delete all training data by source
		 *
		 * @param string $source The source of the training data to delete (e.g., 'ticket', 'url').
		 * @param string $doc_source The document source to filter the training data for deletion.
		 * @return void
		 */
		public static function delete_all_training_data_by_source( $source, $doc_source ) {

			$training_data = WPSC_RAG_Training_File::find(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'source',
							'compare' => '=',
							'val'     => $source,
						),
						array(
							'slug'    => 'doc_source',
							'compare' => '=',
							'val'     => $doc_source,
						),
					),
				)
			);

			$results = $training_data['results'] ?? array();

			if ( empty( $results ) ) {
				return;
			}

			$flag = false;
			foreach ( $results as $training ) {
				if ( WPSC_RAG_Training_File::safe_delete( $training ) ) {
					$flag = true;
				}
			}

			// Schedule only once safely.
			if ( $flag && ! wp_next_scheduled( 'wpsc_delete_ai_training_record' ) ) {
				wp_schedule_single_event( time() + 5, 'wpsc_delete_ai_training_record' );
			}
		}

		/**
		 * Get post type data for training based on the provided training object.
		 *
		 * @param WPSC_RAG_Training_File $training The training file object containing details about the training data source and type.
		 * @return array The structured data ready for AI training.
		 */
		public static function get_post_type_data_for_training( $training ) {

			$post_id   = absint( $training->source_id );
			$post_type = sanitize_key( $training->source );
			$source    = WPSC_PS_AIT_Source::get_training_source( $training->doc_source );
			$endpoint  = ! empty( $source['api-url'] ) ? trailingslashit( esc_url_raw( $source['api-url'] ) ) : '';

			if ( ! $post_id || '' === $post_type || '' === $endpoint ) {
				return array();
			}

			$url = $endpoint . 'wp/v2/' . $post_type . '/' . $post_id;

			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 30,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array();
			}

			if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return array();
			}

			$post = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $post ) ) {
				return array();
			}

			$title   = wp_strip_all_tags( $post['title']['rendered'] ?? '' );
			$content = $post['content']['rendered'] ?? '';

			if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
				return array();
			}

			$post_meta = array(
				'slug'  => $post_type,
				'id'    => $post_id,
				'title' => WPSC_PS_AI_Functions::wpsc_generate_string_key( $title ),
			);

			$post_content = self::wpsc_proccess_and_clean_raw_content_for_rag( $content, $post_meta );
			$file_uploads = self::wpsc_create_file_from_cleaned_content( $post_content, $post_meta );
			if ( empty( $file_uploads ) ) {
				return array();
			}

			foreach ( $file_uploads as $file_upload ) {

				if ( empty( $file_upload['file'] ) ) {
					continue;
				}

				return array(
					'name'      => $file_upload['name'],
					'file_path' => $file_upload['file'],
				);
			}

			return array();
		}
	}
endif;
