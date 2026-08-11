<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Setting_AI_Training' ) ) :

	final class WPSC_PS_AI_Setting_AI_Training {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Load sections for this screen.
			add_action( 'wp_ajax_wpsc_get_aia_website_setting', array( __CLASS__, 'get_aia_website_setting' ) );
			add_action( 'wp_ajax_wpsc_get_aia_file_upload_setting', array( __CLASS__, 'get_aia_file_upload_setting' ) );

			// File upload training items list.
			add_action( 'wp_ajax_wpsc_get_aia_file_upload_training_list', array( __CLASS__, 'get_aia_file_upload_training_list' ) );
			add_action( 'wp_ajax_wpsc_bulk_delete_training', array( __CLASS__, 'bulk_delete_file_upload_training' ) );

			// Add new.
			add_action( 'wp_ajax_wpsc_add_ai_training_source', array( __CLASS__, 'add_ai_training_source' ) );
			add_action( 'wp_ajax_wpsc_edit_ai_training_source', array( __CLASS__, 'edit_ai_training_source' ) );

			// Manually schedule the upload cron when it's due (records in queue) but not scheduled.
			add_action( 'wp_ajax_wpsc_schedule_ai_training_upload', array( __CLASS__, 'schedule_ai_training_upload' ) );
		}

		/**
		 * Get AI training website setting (training sources list).
		 *
		 * @return void
		 */
		public static function get_aia_website_setting() {

			$sources = get_option( 'wpsc-ps-ai-training-sources', array() );
			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				$sources = array();
			}

			// Record counts are scoped to the currently configured AI provider - see
			// get_source_record_counts().
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			$current_provider = sanitize_text_field( $ai_settings['provider'] ?? '' );
			?>
			<div class="wpsc-dock-container">
				<?php
				printf(
					/* translators: Click here to see the documentation */
					esc_attr__( '%s to see the documentation!', 'supportcandy' ),
					'<a href="https://supportcandy.net/docs/ai-training/" target="_blank">' . esc_attr__( 'Click here', 'supportcandy' ) . '</a>'
				);
				?>
			</div>
			<table class="wpsc-ai-trainings wpsc-setting-tbl">
				<thead>
					<tr>
						<th><?php echo esc_attr( wpsc__( 'Name', 'wpsc-ps' ) ); ?></th>
						<th><?php echo esc_attr( wpsc__( 'Source', 'wpsc-ps' ) ); ?></th>
						<th><?php echo esc_attr( wpsc__( 'Post Types', 'wpsc-ps' ) ); ?></th>
						<th><?php echo esc_attr( wpsc__( 'Upload Status', 'wpsc-ps' ) ); ?></th>
						<th><?php echo esc_attr( wpsc__( 'Actions', 'wpsc-ps' ) ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( ! empty( $sources ) ) {
						foreach ( $sources as $key => $source ) {
							// remove trailing content from api url.
							$api_url = $source['api-url'] ?? '';
							$api_url = preg_replace( '/\/wp-json\/?$/', '', $api_url );
							$record_counts = self::get_source_record_counts( sanitize_text_field( $source['slug'] ?? '' ), $current_provider );
							$upload_status = self::get_source_upload_status( $record_counts );
							?>
							<tr>
								<td><?php echo esc_html( $source['name'] ); ?></td>
								<td><?php echo esc_attr( $api_url ); ?></td>
								<td>
								<?php
									echo esc_html(
										implode(
											', ',
											array_map(
												function ( $post_type ) {
													return isset( $post_type['name'] ) ? $post_type['name'] : '';
												},
												array_filter(
													isset( $source['post-types'] ) && is_array( $source['post-types'] ) ? $source['post-types'] : array(),
													function ( $post_type ) {
														return isset( $post_type['status'] ) && (int) $post_type['status'] === 1;
													}
												)
											)
										)
									);
								?>
								</td>
								<td>
									<span class="wpsc-ait-upload-status wpsc-ait-upload-status--<?php echo esc_attr( $upload_status['key'] ); ?>"><?php echo esc_html( $upload_status['label'] ); ?></span>
								</td>
								<td>
									<span class="wpsc-link" onclick="wpsc_edit_ai_training_source('<?php echo esc_js( (string) $source['slug'] ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_edit_ai_training_source' ) ); ?>')"><?php esc_attr_e( 'Edit', 'supportcandy' ); ?></span>
									<?php
									if ( 'local' != $source['slug'] ) {
										?>
										| <span class="wpsc-link" onclick="wpsc_get_delete_ai_training('<?php echo esc_js( (string) $source['slug'] ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_delete_ai_training' ) ); ?>' )"><?php esc_attr_e( 'Delete', 'supportcandy' ); ?></span>
										<?php
									}
									?>
								</td>
							</tr>
							<?php
						}
						update_option( 'wpsc-ps-ai-training-sources', $sources );
					}
					?>
				</tbody>
			</table>

			<script>
				jQuery(document).ready(function() {
					jQuery('.wpsc-ai-trainings').DataTable({
						ordering: false,
						pageLength: 20,
						bLengthChange: false,
						columnDefs: [ 
							{ targets: -1, searchable: false },
							{ targets: '_all', className: 'dt-left' }
						],
						layout: {
							topStart: {
								buttons: [
									{
										text: '<?php echo esc_attr( wpsc__( 'Add new', 'wpsc-ps' ) ); ?>',
										className: 'wpsc-button small primary',
										action: function ( e, dt, node, config ) {
											wpsc_add_ai_training_source( '<?php echo esc_attr( wp_create_nonce( 'wpsc_add_ai_training_source' ) ); ?>' );
										}
									}
								],
							},
						},
						language: supportcandy.translations.datatables
					});
				});
			</script>
			<?php
			wp_die();
		}

		/**
		 * Get the per-status training record counts for a source, scoped to the
		 * currently configured AI provider - each provider keeps its own independent
		 * copy of a document (see insert_training_post()), so counts must only reflect
		 * the provider actually in use, not every provider a source has ever been synced
		 * under. Soft-deleted (DELETE) records are intentionally excluded from every
		 * bucket, including 'total'.
		 *
		 * @param string $source_slug      Training source slug.
		 * @param string $current_provider Currently configured AI provider.
		 * @return array { new: int, processing: int, indexed: int, failed: int, queue: int, total: int }
		 */
		private static function get_source_record_counts( $source_slug, $current_provider ) {

			$empty_counts = array(
				'new'        => 0,
				'processing' => 0,
				'indexed'    => 0,
				'failed'     => 0,
				'queue'      => 0,
				'total'      => 0,
			);

			if ( '' === $source_slug ) {
				return $empty_counts;
			}

			$counts_by_status = $empty_counts;
			foreach ( array( WPSC_PS_AIT_Status::NEW, WPSC_PS_AIT_Status::PROCESSING, WPSC_PS_AIT_Status::INDEXED, WPSC_PS_AIT_Status::FAILED ) as $status ) {
				$counts_by_status[ $status ] = WPSC_RAG_Training_File::count(
					array(
						'meta_query' => array(
							'relation' => 'AND',
							array(
								'slug'    => 'doc_source',
								'compare' => '=',
								'val'     => $source_slug,
							),
							array(
								'slug'    => 'status',
								'compare' => '=',
								'val'     => $status,
							),
							array(
								'slug'    => 'provider',
								'compare' => '=',
								'val'     => $current_provider,
							),
						),
					)
				);
			}

			$counts_by_status['queue'] = $counts_by_status[ WPSC_PS_AIT_Status::NEW ] + $counts_by_status[ WPSC_PS_AIT_Status::PROCESSING ];
			$counts_by_status['total'] = $counts_by_status[ WPSC_PS_AIT_Status::INDEXED ] + $counts_by_status['queue'];

			return array(
				'new'        => $counts_by_status[ WPSC_PS_AIT_Status::NEW ],
				'processing' => $counts_by_status[ WPSC_PS_AIT_Status::PROCESSING ],
				'indexed'    => $counts_by_status[ WPSC_PS_AIT_Status::INDEXED ],
				'failed'     => $counts_by_status[ WPSC_PS_AIT_Status::FAILED ],
				'queue'      => $counts_by_status['queue'],
				'total'      => $counts_by_status['total'],
			);
		}

		/**
		 * Collapse a source's per-status record counts (see get_source_record_counts())
		 * into the single overall status shown in the sources list table.
		 *
		 * Priority order: an in-flight upload (processing) or a still-pending one
		 * (new) is more actionable/current than a past failure, so those are reported
		 * first; a failure only surfaces once nothing is actively moving, since by
		 * then it is the reason nothing more is happening for this source.
		 *
		 * @param array $counts Per-status counts from get_source_record_counts().
		 * @return array { key: string, label: string }
		 */
		private static function get_source_upload_status( array $counts ) {

			if ( ( $counts['processing'] ?? 0 ) > 0 ) {
				return array(
					'key'   => 'uploading',
					'label' => __( 'Uploading', 'wpsc-ps' ),
				);
			}

			if ( ( $counts['new'] ?? 0 ) > 0 ) {
				return array(
					'key'   => 'queued',
					'label' => __( 'Queue', 'wpsc-ps' ),
				);
			}

			if ( ( $counts['failed'] ?? 0 ) > 0 ) {
				return array(
					'key'   => 'failed',
					'label' => __( 'Failed', 'wpsc-ps' ),
				);
			}

			if ( ( $counts['indexed'] ?? 0 ) > 0 ) {
				return array(
					'key'   => 'completed',
					'label' => __( 'Completed', 'wpsc-ps' ),
				);
			}

			return array(
				'key'   => 'not-synced',
				'label' => __( 'Not Synced', 'wpsc-ps' ),
			);
		}

		/**
		 * Get AI training file upload setting.
		 *
		 * @return void
		 */
		public static function get_aia_file_upload_setting() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$unique_id = uniqid( 'wpsc_' );
			?>
			<div class="wpsc-dock-container">
				<?php
				printf(
					/* translators: Click here to see the documentation */
					esc_attr__( '%s to see the documentation!', 'supportcandy' ),
					'<a href="https://supportcandy.net/docs/ai-training/" target="_blank">' . esc_attr__( 'Click here', 'supportcandy' ) . '</a>'
				);
				?>
			</div>
			<div class="wpsc-ai-training-list-actions-container">
				<div class="wpsc-ai-training-list-actions">
					<div class="wpsc-ai-training-list-actions-bulk-actions">
						<button
							id="wpsc-file-upload-bulk-actions-btn"
							class="wpsc-button small secondary"
							type="button"
							data-popover="wpsc-file-upload-bulk-actions">
							<?php esc_attr_e( 'Bulk Actions', 'supportcandy' ); ?>
							<?php WPSC_Icons::get( 'chevron-down' ); ?>
						</button>
						<div id="wpsc-file-upload-bulk-actions" class="gpopover wpsc-popover-menu wpsc-ticket-bulk-actions" style="width: 200px !important;">
							<div class="wpsc-popover-menu-item" onclick="wpsc_bulk_delete_training( '<?php echo esc_attr( wp_create_nonce( 'wpsc_bulk_delete_training' ) ); ?>' );">
								<?php WPSC_Icons::get( 'trash-alt' ); ?>
								<span><?php esc_html_e( 'Delete', 'wpsc-ps' ); ?></span>
							</div>
						</div>
					</div>
					<div class="wpsc-ai-training-list-actions-select wpsc-more-actions-btn" style="min-width: 200px;">
						<select id="wpsc-file-upload-status-filter" class="load-status-wise-training-list">
							<option value="all"><?php esc_attr_e( 'All statuses', 'wpsc-ps' ); ?></option>
							<?php
							foreach ( WPSC_PS_AIT_Status::get_labels() as $status_value => $status_label ) {
								if ( $status_value == WPSC_PS_AIT_Status::DELETE ) {
									continue;
								}
								?>
								<option value="<?php echo esc_attr( $status_value ); ?>"><?php echo esc_html( $status_label ); ?></option>
								<?php
							}
							?>
						</select>
					</div>
				</div>
				<div class="wpsc-ai-training-list-actions-search">
					<input type="text" id="wpsc-file-upload-list-search" placeholder="<?php esc_attr_e( 'Search...', 'supportcandy' ); ?>">
				</div>
			</div>
			<div class="wpsc-ai-training-list-container">
				<table class="wpsc-ai-file-upload-list-table wpsc-setting-tbl">
					<thead>
						<tr>
							<th style="width: 40px;">
								<div class="checkbox-container">
									<input id="<?php echo esc_attr( $unique_id ); ?>" class="wpsc-bulk-selector" type="checkbox" onchange="wpsc_bulk_select_change();"/>
									<label for="<?php echo esc_attr( $unique_id ); ?>"></label>
								</div>
							</th>
							<th><?php esc_attr_e( 'Status', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Provider', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Source', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'File', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Action', 'wpsc-ps' ); ?></th>
						</tr>
					</thead>
				</table>
			</div>

			<script>
				var trainingTable;
				function wpsc_load_file_upload_training_list(aiStatusType) {

					if (trainingTable) {
						trainingTable.destroy();
					}

					trainingTable = jQuery('.wpsc-ai-file-upload-list-table').DataTable({
						processing: true,
						serverSide: true,
						serverMethod: 'post',
						searching: true,
						ordering: true,
						order: [[ 1, 'asc' ]],
						pageLength: 20,
						bLengthChange: false,

						ajax: {
							url: supportcandy.ajax_url,
							data: {
								action: 'wpsc_get_aia_file_upload_training_list',
								ai_status_type: aiStatusType,
								_ajax_nonce: '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_aia_file_upload_training_list' ) ); ?>'
							}
						},

						columns: [
							{ data: 'selectsingle' },
							{ data: 'status' },
							{ data: 'provider' },
							{ data: 'source' },
							{ data: 'file' },
							{ data: 'action' },
						],

						columnDefs: [
							{ targets: '_all', className: 'dt-left' },
							{ targets: [ 0, 3, 5 ], orderable: false }
						],

						layout: {
							topStart: {
								buttons: [
									{
										text: '<?php echo esc_attr( wpsc__( 'Add new', 'wpsc-ps' ) ); ?>',
										className: 'wpsc-button small primary',
										action: function ( e, dt, node, config ) {
											wpsc_add_ai_training_item( node );
										}
									}
								],
							},
						},

						language: supportcandy.translations.datatables
					});

					// Move the DataTable's "Add new" button in front of the bulk actions/status filter.
					jQuery('.wpsc-ai-training-list-actions').prepend(
						jQuery(trainingTable.table().container()).find('.dt-buttons')
					);
				}

				jQuery(document).ready(function() {

					jQuery('#wpsc-file-upload-bulk-actions-btn').gpopover({
						width: 120
					});
					jQuery('#wpsc-file-upload-status-filter').selectWoo({});

					wpsc_load_file_upload_training_list('all');
					jQuery('#wpsc-file-upload-status-filter').on('change', function(){
						wpsc_load_file_upload_training_list(jQuery(this).val());
					});

					jQuery('#wpsc-file-upload-list-search').on('keyup', function() {
						if (trainingTable) {
							trainingTable.search(this.value).draw();
						}
					});
				});
			</script>
			<?php
			wp_die();
		}

		/**
		 * Get AI training list filtered to file/url sources only, for the File Upload tab.
		 *
		 * @return void
		 */
		public static function get_aia_file_upload_training_list() {

			if ( check_ajax_referer( 'wpsc_get_aia_file_upload_training_list', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( __( 'Unauthorized request!', 'wpsc-ps' ), 401 );
			}

			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				$trainings = array(
					'draw'                 => 1,
					'iTotalRecords'        => 0,
					'iTotalDisplayRecords' => 0,
					'data'                 => array(
						'selectsingle' => '',
						'status'       => '',
						'provider'     => '',
						'source'       => '',
						'file'         => '',
						'action'       => '',
					),
				);
				wp_send_json( $trainings );
			}

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings' );
			$current_provider = $ai_settings['provider'] ?? '';
			$search = isset( $_POST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_POST['search']['value'] ) ) : '';
			$draw       = isset( $_POST['draw'] ) ? intval( $_POST['draw'] ) : 1;
			$start      = isset( $_POST['start'] ) ? intval( $_POST['start'] ) : 1;
			$rowperpage = isset( $_POST['length'] ) ? intval( $_POST['length'] ) : 20;
			$page_no    = ( $start / $rowperpage ) + 1;
			$status_filter = isset( $_POST['ai_status_type'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_status_type'] ) ) : 'all';
			if ( 'all' !== $status_filter && ! WPSC_PS_AIT_Status::is_valid( $status_filter ) ) {
				$status_filter = 'all';
			}

			// Whitelisted map of sortable DataTables column index to actual DB column.
			$sortable_columns = array(
				1 => 'status',
				2 => 'provider',
				4 => 'source',
			);

			$orderby = 'id';
			$order   = 'ASC';

			if ( isset( $_POST['order'][0]['column'] ) ) {
				$order_column = intval( $_POST['order'][0]['column'] );
				if ( isset( $sortable_columns[ $order_column ] ) ) {
					$orderby = $sortable_columns[ $order_column ];
				}
			}

			if ( isset( $_POST['order'][0]['dir'] ) && 'desc' === strtolower( sanitize_text_field( wp_unslash( $_POST['order'][0]['dir'] ) ) ) ) {
				$order = 'DESC';
			}

			$args = array(
				'search'         => $search,
				'items_per_page' => $rowperpage,
				'page_no'        => $page_no,
				'orderby'        => $orderby,
				'order'          => $order,
				'meta_query'     => array(
					'relation' => 'AND',
				),
			);

			if ( 'all' === $status_filter ) {
				$args['meta_query'][] = array(
					'slug'    => 'status',
					'compare' => 'NOT IN',
					'val'     => array( WPSC_PS_AIT_Status::DELETE ),
				);
			} else {
				$args['meta_query'][] = array(
					'slug'    => 'status',
					'compare' => '=',
					'val'     => $status_filter,
				);
			}

			$args['meta_query'][] = array(
				'slug'    => 'provider',
				'compare' => '=',
				'val'     => $current_provider,
			);

			// Only show file/url sourced training items on this tab.
			$args['meta_query'][] = array(
				'slug'    => 'source',
				'compare' => 'IN',
				'val'     => array( WPSC_PS_AIT_Source::FILE, WPSC_PS_AIT_Source::URL ),
			);

			$trainings = WPSC_RAG_Training_File::find( $args );
			$data = array();
			foreach ( $trainings['results'] as $training ) {

				$status = WPSC_PS_AIT_Status::get_label( $training->status );
				$provider = WPSC_PS_AIT_Provider::get_label( $training->provider );
				$training_id = absint( $training->id );

				// Surface the underlying failure reason (if one was recorded) as a tooltip on the
				// status badge instead of leaving admins with just a generic "Failed" label.
				if ( in_array( $training->status, array( WPSC_PS_AIT_Status::FAILED, WPSC_PS_AIT_Status::DELETE ), true ) ) {
					$meta = json_decode( $training->meta_data, true );
					$failure_reason = is_array( $meta ) && ! empty( $meta['failure_reason'] ) ? $meta['failure_reason'] : '';
					if ( '' !== $failure_reason ) {
						$status = '<span title="' . esc_attr( $failure_reason ) . '">' . esc_html( $status ) . ' &#9432;</span>';
					}
				}

				$edit_actions = array();
				if ( $training->provider === $current_provider && ! in_array( $training->status, array( WPSC_PS_AIT_Status::DELETE, WPSC_PS_AIT_Status::PROCESSING ), true ) ) {
					$edit_actions[] = sprintf(
						'<a class="wpsc-link" onclick="wpsc_get_delete_ai_training_item(this, %d, \'%s\')">%s</a>',
						$training_id,
						esc_attr( wp_create_nonce( 'wpsc_get_delete_ai_training_item' ) ),
						esc_html__( 'Delete', 'wpsc-ps' )
					);
				}

				$check_box =
					'<div class="checkbox-container">
						<input id="' . esc_attr( $training_id ) . '" class="wpsc-bulk-select" type="checkbox" onchange="wpsc_bulk_item_select_change();" value="' . esc_attr( $training_id ) . '"/>
						<label for="' . esc_attr( $training_id ) . '"></label>
					</div>';

				$data[] = array(
					'selectsingle' => $check_box,
					'status'       => $status,
					'provider'     => $provider,
					'source'       => WPSC_PS_AIT_Source::get_label( $training->source ),
					'file'         => esc_attr( $training->name ),
					'action'       => implode( ' | ', $edit_actions ),
				);
			}

			$trainings = array(
				'draw'                 => intval( $draw ),
				'iTotalRecords'        => $trainings['total_items'],
				'iTotalDisplayRecords' => $trainings['total_items'],
				'data'                 => $data,
			);

			wp_send_json( $trainings );
		}

		/**
		 * Bulk delete training items from the File Upload tab list.
		 *
		 * @return void
		 */
		public static function bulk_delete_file_upload_training() {

			if ( check_ajax_referer( 'wpsc_bulk_delete_training', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$training_ids = isset( $_POST['training_ids'] ) ? array_filter( array_map( 'intval', $_POST['training_ids'] ) ) : array();
			if ( ! $training_ids ) {
				wp_send_json_error( 'Something went wrong!', 400 );
			}

			$flag = false;
			foreach ( $training_ids as $training_id ) {

				$training = new WPSC_RAG_Training_File( $training_id );
				if ( $training->id && $training->status == WPSC_PS_AIT_Status::DELETE ) {
					continue;
				}
				if ( WPSC_RAG_Training_File::safe_delete( $training ) ) {
					$flag = true;
				}
			}

			if ( $flag && ! wp_next_scheduled( 'wpsc_delete_ai_training_record' ) ) {
				wp_schedule_single_event( time(), 'wpsc_delete_ai_training_record' );
			}
			wp_die();
		}

		/**
		 * Add AI training source UI
		 *
		 * @return void
		 */
		public static function add_ai_training_source() {

			if ( ! check_ajax_referer( 'wpsc_add_ai_training_source', '_ajax_nonce', false ) ) {
				wp_send_json_error( 'Unauthorized request', 401 );
			}

			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'wpsc-ps' ), 401 );
			}
			?>
			<form action="#" onsubmit="return false;" class="wpsc-frm-add-ai-training-source">

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-ait-name">
							<?php esc_attr_e( 'Name', 'wpsc-ps' ); ?>
						</label>
						<span class="required-char">*</span>
					</div>
					<input id="wpsc-ait-name" name="ait-name" type="text" autocomplete="off">
				</div>

				<div class="wpsc-input-group wpsc-ait-wordpress-website">
					<div class="label-container">
						<label for="wpsc-ait-wp-endpoint">
							<?php esc_attr_e( 'WordPress REST API endpoint', 'wpsc-ps' ); ?>
						</label>
						<span class="required-char">*</span>
					</div>
					<div class="divide-bar">
						<input id="wpsc-ait-wp-endpoint" name="ait-wp-endpoint" type="text" style="max-width: 500px;" autocomplete="off">
					</div>
					<span class="extra-info"> <?php esc_html_e( 'Usually this is your site URL (example: https://example.com).', 'wpsc-ps' ); ?> </span>
				</div>

				<input type="hidden" name="action" value="wpsc_set_add_ai_training_source">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_add_ai_training_source' ) ); ?>">

				<div class="setting-footer-actions">
					<button
						type="button"
						class="wpsc-button normal primary margin-right"
						onclick="wpsc_set_add_ai_training_source(this);">
						<?php esc_html_e( 'Save', 'wpsc-ps' ); ?>
					</button>
					<button
						type="button"
						class="wpsc-button normal secondary margin-right"
						onclick="wpsc_get_aia_website_setting();">
						<?php esc_html_e( 'Cancel', 'wpsc-ps' ); ?>
					</button>
				</div>
			</form>
			<?php
			wp_die();
		}

		/**
		 * Add AI training source UI
		 *
		 * @return void
		 */
		public static function edit_ai_training_source() {

			if ( ! check_ajax_referer( 'wpsc_edit_ai_training_source', '_ajax_nonce', false ) ) {
				wp_send_json_error( 'Unauthorized request', 401 );
			}

			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'wpsc-ps' ), 401 );
			}

			$training_slug     = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
			$request_post_type = sanitize_text_field( wp_unslash( $_POST['post-types'] ?? '' ) );

			$sources = get_option( 'wpsc-ps-ai-training-sources', array() );
			$sources = is_array( $sources ) ? array_filter( $sources, 'is_array' ) : array();

			// Index sources by slug for a direct O(1) lookup instead of looping through them.
			$sources_by_slug = array_column( $sources, null, 'slug' );
			$selected_source = ( '' !== $training_slug && isset( $sources_by_slug[ $training_slug ] ) ) ? $sources_by_slug[ $training_slug ] : array();
			$saved_post_types = isset( $selected_source['post-types'] ) && is_array( $selected_source['post-types'] ) ? $selected_source['post-types'] : array();

			// Get requested post type, if any, using the same indexed-lookup approach.
			$matched_post_type = array();
			if ( '' !== $request_post_type && ! empty( $saved_post_types ) ) {
				$post_types_by_slug = array_column( array_filter( $saved_post_types, 'is_array' ), null, 'slug' );
				$matched_post_type  = $post_types_by_slug[ $request_post_type ] ?? array();
			}

			$ait_slug = sanitize_text_field( $selected_source['slug'] ?? '' );
			$ait_name = sanitize_text_field( $selected_source['name'] ?? '' );
			$ait_source = sanitize_text_field( $selected_source['type'] ?? '' );
			// remove trailing content from api url.
			$ait_endpoint = esc_url_raw( $selected_source['api-url'] ?? '' );
			$site_url = preg_replace( '/\/wp-json\/?$/', '', $ait_endpoint );

			// Records for this source are tagged with doc_source = the source's own slug (see insert_training_post()),
			// and with the AI provider they were uploaded to at the time - the currently configured provider is what
			// "Total records" etc. should reflect, not every provider a source has ever been synced under.
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			$current_provider = sanitize_text_field( $ai_settings['provider'] ?? '' );

			$record_counts = self::get_source_record_counts( $ait_slug, $current_provider );
			$indexed_count = $record_counts['indexed'];
			$queue_count   = $record_counts['queue'];
			$total_records = $record_counts['total'];

			$needs_provider_resync = false;
			if ( '' !== $ait_slug ) {

				$enabled_post_type_slugs = array();
				foreach ( $saved_post_types as $post_type ) {
					if ( is_array( $post_type ) && ! empty( $post_type['status'] ) && ! empty( $post_type['slug'] ) ) {
						$enabled_post_type_slugs[] = sanitize_key( $post_type['slug'] );
					}
				}

				$needs_provider_resync = WPSC_PS_AI_Setting_AI_Training_Actions::source_has_other_provider_data( $ait_slug, $enabled_post_type_slugs, $current_provider );
			}

			// Show the sync progress bar already running if a background sync is in flight for this source.
			$sync_running = WPSC_PS_AI_Setting_AI_Training_Actions::is_sync_running( $ait_slug );

			// Offer a manual "Schedule Upload" action only when there's actually something
			// stuck: records waiting (queue_count > 0) but the cron that would upload them
			// isn't due at all. Hidden while a database sync is active anywhere - the
			// upload cron is deliberately deferred until every sync finishes (see
			// WPSC_PS_AIT_Controller::upload_file_to_training()), so scheduling it here would
			// just be cleared again on its next tick, not actually upload anything sooner.
			$upload_scheduled = (bool) wp_next_scheduled( 'wpsc_ai_training_upload' );
			$sync_active = WPSC_PS_AI_Setting_AI_Training_Actions::is_any_sync_active();
			$show_schedule_upload_link = $queue_count > 0 && ! $upload_scheduled && ! $sync_active;
			?>
			<div class="wpsc-back-button">
				<a class="wpsc-link" onclick="wpsc_get_aia_website_setting();"><?php esc_attr_e( 'Back', 'supportcandy' ); ?></a>
			</div>
			<form action="#" onsubmit="return false;" class="wpsc-frm-edit-ai-training-source">

				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="wpsc-ait-name">
							<?php esc_attr_e( 'Name', 'wpsc-ps' ); ?>
						</label>
						<span class="required-char">*</span>
					</div>
					<input id="wpsc-ait-name" name="ait-name" type="text" value="<?php echo esc_attr( $ait_name ); ?>" autocomplete="off">
				</div>

				<div class="wpsc-input-group wpsc-ait-wordpress-website">
					<div class="label-container">
						<label for="wpsc-ait-wp-endpoint">
							<?php esc_attr_e( 'WordPress REST API endpoint', 'wpsc-ps' ); ?>
						</label>
						<span class="required-char">*</span>
					</div>
					<div class="divide-bar">
						<input id="wpsc-ait-wp-endpoint" name="ait-wp-endpoint" type="text" value="<?php echo esc_attr( $site_url ); ?>" style="max-width: 500px;" autocomplete="off" readonly>
						<button type="button" class="wpsc-button small secondary" style="max-width:200px;" onclick="wpsc_fetch_wordpress_endpoints_posts(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_fetch_wordpress_endpoints_posts' ) ); ?>' );"> <?php esc_attr_e( 'Get Post Types', 'wpsc-ps' ); ?> </button>
					</div>
					<span class="extra-info"> <?php esc_html_e( 'Usually this is your site URL (example: https://example.com).', 'wpsc-ps' ); ?> </span>
				</div>

				<div class="wpsc-input-group">
					<div class="wpsc-ait-wordpress-sync-response" style="<?php echo empty( $saved_post_types ) ? 'display:none;' : 'display:block;'; ?>">
						<?php if ( ! empty( $saved_post_types ) ) : ?>
							<div class="label-container">
								<label><?php esc_html_e( 'Select Post Types', 'wpsc-ps' ); ?></label>
							</div>
							<div class="wpsc-container">
								<?php
								foreach ( $saved_post_types as $post_type ) :
									$pt_slug = sanitize_text_field( $post_type['slug'] ?? '' );
									if ( '' === $pt_slug ) {
										continue;
									}
									$pt_name = sanitize_text_field( $post_type['name'] ?? $pt_slug );
									$checked = ! empty( $post_type['status'] );
									$input_id = 'wpsc-post-type-' . sanitize_html_class( $pt_slug );
									?>
									<div class="checkbox-container" style="margin-bottom:5px;">
										<input id="<?php echo esc_attr( $input_id ); ?>" type="checkbox" name="ait-post-types[]" value="<?php echo esc_attr( $pt_slug ); ?>" <?php checked( $checked ); ?>>
										<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $pt_name ); ?></label>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $matched_post_type ) ) : ?>
							<input type="hidden" name="matched-post-type" value="<?php echo esc_attr( wp_json_encode( $matched_post_type ) ); ?>">
						<?php endif; ?>
					</div>
				</div>

				<input type="hidden" name="ait-training-type" value="<?php echo esc_attr( $training_slug ); ?>">
				<input type="hidden" name="wpsc_update_ai_training_source_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_update_edit_ai_training_source' ) ); ?>">
				<input type="hidden" name="wpsc_get_ait_sync_progress_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_get_ait_sync_progress' ) ); ?>">
				<input type="hidden" name="wpsc-ait-edit-refresh-nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_edit_ai_training_source' ) ); ?>">

				<div class="wpsc-input-group">
					<div class="setting-footer-actions">
						<button
							id="wpsc-update-source-btn"
							type="button"
							class="wpsc-button normal primary margin-right"
							onclick="wpsc_update_edit_ai_training_source(this);"
							<?php disabled( $sync_running ); ?>>
							<?php esc_html_e( 'Update', 'wpsc-ps' ); ?>
						</button>
					</div>
				</div>
				
				<div class="wpsc-ait-record-counts">
					<span><?php esc_html_e( 'Total records:', 'wpsc-ps' ); ?> <strong><?php echo esc_html( $total_records ); ?></strong></span>
					<span><?php esc_html_e( 'Indexed:', 'wpsc-ps' ); ?> <strong><?php echo esc_html( $indexed_count ); ?></strong></span>
					<span>
						<?php esc_html_e( 'In Queue:', 'wpsc-ps' ); ?> <strong><?php echo esc_html( $queue_count ); ?></strong>
						<?php if ( $show_schedule_upload_link ) : ?>
							<span
								class="wpsc-link wpsc-ait-retry-upload-link"
								title="<?php esc_attr_e( 'The upload isn\'t scheduled yet - click to schedule it now.', 'wpsc-ps' ); ?>"
								onclick="wpsc_schedule_ai_training_upload(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_schedule_ai_training_upload' ) ); ?>', '<?php echo esc_attr( $ait_slug ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_edit_ai_training_source' ) ); ?>');">
								<?php esc_html_e( 'Retry Upload', 'wpsc-ps' ); ?>
							</span>
						<?php endif; ?>
					</span>
				</div>

				<hr>
				<div class="wpsc-tt-data-sync-setting">
					<?php if ( $needs_provider_resync ) : ?>
						<div class="wpsc-ait-provider-notice">
							<?php esc_html_e( 'Your AI Assistant provider has been changed. Some or all of your existing training data was uploaded using a different AI provider. Please sync the affected data again to continue using the AI Assistant.', 'wpsc-ps' ); ?>
						</div>
					<?php endif; ?>
					<div class="wpsc-input-group">
						<div class="label-container">
							<label for="wpsc-ait-wp-endpoint">
								<?php esc_attr_e( 'Data Synchronization & Actions', 'wpsc-ps' ); ?>
							</label>
						</div>
					</div>

					<div class="wpsc-input-group options">
						<button
							id="wpsc-sync-posts-btn"
							type="button"
							class="wpsc-button normal secondary margin-right"
							onclick="wpsc_sync_posts_for_ai_training(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_sync_posts_for_ai_training' ) ); ?>', '<?php echo esc_attr( $ait_slug ); ?>' );"
							<?php disabled( $sync_running ); ?>>
							<?php esc_html_e( 'Sync Posts', 'wpsc-ps' ); ?>
						</button>
						<span class="extra-info">
							<?php esc_attr_e( 'By clicking "Sync Posts" button, posts will be synchronized (If previously synced posts is changed or updated then resynced) for AI training.', 'wpsc-ps' ); ?>
						</span>

						<div class="wpsc-ait-sync-progress" style="<?php echo $sync_running ? 'display:block;' : 'display:none;'; ?>">
							<div class="wpsc-ait-sync-notice">
								<?php esc_html_e( 'Sync in progress - please do not refresh this page or navigate away until it completes.', 'wpsc-ps' ); ?>
							</div>
							<div class="wpsc-ait-sync-progress-bar">
								<div class="wpsc-ait-sync-progress-fill"></div>
							</div>
							<span class="wpsc-ait-sync-progress-label"></span>
							<ul class="wpsc-ait-sync-post-types"></ul>
						</div>
					</div>

					<div class="wpsc-input-group options">
						<button
							id="wpsc-delete-all-posts-btn"
							type="button"
							class="wpsc-button normal secondary margin-right"
							onclick="wpsc_delete_all_ait_posts(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_delete_all_ait_posts' ) ); ?>', '<?php echo esc_attr( $ait_slug ); ?>' );"
							<?php disabled( $sync_running ); ?>>
							<?php esc_html_e( 'Delete All Posts', 'wpsc-ps' ); ?>
						</button>
						<span class="extra-info">
							<?php esc_attr_e( 'By clicking "Delete all Posts" button, all posts will be deleted for AI training.', 'wpsc-ps' ); ?>
						</span>
					</div>
				</div>

			</form>
			<?php
			if ( $sync_running ) {
				?>
				<script>
					jQuery( function() {
						wpsc_poll_ait_sync_progress( <?php echo wp_json_encode( $ait_slug ); ?> );
					} );
				</script>
				<?php
			}
			wp_die();
		}

		/**
		 * AJAX: Manually schedule the wpsc_ai_training_upload cron when it's due
		 * (records waiting in queue) but not currently scheduled - see the
		 * "Schedule Upload" link rendered next to the In Queue count in
		 * edit_ai_training_source().
		 *
		 * Re-checks both conditions server-side rather than trusting the link only
		 * being rendered when appropriate, since the page state can go stale between
		 * render and click (another admin/tab, a sync starting, the cron firing on
		 * its own in the meantime).
		 *
		 * @return void
		 */
		public static function schedule_ai_training_upload() {

			if ( check_ajax_referer( 'wpsc_schedule_ai_training_upload', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized request!', 'wpsc-ps' ) ), 401 );
			}

			if ( ! WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized access!', 'wpsc-ps' ) ), 401 );
			}

			if ( WPSC_PS_AI_Setting_AI_Training_Actions::is_any_sync_active() ) {
				wp_send_json_error( array( 'message' => __( 'A database sync is currently in progress. The upload will be scheduled automatically once it finishes.', 'wpsc-ps' ) ), 409 );
			}

			if ( wp_next_scheduled( 'wpsc_ai_training_upload' ) ) {
				wp_send_json_success( array( 'message' => __( 'Upload is already scheduled.', 'wpsc-ps' ) ) );
			}

			wp_schedule_single_event( time(), 'wpsc_ai_training_upload' );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}

			wp_send_json_success( array( 'message' => __( 'Upload scheduled.', 'wpsc-ps' ) ) );
		}
	}
endif;
WPSC_PS_AI_Setting_AI_Training::init();
