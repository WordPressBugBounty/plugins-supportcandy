<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AIT_Controller' ) ) :

	final class WPSC_PS_AIT_Controller {

		/**
		 * How long an upload lock is honored before it's considered abandoned by a
		 * crashed/killed process and reclaimed by the next tick. Kept well above the
		 * batch's own 20-second per-pass time budget - a single tick can still run
		 * longer than that budget in practice, since it's only checked between files,
		 * not during an individual provider upload call.
		 *
		 * @var int
		 */
		private const UPLOAD_LOCK_TIMEOUT = 5 * MINUTE_IN_SECONDS;

		/**
		 * Process file uploads to AI provider for training.
		 * This function is designed to be called via a scheduled cron event to handle the processing of training files in batches.
		 * It retrieves pending training files, gets the necessary data from their sources, uploads them to the AI provider, and updates their status accordingly.
		 *
		 * Drains as many pending files as fit within the time budget rather than exactly
		 * one - a single wp_schedule_single_event( time(), ... ) reschedule made from
		 * inside this same hook's execution cannot fire again until the next external
		 * wp-cron.php dispatch (WP-Cron snapshots the ready jobs before running any of
		 * them), so on a host with an infrequent external cron and WP-Cron's own
		 * pseudo-cron disabled, one-file-per-invocation meant one file per cron interval
		 * regardless of backlog size.
		 *
		 * @param array $ai_settings The AI settings including API keys and provider information needed for uploading files.
		 * @return void
		 */
		public static function upload_file_to_training( $ai_settings ) {

			$provider_slug = $ai_settings['provider'];

			if ( ! self::acquire_upload_lock( $provider_slug ) ) {
				// Another tick is already draining this provider's queue - two overlapping
				// WP-Cron dispatches (a duplicate fire, spawn_cron() racing a second
				// request, WP-CLI `cron event run`, etc.) must not both claim and upload
				// the same NEW row, since neither find() nor the row's later status flip
				// is atomic. Let the in-flight tick finish and reschedule shortly so the
				// queue doesn't stall just because this tick lost the race.
				if ( ! wp_next_scheduled( 'wpsc_ai_training_upload' ) ) {
					wp_schedule_single_event( time() + 15, 'wpsc_ai_training_upload' );
				}
				return;
			}

			try {
				self::wpsc_do_upload_tick( $ai_settings );
			} finally {
				self::release_upload_lock( $provider_slug );
			}
		}

		/**
		 * Lock-held body of upload_file_to_training() - see that method for the locking
		 * contract.
		 *
		 * @param array $ai_settings The AI settings including API keys and provider information needed for uploading files.
		 * @return void
		 */
		private static function wpsc_do_upload_tick( $ai_settings ) {

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

			try {
				$provider = WPSC_PS_AIT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			} catch ( \Throwable $e ) {
				// Invalid/unset provider setting - nothing to upload against. Leave the
				// hook scheduled so a later tick (once the setting is fixed) can proceed;
				// an uncaught exception here would otherwise fatal this cron dispatch.
				return;
			}
			$batch_size  = 25;
			$time_budget = time() + 20;

			do {
				$results = WPSC_RAG_Training_File::find(
					array(
						'items_per_page' => $batch_size,
						'order'          => 'ASC',
						'orderby'        => 'date_created',
						'meta_query'     => array(
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

				foreach ( $results as $training ) {

					// A sync starting mid-batch takes priority over the rest of this batch -
					// stop here and let its own finalize step reschedule the upload once it's
					// done, same as the check at the top of this function.
					if ( WPSC_PS_AI_Setting_AI_Training_Actions::is_any_sync_active() ) {
						return;
					}

					self::wpsc_process_single_upload( $training, $provider, $ai_settings );

					if ( time() >= $time_budget ) {
						break;
					}
				}
			} while ( time() < $time_budget );

			// Always re-check pending queue and reschedule for the next batch, regardless
			// of how the last pass above ended.
			self::wpsc_schedule_training_upload_if_pending( $ai_settings['provider'] );
		}

		/**
		 * Process a single pending training file: fetch its content if needed, upload it
		 * to the provider, attach it to the vector/file-search store, and update its
		 * status. Extracted from upload_file_to_training() so a batch pass can process
		 * more than one file per invocation - each file's outcome is handled and
		 * persisted independently of the others in the same batch.
		 *
		 * @param WPSC_RAG_Training_File $training    The training file to process.
		 * @param object                 $provider    Provider instance for the currently configured provider.
		 * @param array                  $ai_settings AI settings array.
		 * @return void
		 */
		private static function wpsc_process_single_upload( $training, $provider, $ai_settings ) {

			try {

				// Ticket/file training items already have name + file_path set when inserted.
				// URL and post-type (website sync) sources need their content fetched here.
				if ( ! in_array( $training->source, array( WPSC_PS_AIT_Source::TICKET, WPSC_PS_AIT_Source::FILE ), true ) ) {

					// Record which post type/post this row is for directly in meta_data (in
					// addition to the source/source_id columns) so a skipped or failed row's
					// meta_data alone is enough to identify what it was for. URL sources have
					// no post type, so this is only meaningful for website-sync post types.
					if ( WPSC_PS_AIT_Source::URL !== $training->source ) {
						self::wpsc_set_training_meta( $training, 'post_type', $training->source );
						self::wpsc_set_training_meta( $training, 'post_id', absint( $training->source_id ) );
					}

					$data = ( WPSC_PS_AIT_Source::URL === $training->source )
						? self::get_url_data_for_training( $training )
						: self::get_post_type_data_for_training( $training ); // Any other source is a post type synced from a training source (website sync).

					if ( empty( $data ) ) {
						// Network/API error reaching the source (or an empty/invalid response) -
						// the post title was never fetched, so fall back to an identifier so the
						// row is still recognizable in the training list. Mark as failed with the
						// reason kept in meta_data, do not output JSON in cron context.
						$training->name = self::wpsc_get_fallback_training_name( $training );
						self::wpsc_mark_failed( $training, 'Failed to get training data from source (network or API error)' );
						return;
					} elseif ( isset( $data['error'] ) && $data['error'] === 'FILE_PROCESSING_ERROR' ) {
						// Most commonly the generated training file exceeds the configured
						// upload size limit - the document is never truncated to fit, so this
						// fails clearly (Failed status + reason) instead of being silently reduced.
						$training->name = ! empty( $data['title'] ) ? $data['title'] : self::wpsc_get_fallback_training_name( $training );
						self::wpsc_mark_failed( $training, $data['message'] );
						return;
					} elseif ( isset( $data['error'] ) && $data['error'] === 'NO_RAG_CONTENT_FOUND' ) {
						// Tag the reason so get_skipped_insufficient_content_count() can find this
						// row again, then mark it failed (same as every other failure path here)
						// so its post_type/post_id/failure_reason in meta_data stay inspectable
						// until an admin deletes it themselves.
						$training->name = ! empty( $data['title'] ) ? $data['title'] : self::wpsc_get_fallback_training_name( $training );
						self::wpsc_set_training_meta( $training, self::SKIP_REASON_META_KEY, self::SKIP_REASON_INSUFFICIENT_CONTENT );
						self::wpsc_mark_failed( $training, $data['message'] ?? 'Insufficient content for training' );
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
					// Local file went missing (e.g. removed from disk between generation and
					// upload) - mark as failed, do not output JSON in cron context.
					self::wpsc_mark_failed( $training, 'File not found' );
					return;
				}

				// Provider-side hard limit check, applied uniformly regardless of source
				// (ticket/file/URL/post type) - the local 'ai-max-upload-file-size' setting
				// is enforced earlier (file upload validation, wpsc_create_file_from_cleaned_content()),
				// but an admin can configure that setting above what the provider itself
				// accepts, so this is checked independently right before upload rather than
				// assumed to already be covered.
				$file_size = filesize( $file_path );
				$provider_limit = $provider->wpsc_max_training_file_size();
				if ( false !== $file_size && $provider_limit > 0 && $file_size > $provider_limit ) {
					self::wpsc_mark_failed(
						$training,
						sprintf(
							'AI Training: File size limit exceeded. File: %1$s. Size: %2$s bytes. Maximum allowed size: %3$s bytes (%4$s limit).',
							$training->name,
							$file_size,
							$provider_limit,
							ucfirst( $ai_settings['provider'] )
						)
					);
					return;
				}

				// Convert supported text files to JSON so RAG receives structured metadata + content.
				$file_extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
				if ( ! in_array( $file_extension, array( 'txt', 'json', 'pdf' ), true ) ) {
					self::wpsc_mark_failed( $training, 'Unsupported file format for training upload' );
					return;
				}

				// Upload file to provider.
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

				// Gemini's uploadToFileSearchStore is a long-running operation - the upload
				// itself succeeded, but embedding/import was still in progress when this
				// tick's bounded poll window ran out. Not an actual failure, so requeue for
				// a fresh attempt instead of dead-ending the row on a false FAILED.
				if ( is_wp_error( $upload ) && 'gemini_operation_pending' === $upload->get_error_code() ) {
					self::wpsc_requeue_for_pending_operation( $training, $upload->get_error_message() );
					return;
				}

				if ( is_wp_error( $upload ) || empty( $upload['id'] ) ) {
					// Provider/network/API error while uploading - surface the underlying
					// error message when one is available (auth/quota/network failure)
					// instead of always collapsing to a generic message, so the Training
					// Data list can distinguish a systemic outage from a one-off content
					// problem. Do not output JSON in cron context.
					$reason = is_wp_error( $upload )
						? sprintf( 'Failed to upload file to %1$s: %2$s', ucfirst( $ai_settings['provider'] ), $upload->get_error_message() )
						: 'Failed to upload file to ' . ucfirst( $ai_settings['provider'] );
					self::wpsc_mark_failed( $training, $reason );
					return;
				}

				$file_id = $upload['id'];

				// Fetched per file rather than once per batch (it's cached in an option
				// after the first real lookup, so this is cheap) - otherwise a stale-store
				// requeue above for an earlier file in this same batch would leave every
				// later file in the batch attaching against the same now-invalid store id
				// instead of picking up the fresh one on the very next file.
				$store_id = $provider->wpsc_provider_store_id( $ai_settings['api_key'] );

				// Attach file.
				$attach = $provider->wpsc_attach_file( $store_id, $file_id, $ai_settings['api_key'] );

				// Same stale-store scenario as above, but surfaced at attach time (OpenAI).
				if ( is_wp_error( $attach ) && 'vector_store_not_found' === $attach->get_error_code() ) {
					$provider->wpsc_clear_provider_store_id();
					self::wpsc_requeue_for_stale_store( $training, $attach->get_error_message(), $file_id );
					return;
				}

				// OpenAI's vector-store-file attach (and Gemini's attach, folded into its
				// upload call) is a long-running operation - the file was uploaded, but
				// chunking/embedding was still in progress when this tick's bounded poll
				// window ran out. Not an actual failure, so requeue for a fresh attempt
				// instead of dead-ending the row on a false FAILED.
				if ( is_wp_error( $attach ) && 'attach_pending' === $attach->get_error_code() ) {
					self::wpsc_requeue_for_pending_operation( $training, $attach->get_error_message(), $file_id );
					return;
				}

				if ( is_wp_error( $attach ) || empty( $attach ) ) {
					// File was uploaded to the provider but failed to attach to the vector/file search store
					// (e.g. permission-denied on that endpoint, or the provider itself couldn't index the
					// content - see attach_failed). Surface the real reason when one is available. Keep
					// provider_file_id so it can be cleaned up or retried later instead of silently marking
					// this as indexed.
					$reason = is_wp_error( $attach )
						? sprintf( 'Failed to attach file to %1$s vector store / file search store: %2$s', ucfirst( $ai_settings['provider'] ), $attach->get_error_message() )
						: 'Failed to attach file to ' . ucfirst( $ai_settings['provider'] ) . ' vector store / file search store';
					self::wpsc_mark_failed( $training, $reason, $file_id );
					return;
				}

				$training->status           = WPSC_PS_AIT_Status::INDEXED;
				$training->provider_file_id = $file_id;
				$training->save();

				// After successful upload and attach, delete the local file to save space.
				self::wpsc_delete_local_file( $file_path );
			} catch ( \Throwable $e ) {

				$retry_count = self::wpsc_get_training_meta( $training, 'exception_retry_count', 0 ) + 1;

				if ( $retry_count > 3 ) {
					self::wpsc_mark_failed( $training, $e->getMessage() );
					return;
				}

				self::wpsc_set_training_meta( $training, 'exception_retry_count', $retry_count );
				self::wpsc_set_failure_reason( $training, $e->getMessage() );
				$training->status = WPSC_PS_AIT_Status::NEW; // Reset to new for retry.
				$training->save();
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
		 * Build a human-readable fallback name for a training row whose real title/filename
		 * was never produced (e.g. the request to the source failed before the post's title
		 * could be read) - so the row still shows up as something recognizable (which post
		 * or URL it was for) in the training list rather than a blank name.
		 *
		 * @param WPSC_RAG_Training_File $training The training data model instance.
		 * @return string
		 */
		private static function wpsc_get_fallback_training_name( $training ) {

			if ( WPSC_PS_AIT_Source::URL === $training->source ) {
				$meta = json_decode( $training->meta_data, true );
				return is_array( $meta ) && ! empty( $meta['url'] ) ? $meta['url'] : '';
			}

			return sprintf( '%s #%d', $training->source, absint( $training->source_id ) );
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
		 * Requeue a training row whose upload is a long-running provider-side operation
		 * that was still processing (not done) when this tick's bounded poll window ran
		 * out - e.g. Gemini's uploadToFileSearchStore, or OpenAI's vector-store-file
		 * attach still "in_progress". Not an actual failure, just slower than a single
		 * tick can wait for. Capped like the other retry paths in this file so an
		 * operation that never finishes on the provider's side eventually lands on FAILED
		 * instead of requeuing forever.
		 *
		 * @param WPSC_RAG_Training_File $training The training data model instance.
		 * @param string                 $message The reason to persist for admin visibility.
		 * @param string                 $provider_file_id Provider-side file ID, if one was created.
		 * @return void
		 */
		private static function wpsc_requeue_for_pending_operation( $training, $message = '', $provider_file_id = '' ) {

			$retry_count = self::wpsc_get_training_meta( $training, 'pending_operation_retry_count', 0 ) + 1;

			if ( $retry_count > 3 ) {
				self::wpsc_mark_failed( $training, $message, $provider_file_id );
				return;
			}

			self::wpsc_set_training_meta( $training, 'pending_operation_retry_count', $retry_count );
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
		 * Meta_data key marking a row rejected specifically for having empty/too-short/
		 * duplicate extracted content (as opposed to any other reason a row can end up
		 * FAILED - network, API, database, unsupported format, etc.) - see the
		 * NO_RAG_CONTENT_FOUND branch in wpsc_process_single_upload(). Queried by
		 * get_skipped_insufficient_content_count() so the admin-visible "Skipped" counts
		 * reflect the rows actually still in the database, rather than a separately
		 * maintained running total.
		 *
		 * @var string
		 */
		private const SKIP_REASON_META_KEY = 'skip_reason';

		/**
		 * Meta_data value for SKIP_REASON_META_KEY identifying the insufficient-content skip.
		 *
		 * @var string
		 */
		private const SKIP_REASON_INSUFFICIENT_CONTENT = 'insufficient_content';

		/**
		 * Get the count of rows failed for insufficient content, for a source - either its
		 * source-wide total, or just for one of its post types. Queries the
		 * WPSC_RAG_Training_File table directly (status = FAILED rows tagged with
		 * SKIP_REASON_META_KEY = SKIP_REASON_INSUFFICIENT_CONTENT in meta_data), since
		 * those rows are kept (not deleted) for admin review.
		 *
		 * @param string $doc_source Training source slug.
		 * @param string $post_type  Optional post type slug; omit for the source-wide total.
		 * @return int
		 */
		public static function get_skipped_insufficient_content_count( $doc_source, $post_type = '' ) {

			$doc_source = sanitize_key( $doc_source );
			if ( '' === $doc_source ) {
				return 0;
			}

			$meta_query = array(
				'relation' => 'AND',
				array(
					'slug'    => 'doc_source',
					'compare' => '=',
					'val'     => $doc_source,
				),
				array(
					'slug'    => 'status',
					'compare' => '=',
					'val'     => WPSC_PS_AIT_Status::FAILED,
				),
				array(
					'slug'    => 'custom_query',
					'compare' => '=',
					'val'     => "(
						meta_data IS NOT NULL
						AND JSON_VALID(meta_data) = 1
						AND JSON_CONTAINS_PATH(meta_data, 'one', '$." . self::SKIP_REASON_META_KEY . "') = 1
						AND JSON_UNQUOTE(JSON_EXTRACT(meta_data, '$." . self::SKIP_REASON_META_KEY . "')) = '" . self::SKIP_REASON_INSUFFICIENT_CONTENT . "'
					)",
				),
			);

			if ( '' !== $post_type ) {
				$meta_query[] = array(
					'slug'    => 'source',
					'compare' => '=',
					'val'     => sanitize_key( $post_type ),
				);
			}

			return (int) WPSC_RAG_Training_File::count( array( 'meta_query' => $meta_query ) );
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
		 * Whether an upload tick is genuinely in flight for a provider right now.
		 *
		 * WP-Cron unschedules a single event before invoking its callback (see
		 * wp-cron.php), so wp_next_scheduled( 'wpsc_ai_training_upload' ) already reads
		 * false for the entire duration of the tick that is actively uploading - not just
		 * once it has finished. Settings screens that use "not scheduled" as a proxy for
		 * "stuck" (see the "Retry Upload" link in
		 * WPSC_PS_AI_Setting_AI_Training::edit_ai_training_source()) must also check this,
		 * otherwise that link flashes on for every normal upload, not only a stuck one.
		 *
		 * @param string $provider_slug Provider slug.
		 * @return bool
		 */
		public static function is_upload_running( $provider_slug ) {

			$locked_at = (int) get_option( self::get_upload_lock_key( $provider_slug ), 0 );

			return $locked_at > 0 && ( time() - $locked_at ) < self::UPLOAD_LOCK_TIMEOUT;
		}

		/**
		 * Acquire the per-provider upload lock, reclaiming it first if it has gone stale
		 * (the previous holder crashed/timed out mid-tick without releasing it). Mirrors
		 * WPSC_PS_AI_Setting_AI_Training_Actions::acquire_sync_lock() - add_option() is used
		 * deliberately, since MySQL enforces uniqueness on option_name, making the initial
		 * acquisition atomic without requiring any table/schema change.
		 *
		 * @param string $provider_slug Provider slug.
		 * @return bool True if the lock was acquired.
		 */
		private static function acquire_upload_lock( $provider_slug ) {

			$lock_key = self::get_upload_lock_key( $provider_slug );

			if ( add_option( $lock_key, time(), '', 'no' ) ) {
				return true;
			}

			$locked_at = (int) get_option( $lock_key, 0 );
			if ( $locked_at > 0 && ( time() - $locked_at ) < self::UPLOAD_LOCK_TIMEOUT ) {
				return false;
			}

			// Stale lock (or unreadable) - reclaim it.
			delete_option( $lock_key );
			return add_option( $lock_key, time(), '', 'no' );
		}

		/**
		 * Release the per-provider upload lock.
		 *
		 * @param string $provider_slug Provider slug.
		 * @return void
		 */
		private static function release_upload_lock( $provider_slug ) {
			delete_option( self::get_upload_lock_key( $provider_slug ) );
		}

		/**
		 * Build the option name used to lock a provider's upload tick against concurrent
		 * WP-Cron dispatches.
		 *
		 * @param string $provider_slug Provider slug.
		 * @return string
		 */
		private static function get_upload_lock_key( $provider_slug ) {
			return 'wpsc_ait_upload_lock_' . md5( $provider_slug );
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
			if ( ! $real_file || ! $real_base || strpos( $real_file, $real_base . DIRECTORY_SEPARATOR ) !== 0 ) {
				return;
			}

			if ( file_exists( $real_file ) ) {
				wp_delete_file( $real_file );
			}
		}

		/**
		 * Delete AI training record.
		 *
		 * Drains as many pending deletions as possible within a single invocation
		 * (deletes are cheap, single DELETE calls, so it's safe to batch aggressively),
		 * bounded by a wall-clock time budget so it stays within typical cron execution
		 * limits. If the queue is not fully drained within that budget, it reschedules
		 * itself to continue immediately rather than relying on a much larger number of
		 * separate wp-cron.php invocations to eventually catch up.
		 *
		 * @param array $ai_settings AI settings array.
		 * @return void
		 */
		public static function delete_ai_training_record( $ai_settings ) {

			try {
				$provider = WPSC_PS_AIT_Provider_Factory::get_current_provider( $ai_settings['provider'] );
			} catch ( \Throwable $e ) {
				// Invalid/unset provider setting - nothing to delete against. Leave
				// pending rows in place for a later tick rather than letting an uncaught
				// exception fatal this cron dispatch.
				return;
			}
			$batch_size   = 200;
			$time_budget  = time() + 20;
			$more_pending = false;

			do {
				$training_response = WPSC_RAG_Training_File::find(
					array(
						'items_per_page' => $batch_size,
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

				// If this pass processed a full batch, there may be more beyond it.
				$more_pending = count( $training_data ) >= $batch_size;

			} while ( ! empty( $training_data ) && time() < $time_budget );

			// Schedule next run if the queue wasn't fully drained (more pages left, or we hit the time budget).
			if ( ( $more_pending || time() >= $time_budget ) && ! wp_next_scheduled( 'wpsc_delete_ai_training_record' ) ) {
				wp_schedule_single_event( time(), 'wpsc_delete_ai_training_record' );
			}
		}

		/**
		 * Prepare a WP post/page's raw HTML content for RAG training.
		 *
		 * Deliberately deterministic - this used to run the full document through
		 * an LLM (wpsc_proccess_and_clean_raw_content_for_rag()), which meant the
		 * provider's response was capped by the "Max Tokens" setting (500 by
		 * default) and large documents got reduced to a short summary before ever
		 * reaching the vector/file-search store. Reusing WPSC_Content_Extractor
		 * here instead - the same deterministic HTML→text extractor already used
		 * for the URL training source - strips technical noise (nav/footer/
		 * scripts/etc.) and HTML tags while preserving the full document text, so
		 * nothing is summarized, truncated, or rewritten before upload.
		 *
		 * @param string $row_content Raw HTML content (e.g. a post's rendered content).
		 * @return string Plain-text content, structure preserved, no HTML tags.
		 */
		public static function wpsc_prepare_post_content_for_rag( $row_content ) {

			if ( empty( $row_content ) || ! is_string( $row_content ) ) {
				return '';
			}

			$clean_text = WPSC_Content_Extractor::extract_from_html( $row_content );

			// The DOM-based extractor can fail on badly malformed markup (returns
			// ''). Fall back to a plain deterministic tag-strip rather than losing
			// the document entirely - still no LLM, no summarization.
			if ( '' === trim( (string) $clean_text ) ) {
				$clean_text = wp_strip_all_tags( $row_content );
				$clean_text = html_entity_decode( $clean_text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$clean_text = preg_replace( "/\n{3,}/", "\n\n", $clean_text );
			}

			return trim( $clean_text );
		}

		/**
		 * Write prepared training content to a file, validated against the configured
		 * upload size limit.
		 *
		 * Only called from the cron-driven post type/website sync path (see
		 * get_post_type_data_for_training()), so failures return WP_Error instead of
		 * calling wp_send_json_error() - that helper calls wp_die(), which would
		 * otherwise fatally kill the WP-Cron request instead of letting the caller
		 * log a reason and move on to the next training file.
		 *
		 * @param string $cleaned_content The cleaned content of the ticket.
		 * @param array  $meta_data       Additional metadata for the file, such as ticket ID.
		 * @return array|WP_Error The file path (absolute server path) or WP_Error on failure.
		 */
		public static function wpsc_create_file_from_cleaned_content( $cleaned_content, $meta_data = array() ) {

			// Validate input content.
			if ( empty( $cleaned_content ) || ! is_string( $cleaned_content ) ) {
				return new WP_Error( 'wpsc_ai_training_empty_content', __( 'Ticket content is empty or invalid.', 'wpsc-ps' ) );
			}

			$id = isset( $meta_data['id'] ) ? intval( $meta_data['id'] ) : 0;
			$title = isset( $meta_data['title'] ) ? sanitize_text_field( $meta_data['title'] ) : 'file-' . $id . '-' . time();

			$upload_dir = wp_upload_dir();

			// Validate upload directory.
			if ( empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
				return new WP_Error( 'wpsc_ai_training_no_upload_dir', __( 'Upload directory not available.', 'wpsc-ps' ) );
			}

			$today = new DateTime( 'now' );
			$base_dir = $upload_dir['basedir'] . '/wpsc/ai-training/' . $today->format( 'Y' ) . '/' . $today->format( 'm' );
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );

			// Create directory if not exists.
			if ( ! file_exists( $base_dir ) ) {
				if ( ! wp_mkdir_p( $base_dir ) ) {
					return new WP_Error( 'wpsc_ai_training_mkdir_failed', __( 'Failed to create directory.', 'wpsc-ps' ) );
				}
			}

			// Check writable.
			if ( ! is_writable( $base_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				return new WP_Error( 'wpsc_ai_training_dir_not_writable', __( 'Directory is not writable: ', 'wpsc-ps' ) . $base_dir );
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
				return new WP_Error( 'wpsc_ai_training_write_failed', __( 'Failed to write file.', 'wpsc-ps' ) );
			}

			// Validate file size.
			$file_size = filesize( $file_path );
			if ( false === $file_size || $file_size <= 0 ) {
				wp_delete_file( $file_path );
				return new WP_Error( 'wpsc_ai_training_empty_file', __( 'Generated file is empty.', 'wpsc-ps' ) );
			}

			// Get file type.
			$file_type_data = wp_check_filetype( $file_name );
			$file_type = ! empty( $file_type_data['type'] ) ? $file_type_data['type'] : 'text/plain';

			// Create temp file for upload simulation.
			$tmp_file = tempnam( $base_dir . '/', $file_name );
			if ( ! $tmp_file || ! copy( $file_path, $tmp_file ) ) {
				wp_delete_file( $file_path );
				return new WP_Error( 'wpsc_ai_training_tmp_file_failed', __( 'Failed to create temp file.', 'wpsc-ps' ) );
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
			// Validate file using existing validator - $throw_json = false so an oversized/
			// invalid file comes back as a WP_Error for this cron path instead of wp_die()'ing.
			$validate = WPSC_PS_AI_Functions::wpsc_validate_ai_file_uploads( $files, $ai_settings, false );

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
		 * Build the prompt asking the AI to judge whether prepared post content is
		 * useful enough to index into the RAG knowledge base - e.g. shopping cart/
		 * checkout pages, empty boilerplate, and other content that costs a provider
		 * embedding/indexing call and vector store space without ever being useful for
		 * a support agent's knowledge base search.
		 *
		 * Only a leading slice of the content is sent - this is a coarse quality
		 * judgement, not a task that needs the full document.
		 *
		 * @param string $content Prepared plain-text content (see wpsc_prepare_post_content_for_rag()).
		 * @return string The prompt to send to the AI provider.
		 */
		public static function wpsc_prompt_to_assess_content_quality_for_rag( $content ) {

			$max_chars = 4000;
			$snippet   = function_exists( 'mb_substr' )
				? mb_substr( (string) $content, 0, $max_chars, 'UTF-8' )
				: substr( (string) $content, 0, $max_chars );

			return '
				You are a strict content-quality gatekeeper for a Retrieval-Augmented Generation (RAG)
				knowledge base used by a customer support AI assistant.

				TASK:
				Decide whether the CONTENT below is useful as a searchable knowledge base document for
				answering customer support questions.

				REJECT as not useful, content such as:
				- Shopping cart / checkout / order summary pages (line items, prices, quantities, "add to cart", totals)
				- Empty, boilerplate, or placeholder text with no real information
				- Navigation menus, breadcrumbs, or link lists with no explanatory text
				- Login/account pages with no documentation content
				- Content that is mostly non-informational (ads, unrelated legal text, pure media captions)

				ACCEPT as useful, content such as:
				- Articles, guides, documentation, FAQs, how-to instructions
				- Product/feature/service descriptions
				- Policy or troubleshooting information a customer might ask about

				OUTPUT FORMAT:
				Return ONLY this raw JSON object, nothing else - no markdown, no explanation:
				{"quality_score": <integer 0-100, how useful this is for support-question retrieval>, "useful_for_rag": <true|false>}

				CONTENT:
				"""
				' . $snippet . '
				"""
			';
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
			$stripped_content = trim( wp_strip_all_tags( $content ) );

			// Dynamically-rendered post types (tabs, page-builder modules, etc.) often
			// leave post_content/the REST rendered field as near-empty boilerplate,
			// since the real content only exists client-side. A record this short is
			// never useful for retrieval, so reject it before it costs a provider
			// embedding/indexing call and vector store space.
			$min_content_length = 50;
			if ( strlen( $stripped_content ) < $min_content_length ) {
				return array(
					'error'   => 'NO_RAG_CONTENT_FOUND',
					'message' => sprintf(
						'Too short content to be useful for search (%1$d characters, minimum %2$d required).',
						strlen( $stripped_content ),
						$min_content_length
					),
					'title'   => $title,
				);
			}

			// Reject exact-duplicate boilerplate already indexed for a different post
			// under this same source - e.g. a fixed "no content configured" placeholder
			// repeated verbatim across many records. A resync of this same post with
			// unchanged content is not affected, since its own prior record is excluded.
			$content_hash = md5( preg_replace( '/\s+/', ' ', strtolower( $stripped_content ) ) );

			$duplicate_count = WPSC_RAG_Training_File::count(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'source',
							'compare' => '=',
							'val'     => $post_type,
						),
						array(
							'slug'    => 'doc_source',
							'compare' => '=',
							'val'     => $training->doc_source,
						),
						array(
							'slug'    => 'status',
							'compare' => 'NOT IN',
							// FAILED rows never resulted in an actual provider-side document -
							// their content_hash (set before the quality-gate/file-creation
							// steps that can still fail afterward) must not count as "already
							// indexed" and block a later, unrelated post with the same content.
							'val'     => array( WPSC_PS_AIT_Status::DELETE, WPSC_PS_AIT_Status::FAILED ),
						),
						array(
							'slug'    => 'source_id',
							'compare' => 'NOT IN',
							'val'     => array( (string) $post_id ),
						),
						array(
							'slug'    => 'custom_query',
							'compare' => '=',
							'val'     => "(
								meta_data IS NOT NULL
								AND JSON_VALID(meta_data) = 1
								AND JSON_CONTAINS_PATH(meta_data, 'one', '$.content_hash') = 1
								AND JSON_UNQUOTE(JSON_EXTRACT(meta_data, '$.content_hash')) = '" . esc_sql( $content_hash ) . "'
							)",
						),
					),
				)
			);

			if ( $duplicate_count > 0 ) {
				return array(
					'error'   => 'NO_RAG_CONTENT_FOUND',
					'message' => 'Duplicate content already indexed for another post under this source.',
					'title'   => $title,
				);
			}

			// Recorded now so later posts sharing this same boilerplate can be detected
			// above; the caller persists this alongside the name/file_path it also sets.
			self::wpsc_set_training_meta( $training, 'content_hash', $content_hash );

			$post_meta = array(
				'slug'  => $post_type,
				'id'    => $post_id,
				'title' => WPSC_PS_AI_Functions::wpsc_generate_string_key( $title ),
			);

			$post_content = self::wpsc_prepare_post_content_for_rag( $content );
			if ( '' === trim( $post_content ) ) {
				return array(
					'error'   => 'NO_RAG_CONTENT_FOUND',
					'message' => 'No usable content remained after cleaning for training.',
					'title'   => $title,
				);
			}

			// Ask the AI provider to judge whether this content (e.g. cart/checkout
			// boilerplate, empty listings) is actually useful for RAG retrieval before
			// it costs a provider embedding/indexing call and vector store space. If the
			// provider couldn't be reached or its response couldn't be parsed, $quality
			// is false - treated as "unable to judge" rather than "not useful", so a
			// transient AI-side issue never blocks otherwise-valid content from being indexed.
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			try {
				$quality = WPSC_PS_AIT_Provider_Factory::get_current_provider( $ai_settings['provider'] )
					->wpsc_assess_content_quality_for_rag( $ai_settings, $post_content );
			} catch ( \Throwable $e ) {
				$quality = false;
			}

			if ( is_array( $quality ) && ! $quality['useful_for_rag'] && $quality['quality_score'] < 35 ) {
				return array(
					'error'   => 'NO_RAG_CONTENT_FOUND',
					'message' => sprintf( 'Content did not meet quality threshold for RAG (score: %d/100).', $quality['quality_score'] ),
					'title'   => $title,
				);
			}

			$file_uploads = self::wpsc_create_file_from_cleaned_content( $post_content, $post_meta );

			if ( is_wp_error( $file_uploads ) ) {
				return array(
					'error'   => 'FILE_PROCESSING_ERROR',
					'message' => $file_uploads->get_error_message(),
					'title'   => $title,
				);
			}

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
