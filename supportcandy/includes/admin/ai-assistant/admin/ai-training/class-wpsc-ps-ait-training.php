<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AIT_Training' ) ) :

	final class WPSC_PS_AIT_Training {

		/**
		 * Initialize the class
		 *
		 * @return void
		 */
		public static function init() {

			add_action( 'wp_ajax_wpsc_add_ai_training_item', array( __CLASS__, 'add_ai_training_item' ) );
			add_action( 'wp_ajax_wpsc_set_add_ai_training_item', array( __CLASS__, 'set_add_ai_training_item' ) );
			add_action( 'wp_ajax_wpsc_get_delete_ai_training_item', array( __CLASS__, 'get_delete_ai_training_item' ) );
			add_action( 'wp_ajax_wpsc_download_ai_training_item', array( __CLASS__, 'download_ai_training_item' ) );
		}

		/**
		 * Add new AI training item
		 *
		 * @return void
		 */
		public static function add_ai_training_item() {

			$title = esc_attr__( 'Add training item', 'wpsc-ps' );
			// Check capability and setting.
			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				ob_start();
				?>
				<div style="margin-top: 15px; color: #ff0000;"><?php esc_html_e( 'Your AI provider is not connected. Please connect AI provider to use AI chatbot', 'wpsc-ps' ); ?></div>
				<?php
				$body = ob_get_clean();
				$response = array(
					'title'  => $title,
					'body'   => $body,
					'footer' => '',
				);
				wp_send_json( $response );
			}
			ob_start();
			?>
			<form action="#" onsubmit="return false;" class="wpsc-frm-add-ai-training-item">
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc_training_item"><?php esc_attr_e( 'File type', 'wpsc-ps' ); ?></label>
					</div>
					<select name="wpsc_training_type" id="wpsc_training_item">
						<option value=""></option>
						<option value="wpsc_ai_file_type"><?php echo esc_attr__( 'PDF/Text file', 'wpsc-ps' ); ?></option>
						<option value="wpsc_ai_url_type"><?php echo esc_attr__( 'URL', 'wpsc-ps' ); ?></option>
					</select>
				</div>
				<div class="wpsc-input-group wpsc_ai_file_visibility" style="display: none;">
					<div class="label-container">
						<label for="wpsc_ai_file"><?php esc_attr_e( 'Upload files (.pdf,.txt only)', 'wpsc-ps' ); ?></label>
					</div>
					<input id="wpsc_ai_file" type="file" name="wpsc_ai_file_input[]" accept=".pdf,.txt" multiple>
				</div>
				<div class="wpsc-input-group wpsc_ai_url_visibility" style="display: none;">
					<div class="label-container">
						<label for="wpsc_ai_url"><?php esc_attr_e( 'Enter URLs (one per line)', 'wpsc-ps' ); ?></label>
					</div>
					<textarea id="wpsc_ai_url" name="wpsc_ai_url_input" placeholder="<?php esc_attr_e( 'Enter URL', 'wpsc-ps' ); ?>" rows="4"></textarea>
				</div>
				<input type="hidden" name="action" value="wpsc_set_add_ai_training_item">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_add_ai_training_item' ) ); ?>">
			</form>
			<script>
				jQuery(function () {

					// Cache selectors
					const trainingType   = jQuery('select[name="wpsc_training_type"]');
					const fileWrap       = jQuery('.wpsc_ai_file_visibility');
					const urlWrap        = jQuery('.wpsc_ai_url_visibility');
					const fileInput      = jQuery('#wpsc_ai_file');
					const urlInput       = jQuery('#wpsc_ai_url');

					// Handle training type change
					function toggleTrainingType() {
						const selected = trainingType.val();

						const isFile = selected === 'wpsc_ai_file_type';
						const isURL  = selected === 'wpsc_ai_url_type';

						fileWrap.toggle(isFile);
						urlWrap.toggle(isURL);

						fileInput.prop('disabled', !isFile);
						urlInput.prop('disabled', !isURL);
					}

					// Bind events
					trainingType.on('change', toggleTrainingType);

					// Initial state
					toggleTrainingType();
				});
			</script>
			<?php
			$body = ob_get_clean();

			ob_start();
			?>
			<button class="wpsc-button small primary" onclick="wpsc_set_add_ai_training_item(this);">
				<?php esc_attr_e( 'Submit', 'wpsc-ps' ); ?>
			</button>
			<button class="wpsc-button small secondary" onclick="wpsc_close_modal();">
				<?php esc_attr_e( 'Cancel', 'wpsc-ps' ); ?>
			</button>
			<?php
			do_action( 'wpsc_get_edit_ai_training_item_footer' );
			$footer = ob_get_clean();

			$response = array(
				'title'  => $title,
				'body'   => $body,
				'footer' => $footer,
			);
			wp_send_json( $response );
		}

		/**
		 * Set AI training item
		 *
		 * @return void
		 */
		public static function set_add_ai_training_item() {

			// Verify nonce.
			if ( check_ajax_referer( 'wpsc_set_add_ai_training_item', '_ajax_nonce', false ) !== 1 ) {
				wp_send_json_error( __( 'Unauthorized request.', 'wpsc-ps' ), 401 );
			}

			// Check capability and setting.
			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized request.', 'wpsc-ps' ), 401 );
			}

			// Get selected training type.
			$wpsc_training_type = isset( $_POST['wpsc_training_type'] ) ? sanitize_text_field( wp_unslash( $_POST['wpsc_training_type'] ) ) : '';
			if ( empty( $wpsc_training_type ) ) {
				wp_send_json_error( __( 'Training type is required.', 'wpsc-ps' ), 400 );
			}

			if ( ! in_array( $wpsc_training_type, array( 'wpsc_ai_file_type', 'wpsc_ai_url_type' ), true ) ) {
				wp_send_json_error( __( 'Invalid training type selected.', 'wpsc-ps' ), 400 );
			}

			// Get settings.
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );

			// Set common data for both file and URL training types.
			$data = array(
				'status'          => 'new',
				'source_id'       => '0',
				'provider'        => $ai_settings['provider'],
				'doc_source'      => '',
				'name'            => '',
				'file_path'       => '',
				'post_updated_on' => ( new DateTime() )->format( 'Y-m-d H:i:s' ),
				'date_created'    => ( new DateTime() )->format( 'Y-m-d H:i:s' ),
				'date_updated'    => ( new DateTime() )->format( 'Y-m-d H:i:s' ),
			);

			$flag = false;
			switch ( $wpsc_training_type ) {

				case 'wpsc_ai_file_type':
					// Validate file input existence.
					$wpsc_ai_file_input = isset( $_FILES['wpsc_ai_file_input'] )
						? $_FILES['wpsc_ai_file_input'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						: array();

					if ( empty( $wpsc_ai_file_input ) ) {
						wp_send_json_error( __( 'No files received.', 'wpsc-ps' ), 400 );
					}

					// Call file upload processor. Validate files, move to uploads dir and get file details like path, name and other meta data for training.
					$files_results = WPSC_PS_AI_Functions::wpsc_validate_ai_file_uploads( $wpsc_ai_file_input, $ai_settings );

					if ( is_wp_error( $files_results ) ) {
						wp_send_json_error( $files_results->get_error_message(), 400 );
					}

					foreach ( $files_results as $file ) {

						if ( empty( $file['file'] ) ) {
							continue;
						}
						// Set source, name, file_path and meta data for file.
						// doc_source is left empty - file uploads aren't tied to a training source.
						$data['source'] = 'file';
						$data['name'] = $file['name'];
						$data['file_path'] = $file['file'];
						$data['meta_data'] = $file['meta_data'];

						// Insert training file record for file.
						$result = WPSC_RAG_Training_File::insert( $data );
						if ( $result ) {
							$flag = true;
						}
					}
					break;

				case 'wpsc_ai_url_type':
					// Get and sanitize URLs.
					$wpsc_ai_url_input = isset( $_POST['wpsc_ai_url_input'] )
						? sanitize_textarea_field( wp_unslash( $_POST['wpsc_ai_url_input'] ) )
						: '';

					// Remove empty lines and trim spaces. Return as array.
					$wpsc_ai_url_input = array_filter(
						array_map( 'trim', explode( "\n", $wpsc_ai_url_input ) ),
						function ( $line ) {
							return $line !== '';
						}
					);

					// Error if no URL provided.
					if ( empty( $wpsc_ai_url_input ) ) {
						wp_send_json_error( __( 'Please enter at least one URL.', 'wpsc-ps' ), 400 );
					}

					foreach ( $wpsc_ai_url_input as $url ) {

						if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
							continue;
						}

						// Check duplicate entry for URL.
						$check_duplicate = WPSC_RAG_Training_File::count(
							array(
								'meta_query' => array(
									'relation' => 'AND',
									array(
										'slug'    => 'source',
										'compare' => '=',
										'val'     => WPSC_PS_AIT_Source::URL,
									),
									array(
										'slug'    => 'status',
										'compare' => '=',
										'val'     => WPSC_PS_AIT_Status::INDEXED,
									),
									array(
										'slug'    => 'provider_file_id',
										'compare' => 'IS NOT',
										'val'     => 'NULL',
									),
									array(
										'slug'    => 'custom_query',
										'compare' => '=',
										'val'     => "(
											meta_data IS NOT NULL
											AND JSON_VALID(meta_data) = 1
											AND JSON_CONTAINS_PATH(meta_data, 'one', '$.url') = 1
											AND JSON_UNQUOTE(JSON_EXTRACT(meta_data, '$.url')) = '" . esc_sql( $url ) . "'
										)",
									),
								),
							)
						);

						// If duplicate entry exists then skip the URL and continue with next one.
						if ( $check_duplicate ) {
							continue;
						}

						// Set source and meta data for URL.
						$data['source'] = 'url';
						$data['meta_data'] = wp_json_encode( array( 'url' => $url ) );

						// Insert training file record for URL.
						$result = WPSC_RAG_Training_File::insert( $data );
						if ( $result ) {
							$flag = true;
						}
					}
					break;

				default:
					wp_send_json_error( __( 'Invalid training type selected.', 'wpsc-ps' ), 400 );
			}

			if ( $flag && ! wp_next_scheduled( 'wpsc_ai_training_upload' ) ) {
				wp_schedule_single_event( time(), 'wpsc_ai_training_upload' );
			}
		}

		/**
		 * Delete an AI training item
		 *
		 * @return void
		 */
		public static function get_delete_ai_training_item() {

			if ( check_ajax_referer( 'wpsc_get_delete_ai_training_item', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( __( 'Unauthorized request.', 'wpsc-ps' ), 401 );
			}

			// Check capability and setting.
			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized request.', 'wpsc-ps' ), 401 );
			}

			$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
			if ( ! $id ) {
				wp_send_json_error( __( 'Bad Request', 'wpsc-ps' ), 400 );
			}

			$training_item = new WPSC_RAG_Training_File( $id );
			if ( ! $training_item->id ) {
				wp_send_json_error( __( 'Bad Request', 'wpsc-ps' ), 400 );
			}

			$result = WPSC_RAG_Training_File::safe_delete( $training_item );
			if ( ! $result ) {
				wp_send_json_error( __( 'Failed to delete AI training item.', 'wpsc-ps' ), 500 );
			}

			// AI training delete scheduler.
			if ( isset( $result ) && $result && ! wp_next_scheduled( 'wpsc_delete_ai_training_record' ) ) {
				wp_schedule_single_event( time(), 'wpsc_delete_ai_training_record' );
			}
			wp_die();
		}

		/**
		 * Download AI training item
		 *
		 * @return void
		 */
		public static function download_ai_training_item() {

			if ( check_ajax_referer( 'wpsc_download_ai_training_item', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( __( 'Unauthorized request.', 'wpsc-ps' ), 401 );
			}

			/* phpcs:ignore Squiz.PHP.CommentedOutCode.Found
			if ( $training->status === WPSC_PS_AIT_Status::INDEXED && ! empty( (string) $training->provider_file_id ) ) {
				$upload_dir = wp_upload_dir();
				$file_path  = $upload_dir['basedir'] . $training->file_path;
				if ( file_exists( $file_path ) ) {
					$url = add_query_arg(
						array(
							'action'   => 'wpsc_download_ai_training_item',
							'file_id'  => rawurlencode( $training_id ),
							'_wpnonce' => wp_create_nonce( 'wpsc_download_ai_training_item' ),
						),
						admin_url( 'admin-ajax.php' )
					);
					$edit_actions[] = sprintf(
						'<a class="wpsc-link" href="%s" target="_blank">%s</a>',
						esc_url( $url ),
						esc_html__( 'Download', 'wpsc-ps' )
					);
				}
			}
			*/

			// Check capability and setting.
			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized request.', 'wpsc-ps' ), 401 );
			}

			$file_id = isset( $_GET['file_id'] ) ? intval( $_GET['file_id'] ) : 0;
			if ( ! $file_id ) {
				wp_send_json_error( __( 'Bad Request', 'wpsc-ps' ), 400 );
			}

			$training_item = new WPSC_RAG_Training_File( $file_id );
			if ( ! $training_item->id ) {
				wp_send_json_error( __( 'Bad Request', 'wpsc-ps' ), 400 );
			}

			$upload_dir = wp_upload_dir();
			$file_path = $upload_dir['basedir'] . $training_item->file_path;
			if ( ! file_exists( $file_path ) ) {
				wp_send_json_error( __( 'File not found.', 'wpsc-ps' ), 404 );
			}

			$file_name = ! empty( $training_item->name ) ? $training_item->name : basename( $file_path );

			// Security: ensure file exists.
			if ( ! file_exists( $file_path ) ) {
				wp_die( 'File does not exist on server.' );
			}

			// Security: prevent path traversal (extra safety).
			$real_path = realpath( $file_path );
			$upload_dir = wp_upload_dir();
			$base_dir   = realpath( $upload_dir['basedir'] );

			if ( strpos( $real_path, $base_dir ) !== 0 ) {
				wp_die( 'Invalid file path.' );
			}

			// Clean output buffer.
			if ( ob_get_length() ) {
				ob_end_clean();
			}

			// Headers.
			header( 'Content-Description: File Transfer' );
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename="' . basename( $file_name ) . '"' );
			header( 'Content-Transfer-Encoding: binary' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate' );
			header( 'Pragma: public' );
			header( 'Content-Length: ' . filesize( $real_path ) );

			// Output file.
			readfile( $real_path ); //phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			exit();
		}
	}
endif;

WPSC_PS_AIT_Training::init();
