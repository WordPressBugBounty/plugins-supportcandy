<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Setting_AI_Training_Data' ) ) :

	final class WPSC_PS_AI_Setting_AI_Training_Data {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Load section for this screen.
			add_action( 'wp_ajax_wpsc_get_aia_training_data_setting', array( __CLASS__, 'get_aia_training_data_setting' ) );

			// AI training data list, across all sources.
			add_action( 'wp_ajax_wpsc_get_aia_training_data_list', array( __CLASS__, 'get_aia_training_data_list' ) );
		}

		/**
		 * Get AI Training Data tab setting - a copy of the File Upload tab's list,
		 * but showing training records from every source (files, URLs, website posts,
		 * etc.) with an additional filter to see which configured training source a
		 * record came from.
		 *
		 * @return void
		 */
		public static function get_aia_training_data_setting() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$training_sources = get_option( 'wpsc-ps-ai-training-sources', array() );
			$training_sources = is_array( $training_sources ) ? array_filter( $training_sources, 'is_array' ) : array();

			// The "Type" filter must reflect what actually lands in the "Type" column - the raw
			// 'source' value on each record. That's a WPSC_PS_AIT_Source constant (ticket/file/url)
			// for tickets and manual uploads, but a WP post type slug (post/page/product/etc.) for
			// anything synced in from a configured website training source - see
			// WPSC_PS_AI_Setting_AI_Training_Actions::insert_training_post(). So pull the distinct
			// values actually present instead of assuming the fixed ticket/file/url set.
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings' );
			$current_provider = $ai_settings['provider'] ?? '';
			$type_values = WPSC_RAG_Training_File::pluck(
				'source',
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'provider',
							'compare' => '=',
							'val'     => $current_provider,
						),
						array(
							'slug'    => 'status',
							'compare' => 'NOT IN',
							'val'     => array( WPSC_PS_AIT_Status::DELETE ),
						),
					),
				)
			);
			$type_values = array_filter( array_unique( $type_values ) );

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
			<div class="wpsc-aia-toolbar">

				<div class="wpsc-aia-toolbar-item">
					<label><?php esc_attr_e( 'Status', 'wpsc-ps' ); ?></label>
					<select id="wpsc-training-data-status-filter">
						<option value="all"><?php esc_attr_e( 'All statuses', 'wpsc-ps' ); ?></option>
						<?php
						foreach ( WPSC_PS_AIT_Status::get_labels() as $status_value => $status_label ) {
							if ( $status_value == WPSC_PS_AIT_Status::DELETE ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $status_value ); ?>" <?php selected( $status_value, WPSC_PS_AIT_Status::INDEXED ); ?>><?php echo esc_html( $status_label ); ?></option>
							<?php
						}
						?>
					</select>
				</div>

				<div class="wpsc-aia-toolbar-item">
					<label><?php esc_attr_e( 'Source', 'wpsc-ps' ); ?></label>
					<select id="wpsc-training-data-source-filter">
						<option value="all"><?php esc_attr_e( 'All sources', 'wpsc-ps' ); ?></option>
						<option value="_uploads"><?php esc_attr_e( 'File/URL Uploads', 'wpsc-ps' ); ?></option>
						<?php
						foreach ( $training_sources as $source ) {
							$slug = sanitize_text_field( $source['slug'] ?? '' );
							$name = sanitize_text_field( $source['name'] ?? $slug );
							if ( '' === $slug ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
							<?php
						}
						?>
					</select>
				</div>

				<div class="wpsc-aia-toolbar-item">
					<label><?php esc_attr_e( 'Type', 'wpsc-ps' ); ?></label>
					<select id="wpsc-training-data-type-filter">
						<option value="all"><?php esc_attr_e( 'All types', 'wpsc-ps' ); ?></option>
						<?php foreach ( $type_values as $type_value ) : ?>
							<option value="<?php echo esc_attr( $type_value ); ?>"><?php echo esc_html( WPSC_PS_AIT_Source::get_label( $type_value ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="wpsc-aia-toolbar-item wpsc-aia-toolbar-search">
					<label><?php esc_attr_e( 'Search', 'supportcandy' ); ?></label>
					<input type="text" id="wpsc-training-data-list-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Search...', 'supportcandy' ); ?>">
				</div>

				<div class="wpsc-aia-toolbar-item wpsc-aia-toolbar-reset">
					<button type="button" class="wpsc-button normal secondary" id="wpsc-training-data-reset-filter">
						<?php esc_attr_e( 'Reset', 'supportcandy' ); ?>
					</button>
				</div>

			</div>

			<div class="wpsc-aia-actions-bar">
				<div class="wpsc-ai-training-list-actions-bulk-actions">
					<button
						id="wpsc-training-data-bulk-actions-btn"
						class="wpsc-button small secondary"
						type="button"
						data-popover="wpsc-training-data-bulk-actions">
						<?php esc_attr_e( 'Bulk Actions', 'supportcandy' ); ?>
						<?php WPSC_Icons::get( 'chevron-down' ); ?>
					</button>
					<div id="wpsc-training-data-bulk-actions" class="gpopover wpsc-popover-menu wpsc-ticket-bulk-actions" style="width: 200px !important;">
						<div class="wpsc-popover-menu-item" onclick="wpsc_bulk_delete_training( '<?php echo esc_attr( wp_create_nonce( 'wpsc_bulk_delete_training' ) ); ?>' );">
							<?php WPSC_Icons::get( 'trash-alt' ); ?>
							<span><?php esc_html_e( 'Delete', 'wpsc-ps' ); ?></span>
						</div>
					</div>
				</div>
			</div>

			<div class="wpsc-ai-training-list-container">
				<table class="wpsc-ai-training-data-list-table wpsc-setting-tbl">
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
							<th><?php esc_attr_e( 'Type', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Source', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Name', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Action', 'wpsc-ps' ); ?></th>
						</tr>
					</thead>
				</table>
			</div>

			<script>
				var trainingTable;

				jQuery(document).ready(function() {

					jQuery('#wpsc-training-data-bulk-actions-btn').gpopover({
						width: 120
					});
					jQuery('#wpsc-training-data-status-filter').selectWoo({ minimumResultsForSearch: 0, width: '100%' });
					jQuery('#wpsc-training-data-source-filter').selectWoo({ minimumResultsForSearch: 0, width: '100%' });
					jQuery('#wpsc-training-data-type-filter').selectWoo({ minimumResultsForSearch: 0, width: '100%' });

					trainingTable = jQuery('.wpsc-ai-training-data-list-table').DataTable({
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
							data: function (d) {
								d.action = 'wpsc_get_aia_training_data_list';
								d.ai_status_type = jQuery('#wpsc-training-data-status-filter').val();
								d.ai_source_type = jQuery('#wpsc-training-data-source-filter').val();
								d.ai_type_filter = jQuery('#wpsc-training-data-type-filter').val();
								d._ajax_nonce = '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_aia_training_data_list' ) ); ?>';
							}
						},

						columns: [
							{ data: 'selectsingle' },
							{ data: 'status' },
							{ data: 'provider' },
							{ data: 'type' },
							{ data: 'source' },
							{ data: 'name' },
							{ data: 'action' },
						],

						columnDefs: [
							{ targets: '_all', className: 'dt-left' },
							{ targets: [ 0, 6 ], orderable: false }
						],

						language: supportcandy.translations.datatables
					});

					jQuery('#wpsc-training-data-status-filter, #wpsc-training-data-source-filter, #wpsc-training-data-type-filter').on('change', function() {
						trainingTable.ajax.reload();
					});

					jQuery('#wpsc-training-data-list-search').on('keyup', function() {
						trainingTable.search(this.value).draw();
					});

					jQuery('#wpsc-training-data-reset-filter').on('click', function() {
						jQuery('#wpsc-training-data-status-filter').val('<?php echo esc_js( WPSC_PS_AIT_Status::INDEXED ); ?>').trigger('change.select2');
						jQuery('#wpsc-training-data-source-filter').val('all').trigger('change.select2');
						jQuery('#wpsc-training-data-type-filter').val('all').trigger('change.select2');
						jQuery('#wpsc-training-data-list-search').val('');
						trainingTable.search('');
						trainingTable.ajax.reload();
					});
				});
			</script>
			<?php
			wp_die();
		}

		/**
		 * Get AI training list across every source (files, URLs, website posts, etc.)
		 * for the AI Training Data tab, optionally filtered by status and/or the
		 * configured training source (doc_source) a record was synced from.
		 *
		 * @return void
		 */
		public static function get_aia_training_data_list() {

			if ( ! check_ajax_referer( 'wpsc_get_aia_training_data_list', '_ajax_nonce', false ) ) {
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
						'type'         => '',
						'source'       => '',
						'name'         => '',
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

			// Whitelist the requested source filter against configured training sources plus
			// the synthetic '_uploads' bucket (file/url uploads, which never carry a doc_source).
			$training_sources = get_option( 'wpsc-ps-ai-training-sources', array() );
			$training_sources = is_array( $training_sources ) ? array_filter( $training_sources, 'is_array' ) : array();
			$valid_source_slugs = array_filter( array_map( fn( $source ) => sanitize_text_field( $source['slug'] ?? '' ), $training_sources ) );

			$source_filter = isset( $_POST['ai_source_type'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_source_type'] ) ) : 'all';
			if ( 'all' !== $source_filter && '_uploads' !== $source_filter && ! in_array( $source_filter, $valid_source_slugs, true ) ) {
				$source_filter = 'all';
			}

			// Type filter - the record's raw 'source' value, shown as the "Type" column. This is a
			// WPSC_PS_AIT_Source constant (ticket/file/url) for tickets and manual uploads, but a WP
			// post type slug (post/page/product/etc.) for records synced from a configured website
			// training source - see insert_training_post() - so whitelist against what is actually
			// present for this provider rather than the fixed ticket/file/url set.
			$valid_type_values = WPSC_RAG_Training_File::pluck(
				'source',
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'provider',
							'compare' => '=',
							'val'     => $current_provider,
						),
						array(
							'slug'    => 'status',
							'compare' => 'NOT IN',
							'val'     => array( WPSC_PS_AIT_Status::DELETE ),
						),
					),
				)
			);

			$type_filter = isset( $_POST['ai_type_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_type_filter'] ) ) : 'all';
			if ( 'all' !== $type_filter && ! in_array( $type_filter, $valid_type_values, true ) ) {
				$type_filter = 'all';
			}

			// Whitelisted map of sortable DataTables column index to actual DB column.
			$sortable_columns = array(
				1 => 'status',
				2 => 'provider',
				3 => 'source',
				4 => 'doc_source',
				5 => 'name',
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

			if ( 'all' !== $type_filter ) {
				$args['meta_query'][] = array(
					'slug'    => 'source',
					'compare' => '=',
					'val'     => $type_filter,
				);
			}

			if ( '_uploads' === $source_filter ) {
				$args['meta_query'][] = array(
					'slug'    => 'doc_source',
					'compare' => '=',
					'val'     => '',
				);
			} elseif ( 'all' !== $source_filter ) {
				$args['meta_query'][] = array(
					'slug'    => 'doc_source',
					'compare' => '=',
					'val'     => $source_filter,
				);
			}

			$trainings = WPSC_RAG_Training_File::find( $args );
			$data = array();
			foreach ( $trainings['results'] as $training ) {

				$status = WPSC_PS_AIT_Status::get_label( $training->status );
				$provider = WPSC_PS_AIT_Provider::get_label( $training->provider );
				$training_id = absint( $training->id );

				// Surface the underlying failure/skip reason (if one was recorded) via a "View
				// Reason" action instead of leaving admins with just a generic "Failed"/"Deleted" label.
				$reason = '';
				if ( in_array( $training->status, array( WPSC_PS_AIT_Status::FAILED, WPSC_PS_AIT_Status::DELETE ), true ) ) {
					$meta = json_decode( $training->meta_data, true );
					$reason = is_array( $meta ) && ! empty( $meta['failure_reason'] ) ? $meta['failure_reason'] : '';
				}

				$edit_actions = array();
				if ( $reason ) {
					$edit_actions[] = sprintf(
						'<a class="wpsc-link" onclick="wpsc_view_reason_for_failed_ai_training_item(this, %d, \'%s\')">%s</a>',
						$training_id,
						esc_attr( wp_create_nonce( 'wpsc_view_reason_for_failed_ai_training_item' ) ),
						esc_html__( 'View Reason', 'wpsc-ps' )
					);
				}

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
					'type'         => WPSC_PS_AIT_Source::get_label( $training->source ),
					'source'       => self::get_doc_source_label( $training->doc_source ),
					'name'         => esc_attr( $training->name ),
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
		 * Resolve a training record's doc_source (the slug of the configured training
		 * source it was synced from - see WPSC_PS_AI_Setting_AI_Training_Actions::insert_training_post())
		 * to a human-readable label for the "Source" column/filter.
		 *
		 * File and URL uploads are never tied to a training source and always carry an
		 * empty doc_source (see WPSC_PS_AIT_Training::add_ai_training_item()), so those
		 * are labelled distinctly rather than as an unresolved/empty source.
		 *
		 * @param string $doc_source Training source slug, or '' for file/url uploads.
		 * @return string
		 */
		private static function get_doc_source_label( $doc_source ) {

			if ( '' === $doc_source ) {
				return esc_html__( 'File/URL Upload', 'wpsc-ps' );
			}

			$source = WPSC_PS_AIT_Source::get_training_source( $doc_source );
			if ( ! empty( $source['name'] ) ) {
				return esc_html( $source['name'] );
			}

			return esc_html( ucwords( str_replace( array( '-', '_' ), ' ', $doc_source ) ) );
		}
	}
endif;
WPSC_PS_AI_Setting_AI_Training_Data::init();
