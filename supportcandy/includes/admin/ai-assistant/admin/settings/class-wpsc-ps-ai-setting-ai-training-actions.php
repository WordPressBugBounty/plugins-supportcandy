<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Setting_AI_Training_Actions' ) ) :

	final class WPSC_PS_AI_Setting_AI_Training_Actions {

		/**
		 * Number of posts to import per request/page. Kept small on purpose so a single
		 * cron tick (one page) stays cheap on shared hosting - large post types are paged
		 * across many small ticks instead of a few huge ones.
		 */
		private const IMPORT_POSTS_PER_REQUEST = 10;

		/**
		 * How long to keep the accumulated remote-post-ids transient around.
		 *
		 * Only needs to survive one sync run; generous enough to cover a slow/large site
		 * without leaving stale data around indefinitely if a run is abandoned midway.
		 */
		private const SYNC_IDS_TRANSIENT_EXPIRY = HOUR_IN_SECONDS;

		/**
		 * Option storing the in-progress/last sync state per training source slug.
		 */
		private const SYNC_PROGRESS_OPTION = 'wpsc-ps-ait-sync-progress';

		/**
		 * How long a job may go without progressing before its cron tick is presumed
		 * lost and rescheduled. Generous enough to cover one slow page of a remote site.
		 */
		private const SYNC_STALL_TIMEOUT = 2 * MINUTE_IN_SECONDS;

		/**
		 * How long a job may go without progressing before it is given up on and marked
		 * failed, so the progress bar and the screen's buttons are never locked forever.
		 */
		private const SYNC_STALL_GIVE_UP = 10 * MINUTE_IN_SECONDS;

		/**
		 * How long the per-source sync lock (see acquire_sync_lock()) is honored before it
		 * is considered abandoned (the holder crashed/timed out mid-tick) and reclaimed.
		 * Generous enough to cover one REST fetch (30s timeout) plus processing.
		 */
		private const SYNC_LOCK_TIMEOUT = MINUTE_IN_SECONDS;

		/**
		 * Maximum consecutive recoverable-error retries for a single post type's current
		 * page before it is skipped rather than retried forever.
		 */
		private const MAX_FETCH_RETRIES = 3;

		/**
		 * Hard cap on pages processed for a single post type, as a last-resort safeguard
		 * against a misbehaving endpoint that never returns a short/empty page (so
		 * end-of-data can never otherwise be detected). At the default batch size this
		 * covers 50,000 posts for a single post type, which is far beyond any realistic
		 * per-post-type corpus.
		 */
		private const MAX_PAGES_PER_POST_TYPE = 5000;

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Add new edit & Update an existing source's details (name, post types). Syncing is handled separately.
			add_action( 'wp_ajax_wpsc_fetch_wordpress_endpoints_posts', array( __CLASS__, 'fetch_wordpress_endpoints_posts' ) );
			add_action( 'wp_ajax_wpsc_set_add_ai_training_source', array( __CLASS__, 'set_add_ai_training_source' ) );
			add_action( 'wp_ajax_wpsc_update_edit_ai_training_source', array( __CLASS__, 'update_edit_ai_training_source' ) );

			// Sync posts for source: kick off a background sync and let the client poll its progress.
			add_action( 'wp_ajax_wpsc_sync_posts_for_ai_training', array( __CLASS__, 'sync_posts_for_ai_training' ) );
			add_action( 'wp_ajax_wpsc_get_ait_sync_progress', array( __CLASS__, 'get_ait_sync_progress' ) );
			add_action( 'wpsc_ait_run_sync', array( __CLASS__, 'run_sync_tick' ) );

			// Delete training source.
			add_action( 'wp_ajax_wpsc_get_delete_ai_training', array( __CLASS__, 'get_delete_ai_training' ) );
			add_action( 'wp_ajax_wpsc_delete_all_ait_posts', array( __CLASS__, 'delete_all_ait_posts' ) );
		}

		/**
		 * Delete AI training source.
		 *
		 * @return void
		 */
		public static function get_delete_ai_training() {

			if ( check_ajax_referer( 'wpsc_get_delete_ai_training', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request', 401 );
			}

			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'wpsc-ps' ), 401 );
			}

			$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
			if ( '' === $slug || 'local' == $slug ) {
				wp_send_json_error( new WP_Error( '001', __( 'Invalid source!', 'wpsc-ps' ) ), 400 );
			}

			$sources = get_option( 'wpsc-ps-ai-training-sources', array() );
			$sources = is_array( $sources ) ? $sources : array();

			foreach ( $sources as $index => $source ) {
				if ( ! is_array( $source ) ) {
					continue;
				}

				$source_slug = isset( $source['slug'] ) ? sanitize_text_field( $source['slug'] ) : '';
				if ( $source_slug !== $slug ) {
					continue;
				}

				// Delete all training data for the source being removed.
				if ( isset( $source['post-types'] ) && is_array( $source['post-types'] ) ) {
					foreach ( $source['post-types'] as $post_type ) {
						if ( isset( $post_type['slug'] ) ) {
							WPSC_PS_AIT_Controller::delete_all_training_data_by_source( $post_type['slug'], $source_slug );
						}
					}
				}

				unset( $sources[ $index ] );
				break;
			}
			update_option( 'wpsc-ps-ai-training-sources', array_values( $sources ) );

			self::clear_sync_progress( $slug );
		}

		/**
		 * Delete AI training source.
		 *
		 * @return void
		 */
		public static function delete_all_ait_posts() {

			if ( check_ajax_referer( 'wpsc_delete_all_ait_posts', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request', 401 );
			}

			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'wpsc-ps' ), 401 );
			}

			$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
			if ( '' === $slug ) {
				wp_send_json_error( array( 'message' => __( 'Invalid source!', 'wpsc-ps' ) ), 400 );
			}

			$sources = get_option( 'wpsc-ps-ai-training-sources', array() );
			$sources = is_array( $sources ) ? $sources : array();

			$source_index = null;
			foreach ( $sources as $index => $source ) {
				if ( is_array( $source ) && isset( $source['slug'] ) && $source['slug'] === $slug ) {
					$source_index = $index;
					break;
				}
			}

			if ( null === $source_index ) {
				wp_send_json_error( array( 'message' => __( 'Training source not found.', 'wpsc-ps' ) ), 400 );
			}

			foreach ( $sources[ $source_index ]['post-types'] as &$post_type ) {
				$post_type['status'] = 0;
				WPSC_PS_AIT_Controller::delete_all_training_data_by_source( $post_type['slug'] ?? '', $slug );
			}
			unset( $post_type );

			update_option( 'wpsc-ps-ai-training-sources', $sources );
			self::clear_sync_progress( $slug );
			wp_send_json_success( array( 'message' => __( 'All posts deleted successfully.', 'wpsc-ps' ) ) );
		}

		/**
		 * Fetch WordPress endpoint data.
		 *
		 * @return void
		 */
		public static function fetch_wordpress_endpoints_posts() {

			if ( check_ajax_referer( 'wpsc_fetch_wordpress_endpoints_posts', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( array( 'message' => 'Unauthorized request' ), 401 );
			}

			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'wpsc-ps' ), 401 );
			}

			$endpoint = isset( $_POST['ait-wp-endpoint'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ait-wp-endpoint'] ) ) ) : '';
			if ( empty( $endpoint ) || ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
				wp_send_json_error( array( 'message' => __( 'Please enter a valid URL.', 'wpsc-ps' ) ), 400 );
			}

			$ait_post_types = isset( $_POST['ait-post-types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ait-post-types'] ) ) : array();
			if ( ! is_array( $ait_post_types ) ) {
				$ait_post_types = array();
			}

			// Keep post types that are already enabled (status = 1) on the saved source checked,
			// regardless of whether the client happened to submit their checkbox state.
			$source_slug = isset( $_POST['ait-training-type'] ) ? sanitize_key( wp_unslash( $_POST['ait-training-type'] ) ) : '';
			if ( '' !== $source_slug ) {
				$saved_source = WPSC_PS_AIT_Source::get_training_source( $source_slug );
				$saved_post_types = isset( $saved_source['post-types'] ) && is_array( $saved_source['post-types'] ) ? $saved_source['post-types'] : array();
				foreach ( $saved_post_types as $saved_post_type ) {
					if ( is_array( $saved_post_type ) && ! empty( $saved_post_type['status'] ) && ! empty( $saved_post_type['slug'] ) ) {
						$ait_post_types[] = sanitize_text_field( $saved_post_type['slug'] );
					}
				}
				$ait_post_types = array_values( array_unique( $ait_post_types ) );
			}

			$rag_types_response = self::get_rag_types( $endpoint );
			if ( ! is_array( $rag_types_response ) || empty( $rag_types_response ) ) {
				wp_send_json_error( array( 'message' => __( 'No valid post types found at the provided endpoint.', 'wpsc-ps' ) ), 400 );
			}

			if ( empty( $rag_types_response['success'] ) ) {
				$error_message = ! empty( $rag_types_response['error'] ) ? sanitize_text_field( $rag_types_response['error'] ) : __( 'Failed to fetch post types from the endpoint.', 'wpsc-ps' );
				wp_send_json_error( array( 'message' => $error_message ), 400 );
			}

			$rag_types = isset( $rag_types_response['data'] ) && is_array( $rag_types_response['data'] ) ? $rag_types_response['data'] : array();
			if ( empty( $rag_types ) ) {
				wp_send_json_error( array( 'message' => __( 'No valid post types found at the provided endpoint.', 'wpsc-ps' ) ), 400 );
			}

			$rag_types_html = self::get_rag_types_html( $rag_types, $ait_post_types );
			wp_send_json_success(
				array(
					'message'        => esc_attr__( 'Select Post Types', 'wpsc-ps' ),
					'rag_types_html' => $rag_types_html,
				),
				200
			);
		}

		/**
		 * Get AI training WordPress endpoint UI
		 *
		 * @return void
		 */
		public static function set_add_ai_training_source() {

			if ( check_ajax_referer( 'wpsc_set_add_ai_training_source', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request', 401 );
			}

			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'wpsc-ps' ), 401 );
			}

			$endpoint    = isset( $_POST['ait-wp-endpoint'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ait-wp-endpoint'] ) ) ) : '';
			$source_slug = isset( $_POST['ait-name'] ) ? sanitize_text_field( wp_unslash( $_POST['ait-name'] ) ) : '';

			if ( '' === trim( $source_slug ) || '' === trim( $endpoint ) ) {
				wp_send_json_error( new WP_Error( '002', __( 'Required fields are missing!', 'wpsc-ps' ) ), 400 );
			}

			// Build site url with wp-json appended to it.
			$site_url = untrailingslashit( esc_url_raw( $endpoint ) );
			$site_url = preg_replace( '#(?:/wp-json)+/?$#i', '', $site_url );
			if ( '' === $site_url || ! filter_var( $site_url, FILTER_VALIDATE_URL ) ) {
				wp_send_json_error( new WP_Error( '003', __( 'Please enter a valid URL.', 'wpsc-ps' ) ), 400 );
			}
			$api_url = trailingslashit( $site_url ) . 'wp-json/';

			$ait_sources = get_option( 'wpsc-ps-ai-training-sources', array() );
			$ait_sources = is_array( $ait_sources ) ? $ait_sources : array();

			// Duplicate api-url is not allowed across sources.
			foreach ( $ait_sources as $source ) {
				if ( is_array( $source ) && ! empty( $source['api-url'] ) && untrailingslashit( $source['api-url'] ) === untrailingslashit( $api_url ) ) {
					wp_send_json_error(
						array(
							'message' => __( 'Website endpoint already available.', 'wpsc-ps' ),
						),
						400
					);
				}
			}

			// Build a unique slug: "wpsc-{name}", suffixed with the current time if it already exists.
			$existing_slugs = wp_list_pluck( array_filter( $ait_sources, 'is_array' ), 'slug' );
			$slug           = 'wpsc-' . sanitize_title( $source_slug );
			if ( in_array( $slug, $existing_slugs, true ) ) {
				$slug .= '-' . time();
			}

			// Post types are selected later on the edit screen.
			$ait_sources[] = array(
				'slug'       => $slug,
				'type'       => 'wordpress_website',
				'name'       => $source_slug,
				'api-url'    => $api_url,
				'post-types' => array(),
			);

			if ( ! update_option( 'wpsc-ps-ai-training-sources', $ait_sources ) ) {
				wp_send_json_error( new WP_Error( '005', __( 'Failed to save training source.', 'wpsc-ps' ) ), 400 );
			}

			wp_send_json_success(
				array(
					'source_slug' => $slug,
					'edit_nonce'  => wp_create_nonce( 'wpsc_edit_ai_training_source' ),
				)
			);
		}

		/**
		 * Update an existing AI training source's name and post types.
		 *
		 * Also kicks off a background sync (see start_sync_for_source()) when the
		 * update leaves at least one post type enabled.
		 *
		 * @return void
		 */
		public static function update_edit_ai_training_source() {

			if ( check_ajax_referer( 'wpsc_update_edit_ai_training_source', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request', 401 );
			}

			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'wpsc-ps' ), 401 );
			}

			$source_slug          = isset( $_POST['ait-training-type'] ) ? sanitize_key( wp_unslash( $_POST['ait-training-type'] ) ) : '';
			$source_name          = isset( $_POST['ait-name'] ) ? sanitize_text_field( wp_unslash( $_POST['ait-name'] ) ) : '';
			$post_types_data_json = isset( $_POST['ait-post-types-data'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ait-post-types-data'] ) ) : '';
			$post_types_data      = '' !== $post_types_data_json ? json_decode( $post_types_data_json, true ) : array();

			if ( '' === $source_slug || '' === trim( $source_name ) ) {
				wp_send_json_error( new WP_Error( '002', __( 'Required fields are missing!', 'wpsc-ps' ) ), 400 );
			}

			$ait_sources = get_option( 'wpsc-ps-ai-training-sources', array() );
			$ait_sources = is_array( $ait_sources ) ? $ait_sources : array();

			$source_index = null;
			foreach ( $ait_sources as $index => $source ) {
				if ( is_array( $source ) && isset( $source['slug'] ) && $source['slug'] === $source_slug ) {
					$source_index = $index;
					break;
				}
			}

			if ( null === $source_index ) {
				wp_send_json_error( new WP_Error( '005', __( 'Training source not found.', 'wpsc-ps' ) ), 400 );
			}

			$api_url             = $ait_sources[ $source_index ]['api-url'] ?? '';
			$previous_post_types = isset( $ait_sources[ $source_index ]['post-types'] ) && is_array( $ait_sources[ $source_index ]['post-types'] ) ? $ait_sources[ $source_index ]['post-types'] : array();
			$post_types          = self::build_post_types_from_data( $post_types_data, $previous_post_types, $api_url );

			// Post types that were disabled in this update no longer need their synced training data.
			self::delete_training_data_for_disabled_post_types( $previous_post_types, $post_types, $source_slug );

			$ait_sources[ $source_index ]['name']       = $source_name;
			$ait_sources[ $source_index ]['post-types'] = $post_types;

			if ( ! update_option( 'wpsc-ps-ai-training-sources', $ait_sources ) ) {
				wp_send_json_error(
					array(
						'source_slug' => $source_slug,
						'message'     => __( 'No training source is updated.', 'wpsc-ps' ),
					)
				);
			}

			$enabled_post_types = self::get_enabled_post_types( $ait_sources[ $source_index ] );
			$is_sync = ! empty( $enabled_post_types );
			if ( $is_sync ) {
				self::start_sync_for_source( $ait_sources[ $source_index ], $enabled_post_types );
			}

			wp_send_json_success(
				array(
					'is_sync'     => $is_sync,
					'source_slug' => $source_slug,
					'message'     => __( 'Training source updated successfully.', 'wpsc-ps' ),
				)
			);
		}

		/**
		 * Build a normalized, slug-indexed post types list from raw post type data.
		 *
		 * The form only submits slug/name/status per post type - it never carries the
		 * endpoint (see wpsc_collect_ait_post_types_data() in ai-training.js) - so the
		 * endpoint that was previously stored for that same slug (set by the installer's
		 * defaults, or by a prior fetch_wordpress_endpoints_posts()/get_rag_types() sync)
		 * is carried forward here instead of being read off the submitted data. The
		 * submitted "slug" is actually the post type's rest_base (get_rag_types_html()
		 * uses rest_base as the checkbox value, which can differ from the post type's own
		 * name), so re-deriving api-url + '/wp/v2/' + slug is only a fallback for a slug
		 * that has never been saved before (e.g. a post type enabled for the first time).
		 *
		 * @param array  $post_types_data     Raw post type data (slug/name/status) submitted by the form.
		 * @param array  $previous_post_types The source's previously saved post types, used to carry the endpoint forward.
		 * @param string $api_url             The training source's api-url (e.g. rest_url() for local, or site wp-json root for remote sources), used only as a fallback.
		 * @return array List of normalized post type entries.
		 */
		private static function build_post_types_from_data( $post_types_data, $previous_post_types = array(), $api_url = '' ) {

			$previous_endpoints = array();
			foreach ( (array) $previous_post_types as $previous_post_type ) {
				if ( is_array( $previous_post_type ) && ! empty( $previous_post_type['slug'] ) && ! empty( $previous_post_type['endpoint'] ) ) {
					$previous_endpoints[ $previous_post_type['slug'] ] = $previous_post_type['endpoint'];
				}
			}

			$post_types_index = array();
			foreach ( (array) $post_types_data as $post_type ) {
				if ( ! is_array( $post_type ) || empty( $post_type['slug'] ) ) {
					continue;
				}
				$pt_slug = sanitize_text_field( $post_type['slug'] );

				if ( isset( $previous_endpoints[ $pt_slug ] ) ) {
					$endpoint = $previous_endpoints[ $pt_slug ];
				} else {
					$endpoint = '' !== $api_url ? trailingslashit( $api_url ) . 'wp/v2/' . $pt_slug : '';
				}

				$post_types_index[ $pt_slug ] = array(
					'slug'     => $pt_slug,
					'name'     => ! empty( $post_type['name'] ) ? sanitize_text_field( $post_type['name'] ) : ucwords( str_replace( array( '-', '_' ), ' ', $pt_slug ) ),
					'status'   => isset( $post_type['status'] ) && (int) $post_type['status'] === 1 ? 1 : 0,
					'endpoint' => $endpoint,
				);
			}

			return array_values( $post_types_index );
		}

		/**
		 * Resolve the enabled {slug, name} post types for a training source.
		 *
		 * @param array $source Training source.
		 * @return array List of { slug, name } for post types with status = 1.
		 */
		private static function get_enabled_post_types( array $source ) {

			$post_types = array();
			foreach ( (array) ( $source['post-types'] ?? array() ) as $post_type ) {
				if ( is_array( $post_type ) && ! empty( $post_type['slug'] ) && ! empty( $post_type['status'] ) ) {
					$post_types[] = array(
						'slug' => sanitize_key( $post_type['slug'] ),
						'name' => sanitize_text_field( $post_type['name'] ?? $post_type['slug'] ),
					);
				}
			}
			return $post_types;
		}

		/**
		 * Whether any document among this source's currently-enabled post types was synced
		 * under a different AI provider and does NOT already have its own copy under the
		 * one currently configured.
		 *
		 * Every training row records the provider it was uploaded to at insert time, and
		 * each provider keeps its own independent copy of a document rather than sharing or
		 * overwriting another provider's (see insert_training_post()) - switching the
		 * configured provider back and forth does not delete anything. So the only thing
		 * worth warning about is a document that has a copy under some other provider but
		 * none yet under the current one - it existed for the AI Assistant before, but
		 * won't be found by it now until it's synced under the newly selected provider.
		 * Coexisting with another provider's already-covered copy is normal, not a problem,
		 * and must not keep re-triggering this warning after that gap is closed.
		 *
		 * A document that has never been synced under any provider does not trigger this -
		 * that is simply "not yet synced", not a provider change, and warning about it here
		 * would be misleading.
		 *
		 * Implemented as one pluck() for the IDs already covered under the current provider
		 * plus one COUNT() excluding them - no REST calls, no per-post checks - so it stays
		 * cheap to run on every settings page load. Relies on WordPress post IDs already
		 * being unique across post types (the norm, since all post types share wp_posts),
		 * so a single id list can safely be checked across every enabled post type at once.
		 *
		 * @param string $source_slug             Training source slug.
		 * @param array  $enabled_post_type_slugs Enabled post type slugs for this source.
		 * @param string $current_provider        Currently configured AI provider.
		 * @return bool
		 */
		public static function source_has_other_provider_data( $source_slug, array $enabled_post_type_slugs, $current_provider ) {

			if ( '' === $source_slug || empty( $enabled_post_type_slugs ) ) {
				return false;
			}

			$covered_ids = WPSC_RAG_Training_File::pluck(
				'source_id',
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'doc_source',
							'compare' => '=',
							'val'     => $source_slug,
						),
						array(
							'slug'    => 'source',
							'compare' => 'IN',
							'val'     => $enabled_post_type_slugs,
						),
						array(
							'slug'    => 'status',
							'compare' => '!=',
							'val'     => WPSC_PS_AIT_Status::DELETE,
						),
						array(
							'slug'    => 'provider',
							'compare' => '=',
							'val'     => $current_provider,
						),
					),
				)
			);

			$meta_query = array(
				'relation' => 'AND',
				array(
					'slug'    => 'doc_source',
					'compare' => '=',
					'val'     => $source_slug,
				),
				array(
					'slug'    => 'source',
					'compare' => 'IN',
					'val'     => $enabled_post_type_slugs,
				),
				array(
					'slug'    => 'status',
					'compare' => '!=',
					'val'     => WPSC_PS_AIT_Status::DELETE,
				),
				array(
					'slug'    => 'provider',
					'compare' => '!=',
					'val'     => $current_provider,
				),
			);

			if ( ! empty( $covered_ids ) ) {
				$meta_query[] = array(
					'slug'    => 'source_id',
					'compare' => 'NOT IN',
					'val'     => array_map( 'absint', $covered_ids ),
				);
			}

			return WPSC_RAG_Training_File::count(
				array(
					'meta_query' => $meta_query,
				)
			) > 0;
		}

		/**
		 * AJAX: Kick off a background sync for a training source's enabled post types.
		 *
		 * The actual paging/importing happens in run_sync_tick(), driven by a
		 * self-chaining cron event (see start_sync_for_source()). The client polls
		 * progress via get_ait_sync_progress().
		 *
		 * @return void
		 */
		public static function sync_posts_for_ai_training() {

			if ( check_ajax_referer( 'wpsc_sync_posts_for_ai_training', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request', 401 );
			}

			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'wpsc-ps' ), 401 );
			}

			$source_slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
			if ( '' === $source_slug ) {
				wp_send_json_error( new WP_Error( '001', __( 'Invalid source!', 'wpsc-ps' ) ), 400 );
			}

			$source = WPSC_PS_AIT_Source::get_training_source( $source_slug );
			if ( empty( $source ) ) {
				wp_send_json_error( new WP_Error( '005', __( 'Training source not found.', 'wpsc-ps' ) ), 400 );
			}

			$post_types = self::get_enabled_post_types( $source );
			if ( empty( $post_types ) ) {
				wp_send_json_error( new WP_Error( '004', __( 'Please enable at least one post type to sync.', 'wpsc-ps' ) ), 400 );
			}

			self::start_sync_for_source( $source, $post_types );

			wp_send_json_success( array( 'source_slug' => $source_slug ) );
		}

		/**
		 * Initialize (or resume) the sync progress state for a source and schedule the
		 * background cron chain that will process it (see run_sync_tick()).
		 *
		 * If a job is already queued/running for this source, its in-progress post types
		 * are left untouched - only post types not already tracked get fresh state. This
		 * is what makes the call idempotent: re-triggering a sync mid-flight (a double
		 * click, a second admin/tab, or saving the edit form while a sync is running - none
		 * of which are prevented client-side) used to reset every post type back to
		 * page 1/total_pages 1 and start over, which is what produced the repeated
		 * "page 1 of 1, same post type" log pattern reported for stuck syncs.
		 *
		 * @param array $source     Training source.
		 * @param array $post_types Enabled { slug, name } post types to sync.
		 * @return void
		 */
		private static function start_sync_for_source( array $source, array $post_types ) {

			$slug = $source['slug'] ?? '';
			if ( '' === $slug || empty( $post_types ) ) {
				return;
			}

			// Serialize against a concurrently running tick so we never read/merge state
			// that a tick is simultaneously in the middle of writing. On contention, skip
			// silently - the running tick is already making progress, and this call can be
			// retried (the user re-clicking, or the next form save) without harm.
			if ( ! self::acquire_sync_lock( $slug ) ) {
				return;
			}

			try {

				$existing_job = self::recover_stalled_sync( $slug, self::get_sync_job( $slug ) );
				$existing_post_types = is_array( $existing_job['post_types'] ?? null ) ? $existing_job['post_types'] : array();
				$already_running = in_array( $existing_job['status'] ?? '', array( 'queued', 'running' ), true );

				$post_type_state = array();
				foreach ( $post_types as $post_type ) {

					$pt_slug = $post_type['slug'];

					if ( $already_running && isset( $existing_post_types[ $pt_slug ] ) ) {
						// Already tracked - keep its progress (page/counters/retry state) as-is;
						// only the display name may need refreshing.
						$post_type_state[ $pt_slug ] = $existing_post_types[ $pt_slug ];
						$post_type_state[ $pt_slug ]['name'] = $post_type['name'];
						continue;
					}

					$post_type_state[ $pt_slug ] = array(
						'name'        => $post_type['name'],
						'done'        => false,
						'failed'      => false,
						'page'        => 1,
						'total_pages' => 1,
						'processed'   => 0,
						'inserted'    => 0,
						'skipped'     => 0,
						'deleted'     => 0,
						'retry_count' => 0,
						'error'       => '',
					);
				}

				$now = current_time( 'mysql' );
				self::save_sync_job(
					$slug,
					array(
						'status'     => $already_running ? $existing_job['status'] : 'queued',
						'message'    => '',
						'post_types' => $post_type_state,
						'started_at' => $already_running ? ( $existing_job['started_at'] ?? $now ) : $now,
						'updated_at' => $now,
					)
				);

				// A database sync is now active for this source, so any upload cron event
				// that was only scheduled for a future tick (e.g. left over from a previous
				// source's sync completing) must not be allowed to fire mid-sync - defer it.
				// It is NOT lost: is_any_sync_active() now reports true, so it gets
				// re-scheduled by process_sync_tick()'s finalize step once every source's
				// sync (including this one) has finished. An upload tick that is already
				// executing right now is left alone on purpose - see
				// WPSC_PS_AIT_Controller::upload_file_to_training(), which checks
				// is_any_sync_active() itself before touching the next row rather than being
				// killed mid-upload.
				if ( wp_next_scheduled( 'wpsc_ai_training_upload' ) ) {
					wp_clear_scheduled_hook( 'wpsc_ai_training_upload' );
				}
			} finally {
				self::release_sync_lock( $slug );
			}

			self::reschedule_tick( $slug );
		}

		/**
		 * Cron: process one page of the next pending post type for a source's sync job,
		 * then reschedule itself until every enabled post type has been fully paged
		 * through (or skipped after exhausting retries), at which point the job is
		 * finalized.
		 *
		 * Wrapped in the same per-source lock used by start_sync_for_source() so an
		 * overlapping tick (a duplicate WP-Cron fire, spawn_cron() racing a second
		 * request, etc.) cannot read/save state concurrently with this one.
		 *
		 * @param string $source_slug Training source slug.
		 * @return void
		 */
		public static function run_sync_tick( $source_slug ) {

			if ( ! self::acquire_sync_lock( $source_slug ) ) {
				// Another tick is already in flight for this source - let it finish rather
				// than processing the same state concurrently. Reschedule shortly so the
				// chain does not stall just because this particular tick lost the race.
				if ( ! wp_next_scheduled( 'wpsc_ait_run_sync', array( $source_slug ) ) ) {
					wp_schedule_single_event( time() + 15, 'wpsc_ait_run_sync', array( $source_slug ) );
				}
				return;
			}

			try {
				self::process_sync_tick( $source_slug );
			} finally {
				self::release_sync_lock( $source_slug );
			}
		}

		/**
		 * Lock-held body of run_sync_tick() - see that method for the locking contract.
		 *
		 * @param string $source_slug Training source slug.
		 * @return void
		 */
		private static function process_sync_tick( $source_slug ) {

			$job = self::get_sync_job( $source_slug );
			if ( empty( $job ) || in_array( $job['status'] ?? '', array( 'completed', 'failed' ), true ) ) {
				return;
			}

			$source = WPSC_PS_AIT_Source::get_training_source( $source_slug );
			if ( empty( $source ) ) {
				$job['status']     = 'failed';
				$job['message']    = __( 'Training source no longer exists.', 'wpsc-ps' );
				$job['updated_at'] = current_time( 'mysql' );
				self::save_sync_job( $source_slug, $job );
				return;
			}

			$job['status'] = 'running';

			$current_post_type = '';
			foreach ( $job['post_types'] as $pt_slug => $pt_state ) {
				if ( empty( $pt_state['done'] ) ) {
					$current_post_type = $pt_slug;
					break;
				}
			}

			// All post types processed (successfully or skipped) - finalize the job.
			if ( '' === $current_post_type ) {

				foreach ( array_keys( $job['post_types'] ) as $pt_slug ) {
					delete_transient( self::get_sync_ids_transient_key( $source_slug, $pt_slug ) );
				}

				$job['status']     = 'completed';
				$job['updated_at'] = current_time( 'mysql' );
				self::save_sync_job( $source_slug, $job );

				// Only kick off the upload once every source's database sync has finished -
				// otherwise a source that finishes early would send the AI provider uploads
				// racing against another source's sync that is still inserting/updating rows.
				if ( ! self::is_any_sync_active() && ! wp_next_scheduled( 'wpsc_ai_training_upload' ) ) {
					wp_schedule_single_event( time(), 'wpsc_ai_training_upload' );
				}
				return;
			}

			$pt_state = $job['post_types'][ $current_post_type ];
			$page = max( 1, (int) $pt_state['page'] );

			$response = self::fetch_training_posts( $source, $current_post_type, $page );

			if ( is_wp_error( $response ) ) {

				$error_data  = $response->get_error_data();
				$http_status = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 0 ) : 0;
				$severity    = self::classify_fetch_error( $response, $http_status );
				$retry_count = (int) ( $pt_state['retry_count'] ?? 0 );

				if ( 'recoverable' === $severity && $retry_count < self::MAX_FETCH_RETRIES ) {

					$pt_state['retry_count'] = $retry_count + 1;
					$pt_state['error']       = $response->get_error_message();

					$job['post_types'][ $current_post_type ] = $pt_state;
					$job['updated_at'] = current_time( 'mysql' );
					self::save_sync_job( $source_slug, $job );

					self::reschedule_tick( $source_slug );
					return;
				}

				// Permanent error, or recoverable retries exhausted - skip only this post
				// type. The rest of the queue (other post types, other training sources)
				// must keep processing regardless of this one's outcome.
				$pt_state['done']   = true;
				$pt_state['failed'] = true;
				$pt_state['error']  = $response->get_error_message();
				delete_transient( self::get_sync_ids_transient_key( $source_slug, $current_post_type ) );

				$job['post_types'][ $current_post_type ] = $pt_state;
				$job['updated_at'] = current_time( 'mysql' );
				self::save_sync_job( $source_slug, $job );

				self::reschedule_tick( $source_slug );
				return;
			}

			// Successful fetch - clear any retry state accumulated for this page.
			$pt_state['retry_count'] = 0;
			$pt_state['error']       = '';

			// Track every remote post id seen this run so stale local records can be
			// detected once this post type has been fully paged through.
			$transient_key = self::get_sync_ids_transient_key( $source_slug, $current_post_type );
			$fetched_ids = 1 === $page ? array() : get_transient( $transient_key );
			$fetched_ids = is_array( $fetched_ids ) ? $fetched_ids : array();
			foreach ( (array) ( $response['posts'] ?? array() ) as $post ) {
				$remote_id = absint( $post['id'] ?? 0 );
				if ( $remote_id ) {
					$fetched_ids[] = $remote_id;
				}
			}
			set_transient( $transient_key, $fetched_ids, self::SYNC_IDS_TRANSIENT_EXPIRY );

			$result = self::process_training_posts( $source, $response, $current_post_type );

			$pt_state['processed'] += $result['processed'];
			$pt_state['inserted']  += $result['inserted'];
			$pt_state['skipped']   += $result['skipped'];

			// Do not blindly trust X-WP-TotalPages: some sources omit it or have it
			// stripped by a proxy/cache. When it's missing/invalid, infer end-of-data from
			// whether this page came back full (a short/empty page means there is no more
			// data) instead of defaulting to "1 page" and stopping prematurely.
			$batch_size = self::IMPORT_POSTS_PER_REQUEST;
			if ( ! empty( $response['has_reliable_total'] ) ) {
				$total_pages  = max( (int) $response['total_pages'], $page );
				$is_last_page = $page >= $total_pages;
			} else {
				$is_last_page = $result['processed'] < $batch_size;
				$total_pages  = $is_last_page ? $page : ( $page + 1 );
			}

			$pt_state['total_pages'] = $total_pages;

			if ( $is_last_page ) {

				// Last page for this post type - anything local not seen in this run no longer exists at the source.
				$pt_state['deleted'] = self::delete_stale_training_posts( $source, $current_post_type, $fetched_ids );
				delete_transient( $transient_key );
				$pt_state['done'] = true;
			} elseif ( $page >= self::MAX_PAGES_PER_POST_TYPE ) {

				// Last-resort safeguard: a misbehaving endpoint that always returns a full
				// page could otherwise page forever since end-of-data is never detected.
				$pt_state['done']   = true;
				$pt_state['failed'] = true;
				$pt_state['error']  = __( 'Reached maximum page limit for this post type; stopped to avoid an endless sync.', 'wpsc-ps' );
				delete_transient( $transient_key );
			} else {
				$pt_state['page'] = $page + 1;
			}

			$job['post_types'][ $current_post_type ] = $pt_state;
			$job['updated_at'] = current_time( 'mysql' );
			self::save_sync_job( $source_slug, $job );

			// More work remains (at least the finalize pass) - keep the chain going.
			self::reschedule_tick( $source_slug );
		}

		/**
		 * Classify a fetch_training_posts() failure as 'recoverable' (worth a limited
		 * number of retries - network blips, timeouts, rate limiting, transient server
		 * errors) or 'permanent' (retrying will not help - invalid endpoint, malformed
		 * response, auth/not-found errors).
		 *
		 * @param WP_Error $error       The error returned by fetch_training_posts().
		 * @param int      $http_status HTTP status code, if the failure was an HTTP response (0 otherwise).
		 * @return string 'recoverable' or 'permanent'.
		 */
		private static function classify_fetch_error( WP_Error $error, $http_status ) {

			$code = $error->get_error_code();

			if ( in_array( $code, array( 'wpsc_invalid_endpoint', 'wpsc_invalid_json' ), true ) ) {
				return 'permanent';
			}

			if ( 'wpsc_http_error' === $code ) {
				return in_array( $http_status, array( 401, 403, 404, 410 ), true ) ? 'permanent' : 'recoverable';
			}

			// wp_remote_get() itself failed (DNS, connection refused, TLS, timeout, etc.) -
			// always a transient/environmental condition, worth retrying.
			return 'recoverable';
		}

		/**
		 * Ensure a cron tick is scheduled for a source and nudge WP-Cron to run it soon,
		 * without creating a duplicate event if one is already pending.
		 *
		 * @param string $source_slug Training source slug.
		 * @return void
		 */
		private static function reschedule_tick( $source_slug ) {

			if ( ! wp_next_scheduled( 'wpsc_ait_run_sync', array( $source_slug ) ) ) {
				wp_schedule_single_event( time(), 'wpsc_ait_run_sync', array( $source_slug ) );
			}

			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}

		/**
		 * Acquire the per-source sync lock, reclaiming it first if it has gone stale (the
		 * previous holder crashed/timed out mid-tick without releasing it).
		 *
		 * Add_option() is used deliberately: MySQL enforces uniqueness on option_name, so
		 * the initial acquisition is atomic without requiring any table/schema change.
		 *
		 * @param string $source_slug Training source slug.
		 * @return bool True if the lock was acquired.
		 */
		private static function acquire_sync_lock( $source_slug ) {

			$lock_key = self::get_sync_lock_key( $source_slug );

			if ( add_option( $lock_key, time(), '', 'no' ) ) {
				return true;
			}

			$locked_at = (int) get_option( $lock_key, 0 );
			if ( $locked_at > 0 && ( time() - $locked_at ) < self::SYNC_LOCK_TIMEOUT ) {
				return false;
			}

			// Stale lock (or unreadable) - reclaim it.
			delete_option( $lock_key );
			return add_option( $lock_key, time(), '', 'no' );
		}

		/**
		 * Release the per-source sync lock.
		 *
		 * @param string $source_slug Training source slug.
		 * @return void
		 */
		private static function release_sync_lock( $source_slug ) {
			delete_option( self::get_sync_lock_key( $source_slug ) );
		}

		/**
		 * Build the option name used to lock a source's sync job against concurrent ticks.
		 *
		 * @param string $source_slug Training source slug.
		 * @return string
		 */
		private static function get_sync_lock_key( $source_slug ) {
			return 'wpsc_ait_sync_lock_' . md5( $source_slug );
		}

		/**
		 * Resume or give up on every source's stalled sync job (see recover_stalled_sync()),
		 * independent of whether an admin has the settings screen open to poll it. Hooked
		 * into the existing 15-minute stale-processing cron (WPSC_PS_AIT_Cron) so a lost
		 * cron tick is not left waiting on a page load that may never come.
		 *
		 * @return void
		 */
		public static function recover_all_stalled_syncs() {

			$progress = get_option( self::SYNC_PROGRESS_OPTION, array() );
			if ( ! is_array( $progress ) || empty( $progress ) ) {
				return;
			}

			foreach ( $progress as $source_slug => $job ) {
				if ( is_array( $job ) ) {
					self::recover_stalled_sync( (string) $source_slug, $job );
				}
			}
		}

		/**
		 * AJAX: Report the current sync progress for a training source, for the
		 * progress bar to poll.
		 *
		 * Post types are synced one at a time, in order - the bar reflects just the
		 * post type currently being paged through (0-100%, resetting for each one).
		 * A small post type each with its own already-done/processing/pending status
		 * is also returned, so the client can show a full breakdown - small post types
		 * can finish within a single poll interval, and without the breakdown it can
		 * look like they were skipped rather than having completed almost instantly.
		 *
		 * @return void
		 */
		public static function get_ait_sync_progress() {

			if ( check_ajax_referer( 'wpsc_get_ait_sync_progress', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request', 401 );
			}

			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$source_slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
			$job = self::get_sync_job( $source_slug );

			if ( empty( $job ) ) {
				wp_send_json_success( array( 'status' => 'idle' ) );
			}

			// A tick can die without ever writing its state back (fatal error, request
			// timeout, cron never firing), leaving the job stuck as queued/running. Resume
			// it, or give up on it, instead of letting the client poll it forever.
			$job = self::recover_stalled_sync( $source_slug, $job );

			$post_types = is_array( $job['post_types'] ?? null ) ? $job['post_types'] : array();
			$total_types = count( $post_types );
			$deleted = 0;
			$percent = 0;
			$label = '';
			$type_index = 0;
			$found_current = false;
			$post_types_breakdown = array();

			foreach ( $post_types as $pt_state ) {

				++$type_index;
				$deleted += (int) ( $pt_state['deleted'] ?? 0 );

				$total_pages = max( 1, (int) ( $pt_state['total_pages'] ?? 1 ) );
				$page = (int) ( $pt_state['page'] ?? 1 );
				$is_done = ! empty( $pt_state['done'] );

				$post_types_breakdown[] = array(
					'name'        => $pt_state['name'] ?? '',
					'status'      => $is_done ? 'done' : ( $found_current ? 'pending' : 'processing' ),
					'page'        => $page,
					'total_pages' => $total_pages,
				);

				// The first not-done post type is the one currently being paged through.
				if ( $is_done || $found_current ) {
					continue;
				}

				$found_current = true;
				$percent = (int) round( min( 100, ( $page / $total_pages ) * 100 ) );
				$label = sprintf(
					/* translators: 1: post type name, 2: post type position in the queue, 3: total post types being synced, 4: current page, 5: total pages */
					__( '%1$s (%2$d of %3$d post types) – page %4$d of %5$d', 'wpsc-ps' ),
					$pt_state['name'] ?? '',
					$type_index,
					$total_types,
					$page,
					$total_pages
				);
			}

			$status = $job['status'] ?? 'idle';

			if ( 'completed' === $status ) {
				$percent = 100;
				$label = __( 'Completed', 'wpsc-ps' );
			}

			wp_send_json_success(
				array(
					'status'     => $status,
					'percent'    => $percent,
					'label'      => $label,
					'deleted'    => $deleted,
					'message'    => $job['message'] ?? '',
					'post_types' => $post_types_breakdown,
				)
			);
		}

		/**
		 * Whether a background sync is genuinely still in flight for a source.
		 *
		 * A job that has stopped progressing for good (see SYNC_STALL_GIVE_UP) reports
		 * as not running, so the edit screen does not disable its buttons and start
		 * polling a sync that is never going to finish.
		 *
		 * @param string $source_slug Training source slug.
		 * @return bool
		 */
		public static function is_sync_running( $source_slug ) {
			return self::job_is_active( self::get_sync_job( $source_slug ) );
		}

		/**
		 * Whether a background sync is genuinely still in flight for ANY training source.
		 *
		 * This is the gate the AI provider upload cron (WPSC_PS_AIT_Controller::upload_file_to_training())
		 * and the finalize step of process_sync_tick() both check before letting an upload
		 * run, so database synchronization always finishes - across every source, not just
		 * the one that just completed - before any record is sent to the AI provider.
		 *
		 * @return bool
		 */
		public static function is_any_sync_active() {

			$progress = get_option( self::SYNC_PROGRESS_OPTION, array() );
			if ( ! is_array( $progress ) ) {
				return false;
			}

			foreach ( $progress as $job ) {
				if ( is_array( $job ) && self::job_is_active( $job ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Whether a single sync job's state counts as "still in flight" - queued/running and
		 * not yet given up on (see SYNC_STALL_GIVE_UP). Shared by is_sync_running() (one
		 * source) and is_any_sync_active() (every source).
		 *
		 * @param array $job Job state.
		 * @return bool
		 */
		private static function job_is_active( array $job ) {

			if ( ! in_array( $job['status'] ?? '', array( 'queued', 'running' ), true ) ) {
				return false;
			}

			$stalled_for = self::get_sync_stalled_duration( $job );

			return null === $stalled_for || $stalled_for < self::SYNC_STALL_GIVE_UP;
		}

		/**
		 * How long a queued/running job has gone without progressing.
		 *
		 * @param array $job Job state.
		 * @return int|null Seconds since the job last progressed, or null if unknown.
		 */
		private static function get_sync_stalled_duration( array $job ) {

			$updated_at = (string) ( $job['updated_at'] ?? '' );
			if ( '' === $updated_at ) {
				return null;
			}

			// updated_at is stored in site-local time - compare it in GMT.
			$updated_gmt = strtotime( get_gmt_from_date( $updated_at ) . ' +00:00' );

			return $updated_gmt ? max( 0, time() - $updated_gmt ) : null;
		}

		/**
		 * Resume - or give up on - a sync job whose cron tick never completed.
		 *
		 * Ticks are self-chaining, so a single lost tick (fatal error, request timeout,
		 * cron not running) breaks the chain and leaves the job queued/running forever.
		 * Once a job has not progressed for SYNC_STALL_TIMEOUT and has no tick pending,
		 * schedule one; if it still has not progressed by SYNC_STALL_GIVE_UP, mark it
		 * failed so the UI reports it and unlocks.
		 *
		 * @param string $source_slug Training source slug.
		 * @param array  $job         Job state.
		 * @return array Job state, updated if it was given up on.
		 */
		private static function recover_stalled_sync( $source_slug, array $job ) {

			if ( ! in_array( $job['status'] ?? '', array( 'queued', 'running' ), true ) ) {
				return $job;
			}

			$stalled_for = self::get_sync_stalled_duration( $job );
			if ( null === $stalled_for || $stalled_for < self::SYNC_STALL_TIMEOUT ) {
				return $job;
			}

			if ( $stalled_for >= self::SYNC_STALL_GIVE_UP ) {

				wp_clear_scheduled_hook( 'wpsc_ait_run_sync', array( $source_slug ) );

				$job['status']     = 'failed';
				$job['message']    = __( 'Sync stopped unexpectedly and could not be resumed. Please try again.', 'wpsc-ps' );
				$job['updated_at'] = current_time( 'mysql' );
				self::save_sync_job( $source_slug, $job );

				return $job;
			}

			// Tick still pending - give it time rather than queueing a duplicate.
			if ( wp_next_scheduled( 'wpsc_ait_run_sync', array( $source_slug ) ) ) {
				return $job;
			}

			wp_schedule_single_event( time(), 'wpsc_ait_run_sync', array( $source_slug ) );

			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}

			return $job;
		}

		/**
		 * Get the sync job state for a source slug.
		 *
		 * @param string $source_slug Training source slug.
		 * @return array Job state, or an empty array if none exists.
		 */
		private static function get_sync_job( $source_slug ) {

			if ( '' === $source_slug ) {
				return array();
			}

			$progress = get_option( self::SYNC_PROGRESS_OPTION, array() );
			$progress = is_array( $progress ) ? $progress : array();

			return isset( $progress[ $source_slug ] ) && is_array( $progress[ $source_slug ] ) ? $progress[ $source_slug ] : array();
		}

		/**
		 * Save the sync job state for a source slug.
		 *
		 * @param string $source_slug Training source slug.
		 * @param array  $job         Job state.
		 * @return void
		 */
		private static function save_sync_job( $source_slug, array $job ) {

			$progress = get_option( self::SYNC_PROGRESS_OPTION, array() );
			$progress = is_array( $progress ) ? $progress : array();

			$progress[ $source_slug ] = $job;
			update_option( self::SYNC_PROGRESS_OPTION, $progress );
		}

		/**
		 * Remove a source's sync job state entirely (e.g. once its source is deleted).
		 *
		 * @param string $source_slug Training source slug.
		 * @return void
		 */
		private static function clear_sync_progress( $source_slug ) {

			$progress = get_option( self::SYNC_PROGRESS_OPTION, array() );
			$progress = is_array( $progress ) ? $progress : array();

			if ( isset( $progress[ $source_slug ] ) ) {
				unset( $progress[ $source_slug ] );
				update_option( self::SYNC_PROGRESS_OPTION, $progress );
			}
		}

		/**
		 * Delete synced training data for post types that were enabled before an update
		 * but are no longer enabled afterwards.
		 *
		 * @param array  $previous_post_types Post types before the update.
		 * @param array  $current_post_types  Post types after the update.
		 * @param string $source_slug         Source slug, used to scope the deletion.
		 * @return void
		 */
		private static function delete_training_data_for_disabled_post_types( array $previous_post_types, array $current_post_types, $source_slug ) {

			$previously_enabled = array();
			foreach ( $previous_post_types as $post_type ) {
				if ( is_array( $post_type ) && ! empty( $post_type['status'] ) && ! empty( $post_type['slug'] ) ) {
					$previously_enabled[ sanitize_key( $post_type['slug'] ) ] = true;
				}
			}

			if ( empty( $previously_enabled ) ) {
				return;
			}

			$currently_enabled = array();
			foreach ( $current_post_types as $post_type ) {
				if ( is_array( $post_type ) && ! empty( $post_type['status'] ) && ! empty( $post_type['slug'] ) ) {
					$currently_enabled[ sanitize_key( $post_type['slug'] ) ] = true;
				}
			}

			foreach ( array_keys( $previously_enabled ) as $slug ) {
				if ( ! isset( $currently_enabled[ $slug ] ) ) {
					WPSC_PS_AIT_Controller::delete_all_training_data_by_source( $slug, $source_slug );
				}
			}
		}

		/**
		 * Get RAG types from the given endpoint.
		 *
		 * @param string $endpoint The REST API endpoint URL.
		 * @return array The filtered RAG types.
		 */
		private static function get_rag_types( $endpoint ) {

			if ( ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
				return array(
					'success' => false,
					'error'   => 'Invalid endpoint URL.',
				);
			}

			// Normalize input so both site URL and /wp-json URL are supported.
			$site_endpoint = untrailingslashit( esc_url_raw( $endpoint ) );
			$site_endpoint = preg_replace( '#(?:/wp-json)+/?$#i', '', $site_endpoint );

			$wp_check = self::is_wordpress_site( $site_endpoint );
			if ( empty( $wp_check['success'] ) ) {
				return $wp_check;
			}

			$body = isset( $wp_check['body'] ) && is_array( $wp_check['body'] ) ? $wp_check['body'] : array();

			// Fetch the post types from the REST API.
			$posts_endpoint = trailingslashit( $site_endpoint ) . 'wp-json/wp/v2/types';
			$posts_endpoint = add_query_arg( array(), $posts_endpoint );

			$response = wp_remote_get(
				$posts_endpoint,
				array(
					'timeout' => 20,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'error'   => 'Failed to connect to the post types endpoint.',
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				return array(
					'success' => false,
					'error'   => 'Post types endpoint returned an error: ' . $status_code,
				);
			}

			$types = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return array(
					'success' => false,
					'error'   => 'Invalid JSON response from post types endpoint.',
				);
			}

			// Filter and prepare the post types for RAG training.
			$rag_types = array();
			$skipped_types = array();
			$supported_types = array();

			// Define infrastructure/internal post types that should be skipped.
			$infra_types = array(
				'revision',
				'custom_css',
				'customize_changeset',
				'oembed_cache',
				'user_request',
				'wp_block',
				'wp_template',
				'wp_template_part',
				'wp_navigation',
				'wp_font_family',
				'wp_font_face',
				'wp_global_styles',
				'attachment',
				'nav_menu_item',
			);

			// Loop through the post types and filter them based on the criteria.
			foreach ( $types as $slug => $type ) {

				$rest_base = ! empty( $type['rest_base'] ) ? $type['rest_base'] : $slug;
				$route = '/wp/v2/' . $rest_base;
				$skip_reason = '';

				// Skip infra/internal types that are not useful for AI training.
				if ( in_array( $slug, $infra_types, true ) || 0 === strpos( $slug, 'wp_' ) ) {
					$skip_reason = 'Infrastructure/internal post type';
				}

				// Ensure a discoverable route exists in the API index.
				if ( '' === $skip_reason ) {
					if ( ! isset( $body['routes'][ $route ] ) && ! isset( $body['routes'][ untrailingslashit( $route ) ] ) && ! isset( $body['routes'][ trailingslashit( $route ) ] ) ) {
						$skip_reason = 'Route not present in REST index';
					}
				}

				// Probe one published item to verify data availability.
				if ( '' === $skip_reason ) {
					$probe_url = add_query_arg(
						array(
							'per_page' => 1,
							'status'   => 'publish',
						),
						trailingslashit( $site_endpoint ) . 'wp-json/wp/v2/' . $rest_base
					);

					$response = wp_remote_get(
						$probe_url,
						array(
							'timeout' => 20,
						)
					);

					if ( is_wp_error( $response ) ) {
						$skip_reason = 'Probe request failed: ' . $response->get_error_message();
					} else {
						$status_code = wp_remote_retrieve_response_code( $response );
						if ( 200 !== $status_code ) {
							$skip_reason = 'Probe returned status ' . $status_code;
						} else {
							$items = json_decode( wp_remote_retrieve_body( $response ), true );
							if ( JSON_ERROR_NONE !== json_last_error() ) {
								$skip_reason = 'Probe response JSON is invalid';
							} elseif ( ! is_array( $items ) || empty( $items ) ) {
								$skip_reason = 'No published items found';
							} else {
								$item = reset( $items );
								if ( ! is_array( $item ) ) {
									$skip_reason = 'Probe payload is not a valid item object';
								} else {
									$text_chunks = array();
									$collect_text = function ( $value ) use ( &$collect_text, &$text_chunks ) {
										if ( is_string( $value ) ) {
											$clean = trim( wp_strip_all_tags( $value ) );
											if ( '' !== $clean ) {
												$text_chunks[] = $clean;
											}
											return;
										}

										if ( is_array( $value ) ) {
											foreach ( $value as $sub_value ) {
												$collect_text( $sub_value );
											}
										}
									};

									$collect_text( $item );
									$text_chunks = array_unique( $text_chunks );

									$total_chars = 0;
									$max_chunk = 0;
									$meaningful_chunks = 0;
									foreach ( $text_chunks as $chunk ) {
										$length = strlen( $chunk );
										$total_chars += $length;
										if ( $length > $max_chunk ) {
											$max_chunk = $length;
										}
										if ( $length >= 20 ) {
											++$meaningful_chunks;
										}
									}

									if ( $total_chars < 80 && $max_chunk < 40 && 0 === $meaningful_chunks ) {
										$skip_reason = 'Insufficient textual content in rendered/string fields';
									}
								}
							}
						}
					}
				}

				if ( '' !== $skip_reason ) {
					$skipped_types[] = array(
						'slug'      => $slug,
						'rest_base' => $rest_base,
						'reason'    => $skip_reason,
					);
					continue;
				}

				$rag_type = array(
					'slug'      => $slug,
					'name'      => isset( $type['name'] ) ? $type['name'] : $slug,
					'rest_base' => $rest_base,
					'endpoint'  => trailingslashit( $site_endpoint ) . 'wp-json/wp/v2/' . $rest_base,
				);

				$rag_types[] = $rag_type;
				$supported_types[] = $rag_type;
			}
			return array(
				'success' => true,
				'data'    => $rag_types,
			);
		}

		/**
		 * Check whether the provided endpoint belongs to a WordPress site.
		 *
		 * @param string $site_endpoint Normalized site URL without trailing /wp-json.
		 * @return array Validation result and decoded REST index body on success.
		 */
		private static function is_wordpress_site( $site_endpoint ) {

			$rest_endpoint = trailingslashit( $site_endpoint ) . 'wp-json';
			$not_wordpress_message = __( 'The provided URL does not appear to be a WordPress website.', 'wpsc-ps' );
			$rest_blocked_message = __( 'Please make sure if this is a WordPress site, its REST API is not blocked or requires authentication.', 'wpsc-ps' );
			$response = wp_remote_get(
				$rest_endpoint,
				array(
					'timeout'     => 20,
					'redirection' => 5,
					'headers'     => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Could not connect to the endpoint.', 'wpsc-ps' ),
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				if ( in_array( (int) $status_code, array( 401, 403 ), true ) ) {
					return array(
						'success' => false,
						'error'   => $rest_blocked_message,
					);
				}

				if ( 404 === (int) $status_code ) {
					return array(
						'success' => false,
						'error'   => __( 'REST API endpoint was not found at this URL. If this is a WordPress site, make sure permalinks are enabled and /wp-json is accessible.', 'wpsc-ps' ),
					);
				}

				return array(
					'success' => false,
					'error'   => sprintf(
					/* translators: %d: HTTP response status code. */
						__( 'Could not validate WordPress REST API (HTTP %d).', 'wpsc-ps' ),
						$status_code
					),
				);
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				return array(
					'success' => false,
					'error'   => __( 'This website may be WordPress, but /wp-json did not return a valid REST response (it may be blocked by a firewall, redirect, or plugin).', 'wpsc-ps' ),
				);
			}

			$namespaces = isset( $body['namespaces'] ) && is_array( $body['namespaces'] ) ? $body['namespaces'] : array();
			$routes = isset( $body['routes'] ) && is_array( $body['routes'] ) ? $body['routes'] : array();

			if ( ! in_array( 'wp/v2', $namespaces, true ) || empty( $routes['/wp/v2/types'] ) ) {
				return array(
					'success' => false,
					'error'   => $not_wordpress_message,
				);
			}

			return array(
				'success' => true,
				'body'    => $body,
			);
		}

		/**
		 * Render post type checkbox HTML from rag types array.
		 *
		 * @param array $rag_types RAG post types.
		 * @param array $ait_post_types Selected post types.
		 * @return string
		 */
		private static function get_rag_types_html( $rag_types, $ait_post_types ) {

			if ( ! is_array( $rag_types ) || empty( $rag_types ) ) {
				return '';
			}

			$html = '';
			foreach ( $rag_types as $index => $rag_type ) {
				if ( ! is_array( $rag_type ) ) {
					continue;
				}

				$rest_base = isset( $rag_type['rest_base'] ) ? sanitize_key( $rag_type['rest_base'] ) : '';
				if ( '' === $rest_base ) {
					$rest_base = isset( $rag_type['slug'] ) ? sanitize_key( $rag_type['slug'] ) : '';
				}

				if ( '' === $rest_base ) {
					continue;
				}

				// Saved post types can be keyed either by rest_base (checkbox value used when
				// saving) or by the post type's own slug (the installer's local defaults), so
				// match against both to keep previously enabled post types checked.
				$type_slug = isset( $rag_type['slug'] ) ? sanitize_key( $rag_type['slug'] ) : '';
				$is_checked = in_array( $rest_base, $ait_post_types, true ) || ( '' !== $type_slug && in_array( $type_slug, $ait_post_types, true ) );

				$item_name = isset( $rag_type['name'] ) ? sanitize_text_field( $rag_type['name'] ) : $rest_base;
				$input_id = 'wpsc_ait_sync_type_' . sanitize_html_class( $rest_base ) . '_' . (int) $index;

				$html .= '<div class="checkbox-container" style="margin-bottom: 5px;">';
				$html .= '<input id="' . esc_attr( $input_id ) . '" type="checkbox" name="ait-post-types[]" value="' . esc_attr( $rest_base ) . '"' . ( $is_checked ? ' checked' : '' ) . '>';
				$html .= '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $item_name ) . '</label>';
				$html .= '</div>';
			}

			return $html;
		}

		/**
		 * Build the transient key used to accumulate remote post ids seen while paging
		 * through a single post type during a sync run.
		 *
		 * @param string $source_slug Training source slug.
		 * @param string $post_type   Post type slug.
		 * @return string
		 */
		private static function get_sync_ids_transient_key( $source_slug, $post_type ) {
			return 'wpsc_ait_sync_ids_' . md5( $source_slug . '|' . $post_type );
		}

		/**
		 * Delete local training records whose remote post is no longer returned by the source.
		 *
		 * Called once a post type has been fully paged through for this sync run. Any locally
		 * stored record for the same source + post type whose id wasn't among the ids fetched
		 * this run is treated as removed at the source (deleted, unpublished, etc.) and is
		 * safe-deleted the same way manual deletion does.
		 *
		 * @param array  $source      Training source.
		 * @param string $post_type   Post type slug.
		 * @param array  $fetched_ids Remote post ids seen while paging through this post type.
		 * @return int Number of stale records deleted.
		 */
		private static function delete_stale_training_posts( array $source, $post_type, array $fetched_ids ) {

			$source_slug = $source['slug'] ?? '';
			if ( empty( $source_slug ) ) {
				return 0;
			}

			$fetched_ids = array_flip( array_unique( array_map( 'absint', $fetched_ids ) ) );

			$local_training = WPSC_RAG_Training_File::find(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'status',
							'compare' => '!=',
							'val'     => WPSC_PS_AIT_Status::DELETE,
						),
						array(
							'slug'    => 'source',
							'compare' => '=',
							'val'     => $post_type,
						),
						array(
							'slug'    => 'doc_source',
							'compare' => '=',
							'val'     => $source_slug,
						),
					),
				)
			)['results'] ?? array();

			$deleted = 0;
			$flag = false;
			foreach ( $local_training as $training ) {

				$source_id = absint( $training->source_id );
				if ( ! $source_id || isset( $fetched_ids[ $source_id ] ) ) {
					continue;
				}

				if ( WPSC_RAG_Training_File::safe_delete( $training ) ) {
					$flag = true;
					++$deleted;
				}
			}

			if ( $flag && ! wp_next_scheduled( 'wpsc_delete_ai_training_record' ) ) {
				wp_schedule_single_event( time() + 5, 'wpsc_delete_ai_training_record' );
			}

			return $deleted;
		}

		/**
		 * Fetch one page of posts from the WordPress REST API.
		 *
		 * Uses the REST API for both local and remote WordPress websites.
		 *
		 * @param array  $source    Training source.
		 * @param string $post_type Post type slug.
		 * @param int    $page      Page number.
		 * @return array|WP_Error
		 */
		private static function fetch_training_posts( array $source, $post_type, $page ) {

			$post_types = array_column( $source['post-types'], null, 'slug' );
			$endpoint = $post_types[ $post_type ]['endpoint'] ?? '';
			if ( empty( $endpoint ) ) {
				return new WP_Error(
					'wpsc_invalid_endpoint',
					__( 'Training source endpoint is invalid.', 'wpsc-ps' )
				);
			}

			$url = add_query_arg(
				array(
					'page'     => max( 1, absint( $page ) ),
					'per_page' => self::IMPORT_POSTS_PER_REQUEST,
					'_fields'  => 'id,modified',
				),
				$endpoint
			);

			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 30,
					'redirection' => 5,
					'user-agent'  => 'SupportCandy AI Training',
					'headers'     => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status ) {

				return new WP_Error(
					'wpsc_http_error',
					sprintf(
					/* translators: %d: HTTP status code */
						__( 'REST API returned HTTP %d.', 'wpsc-ps' ),
						$status
					),
					array( 'status' => $status )
				);

			}

			$body = wp_remote_retrieve_body( $response );
			$posts = json_decode( $body, true );

			if ( ! is_array( $posts ) ) {
				return new WP_Error(
					'wpsc_invalid_json',
					__( 'Invalid REST API response.', 'wpsc-ps' )
				);

			}

			// X-WP-TotalPages is only trustworthy when present and a valid positive integer -
			// a custom/non-core REST endpoint may omit it entirely, and a proxy or caching
			// layer can strip custom headers. Report whether it was usable rather than
			// silently defaulting to "1 page", which previously caused large post types
			// behind such endpoints to be marked complete after only their first page.
			$total_pages_header = wp_remote_retrieve_header( $response, 'X-WP-TotalPages' );
			$has_reliable_total = is_numeric( $total_pages_header ) && absint( $total_pages_header ) >= 1;

			return array(
				'posts'              => $posts,
				'total_pages'        => $has_reliable_total ? absint( $total_pages_header ) : 0,
				'has_reliable_total' => $has_reliable_total,
			);
		}

		/**
		 * Process one page of posts returned by the REST API.
		 *
		 * @param array  $source        Training source.
		 * @param array  $response_data Response returned by fetch_training_posts().
		 * @param string $post_type     Post type slug.
		 *
		 * @return array
		 */
		private static function process_training_posts( array $source, array $response_data, $post_type ) {

			$posts = isset( $response_data['posts'] ) && is_array( $response_data['posts'] ) ? $response_data['posts'] : array();

			$processed = 0;
			$inserted  = 0;
			$skipped   = 0;

			foreach ( $posts as $post ) {

				++$processed;
				$post_id = absint( $post['id'] ?? 0 );

				if ( ! $post_id ) {
					++$skipped;
					continue;
				}

				$result = self::insert_training_post( $source, $post_type, $post );
				if ( $result ) {
					++$inserted;
				} else {
					++$skipped;
				}
			}

			return array(
				'processed' => $processed,
				'inserted'  => $inserted,
				'skipped'   => $skipped,
			);
		}

		/**
		 * Insert a post into the AI training queue.
		 *
		 * If a record already exists for the same source and source_id,
		 * it will be skipped.
		 *
		 * @param array  $source    Training source.
		 * @param string $post_type Post type slug.
		 * @param array  $post      REST API post object.
		 *
		 * @return bool True if inserted, false if skipped.
		 */
		private static function insert_training_post( array $source, $post_type, array $post ) {

			$post_type = sanitize_key( $post_type );
			$post_id = absint( $post['id'] ?? 0 );
			$doc_source = $source['slug'] ?? '';

			if ( empty( $post_type ) || ! $post_id ) {
				return false;
			}

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			$current_provider = sanitize_text_field( $ai_settings['provider'] ?? '' );

			// Look up any existing (non-deleted) queue record(s) for this exact document
			// under the currently configured provider - identified by doc_source + source +
			// source_id + provider together. Each provider keeps its own independent copy
			// (its own provider_file_id/vector store entry), so a record synced under a
			// different provider is intentionally left out of this lookup: it must never be
			// replaced or deleted just because another provider is now active - see
			// source_has_other_provider_data(), which is what actually detects and surfaces
			// "this document has no copy yet for the current provider" to the admin.
			$existing = self::get_existing_training_records( $doc_source, $post_type, $post_id, $current_provider );

			if ( ! empty( $existing ) ) {

				$post_modified_raw = isset( $post['modified'] ) ? sanitize_text_field( $post['modified'] ) : '';

				// If a local copy under this same provider is already up to date (same or
				// newer than the incoming post) there is nothing to do - skip to avoid
				// duplicates.
				foreach ( $existing as $record ) {
					if ( ! self::is_training_record_outdated( $record, $post_modified_raw ) ) {
						return false;
					}
				}
			}

			// Modified date from REST API.
			$post_modified = current_time( 'mysql' );
			if ( ! empty( $post['modified'] ) ) {
				try {
					$post_modified = ( new DateTime( $post['modified'] ) )->format( 'Y-m-d H:i:s' );
				} catch ( Exception $e ) {
					$post_modified = current_time( 'mysql' );
				}
			}

			$now = current_time( 'mysql' );
			$result = WPSC_RAG_Training_File::insert(
				array(
					'status'          => 'new',
					'provider'        => $current_provider,
					'source'          => $post_type,
					'source_id'       => $post_id,
					'doc_source'      => $doc_source,
					'name'            => '',
					'file_path'       => '',
					'meta_data'       => '',
					'post_updated_on' => $post_modified,
					'date_updated'    => $now,
					'date_created'    => $now,
				)
			);

			if ( empty( $result ) ) {
				// Insert failed - leave the existing (stale) record, if any, untouched
				// rather than deleting it first and risking losing it for good.
				return false;
			}

			// The post was modified at the source and the refreshed record is now safely
			// in place, so the old copy/copies can be removed. Doing this only after a
			// successful insert means a failed insert never leaves the document without
			// any local record at all.
			if ( ! empty( $existing ) ) {

				$flag = false;
				foreach ( $existing as $record ) {
					if ( WPSC_RAG_Training_File::safe_delete( $record ) ) {
						$flag = true;
					}
				}

				// safe_delete() only marks provider-backed records as DELETE; the
				// provider file itself is removed by this cron.
				if ( $flag && ! wp_next_scheduled( 'wpsc_delete_ai_training_record' ) ) {
					wp_schedule_single_event( time() + 5, 'wpsc_delete_ai_training_record' );
				}
			}

			return true;
		}

		/**
		 * Fetch all existing (non-deleted) training queue records for a post under a given
		 * provider, identified by doc_source + source + source_id + provider together - the
		 * same source_id can exist under the same post type on two different training
		 * sources without being the same document, and the same document can legitimately
		 * have one independent record per AI provider it has been synced to.
		 *
		 * @param string $doc_source Training source slug the post was fetched from.
		 * @param string $post_type  Post type slug.
		 * @param int    $post_id    Source post id.
		 * @param string $provider   AI provider the record must belong to.
		 * @return array Array of training record objects (empty when none).
		 */
		private static function get_existing_training_records( $doc_source, $post_type, $post_id, $provider ) {

			$post_type = sanitize_key( $post_type );
			$post_id = absint( $post_id );
			if ( empty( $post_type ) || ! $post_id ) {
				return array();
			}

			return WPSC_RAG_Training_File::find(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'status',
							'compare' => '!=',
							'val'     => WPSC_PS_AIT_Status::DELETE,
						),
						array(
							'slug'    => 'source',
							'compare' => '=',
							'val'     => $post_type,
						),
						array(
							'slug'    => 'source_id',
							'compare' => '=',
							'val'     => $post_id,
						),
						array(
							'slug'    => 'doc_source',
							'compare' => '=',
							'val'     => $doc_source,
						),
						array(
							'slug'    => 'provider',
							'compare' => '=',
							'val'     => $provider,
						),
					),
				)
			)['results'] ?? array();
		}

		/**
		 * Determine whether a local training record is older than the incoming post.
		 *
		 * The caller (insert_training_post(), via get_existing_training_records()) already
		 * scopes $training to the currently configured provider, so this is purely a
		 * content-staleness check - it never needs to consider provider here. A record
		 * belonging to a different provider is a different, independently-valid copy (see
		 * insert_training_post()) and is simply not part of what gets passed in.
		 *
		 * @param object $training          Existing training record.
		 * @param string $post_modified_raw Incoming post "modified" date string.
		 * @return bool True when the incoming post is newer and the local record
		 *              needs to be refreshed, false otherwise.
		 */
		private static function is_training_record_outdated( $training, $post_modified_raw ) {

			if ( ! $training || '' === $post_modified_raw ) {
				// Without a comparable modification date, treat the local copy as
				// current so we never create duplicate entries.
				return false;
			}

			// Avoid empty() directly on magic properties; it can return true unexpectedly.
			$existing_updated_on = $training->post_updated_on;
			if ( null === $existing_updated_on || '' === $existing_updated_on ) {
				// No known local timestamp - refresh to be safe.
				return true;
			}

			try {
				if ( $existing_updated_on instanceof DateTimeInterface ) {
					$existing_ts = $existing_updated_on->getTimestamp();
				} else {
					$existing_ts = ( new DateTime( (string) $existing_updated_on ) )->getTimestamp();
				}

				$current_ts = ( new DateTime( $post_modified_raw ) )->getTimestamp();
			} catch ( Exception $e ) {
				// Unparseable dates - leave the local copy untouched.
				return false;
			}

			// Outdated only when the incoming post is strictly newer.
			return $current_ts > $existing_ts;
		}
	}
endif;
WPSC_PS_AI_Setting_AI_Training_Actions::init();
