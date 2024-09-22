<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Customers' ) ) :

	final class WPSC_Customers {

		/**
		 * Initialize the class
		 *
		 * @return void
		 */
		public static function init() {

			// get customer list.
			add_action( 'wp_ajax_wpsc_get_customer_list', array( __CLASS__, 'get_customer_list' ) );

			// view customer info.
			add_action( 'wp_ajax_wpsc_view_customer_info', array( __CLASS__, 'view_customer_info' ) );

			// edit customer info.
			add_action( 'wp_ajax_wpsc_get_edit_customer_info', array( __CLASS__, 'get_edit_customer_info' ) );
			add_action( 'wp_ajax_wpsc_set_edit_customer_info', array( __CLASS__, 'set_edit_customer_info' ) );

			// view customer logs.
			add_action( 'wp_ajax_wpsc_view_customer_logs', array( __CLASS__, 'view_customer_logs' ) );

			// delete customer logs.
			add_action( 'wp_ajax_wpsc_delete_customer', array( __CLASS__, 'delete_customer_info' ) );

			// calculate customer ticket count.
			add_action( 'wpsc_create_new_ticket', array( __CLASS__, 'customer_ticket_count' ) );
			add_action( 'wpsc_delete_ticket', array( __CLASS__, 'customer_ticket_count' ) );
			add_action( 'wpsc_ticket_restore', array( __CLASS__, 'customer_ticket_count' ) );
			add_action( 'wpsc_change_raised_by', array( __CLASS__, 'customer_ticket_count' ), 200, 4 );

			// view customer profile info.
			add_action( 'wp_ajax_wpsc_view_customer_detailed_info', array( __CLASS__, 'view_customer_detailed_info' ) );

			// Get customer recent activities.
			add_action( 'wp_ajax_wpsc_upw_get_recent_activities', array( __CLASS__, 'get_upw_recent_activities' ) );
			add_action( 'wp_ajax_nopriv_wpsc_upw_get_recent_activities', array( __CLASS__, 'get_upw_recent_activities' ) );
		}

		/**
		 * Admin submenu layout
		 *
		 * @return void
		 */
		public static function layout() {

			$types = apply_filters(
				'wpsc_customer_type_filter',
				array(
					'all'                   => esc_attr__( 'All Users', 'supportcandy' ),
					'customer_with_tickets' => esc_attr__( 'Users has tickets', 'supportcandy' ),
				)
			);

			?>
			<div class="wrap">
				<hr class="wp-header-end">
				<div id="wpsc-container">
					<div class="wpsc-setting-header">
						<h2><?php esc_attr_e( 'Customers', 'supportcandy' ); ?></h2>
					</div>
					<div class="wpsc-setting-section-body">						
						<div class="wpsc-feedback-filter-container">
							<div class="wpsc-filter-container">
								<div class="wpsc-filter-item" style="min-width: 200px;">
									<select name="wpsc-cust-list-filter" id="wpsc-cust-list-filter">
										<?php
										foreach ( $types as $key => $type ) {
											$selected = 'selected="selected"';
											?>
											<option <?php echo esc_attr( $selected ); ?> value="<?php echo esc_attr( $key ); ?>"><?php echo esc_attr( $type ); ?></option>
											<?php
										}
										?>
									</select>
								</div>
								<div class="wpsc-filter-item" style="min-width: 250px;">
									<input type="text" id="wpsc-cust-filter-search" placeholder="<?php esc_attr_e( 'Search...', 'supportcandy' ); ?>">
								</div>
							</div>
						</div>
						<?php
						self::load_customer_list();
						WPSC_Tickets::load_html_snippets();
						?>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * List customer list
		 *
		 * @return void
		 */
		public static function load_customer_list() {

			?>
			<div id="wpsc-customer-list">
				<table class="wpsc_customer_list wpsc-setting-tbl">
					<thead>
						<tr>
							<th><?php esc_attr_e( 'Name', 'supportcandy' ); ?></th>
							<th><?php esc_attr_e( 'Email address', 'supportcandy' ); ?></th>
							<th><?php esc_attr_e( 'User Type', 'supportcandy' ); ?></th>
							<th><?php esc_attr_e( 'Number of tickets', 'supportcandy' ); ?></th>
							<th><?php esc_attr_e( 'Actions', 'supportcandy' ); ?></th>
						</tr>
					</thead>
				</table>
			</div>
			<script>
				function load_customer_list(custType) {

					jQuery('.wpsc_customer_list').dataTable({
						processing: true,
						serverSide: true,
						serverMethod: 'post',
						ajax: { 
							url: supportcandy.ajax_url,
							data: {
								'action': 'wpsc_get_customer_list',
								'cust_type': custType,
								'_ajax_nonce': '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_customer_list' ) ); ?>'
							}
						},
						'columns': [
							{ data: 'name' },
							{ data: 'email' },
							{ data: 'type' },
							{ data: 'tickets' },
							{ data: 'actions' },
						],
						'bDestroy': true,
						'searching': true,
						'ordering': false,
						'bLengthChange': false,
						pageLength: 20,
						columnDefs: [ 
							{ targets: '_all', className: 'dt-left' },
						],
						language: supportcandy.translations.datatables
					});
				}

				jQuery(document).ready(function() {

					load_customer_list('customer_with_tickets');

					jQuery('#wpsc-cust-filter-search').on('keyup', function() {
						var searchTerm = jQuery(this).val();
						jQuery('table.wpsc_customer_list').DataTable().search(searchTerm).draw();
					});

					jQuery('#wpsc-cust-list-filter').on('change', function(){
						var custType = jQuery(this).val();
						load_customer_list(custType);
					});
				});

				<?php do_action( 'wpsc_js_customer_list_functions' ); ?>
			</script>
			<?php
		}

		/**
		 * Get list of all customers
		 *
		 * @return void
		 */
		public static function get_customer_list() {

			if ( check_ajax_referer( 'wpsc_get_customer_list', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$search     = isset( $_POST['search'] ) && isset( $_POST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_POST['search']['value'] ) ) : '';
			$draw       = isset( $_POST['draw'] ) ? intval( $_POST['draw'] ) : 1;
			$start      = isset( $_POST['start'] ) ? intval( $_POST['start'] ) : 1;
			$rowperpage = isset( $_POST['length'] ) ? intval( $_POST['length'] ) : 20;
			$page_no    = ( $start / $rowperpage ) + 1;
			$cust_type = isset( $_POST['cust_type'] ) ? sanitize_text_field( wp_unslash( $_POST['cust_type'] ) ) : 'customer_with_tickets';

			$args = array(
				'search'         => $search,
				'items_per_page' => $rowperpage,
				'page_no'        => $page_no,
				'orderby'        => 'ticket_count',
				'order'          => 'DESC',
				'meta_query'     => array(
					'relation' => 'AND',
				),
			);

			if ( $cust_type == 'customer_with_tickets' ) {

				$args['meta_query'][] = array(
					'slug'    => 'ticket_count',
					'compare' => '>',
					'val'     => 0,
				);
			}

			if ( $cust_type == 'all' ) {

				$args['meta_query'][] = array();
			}

			$args = apply_filters( 'wpsc_get_customer_list_filters', $args, $cust_type );
			$response = WPSC_Customer::find( $args );

			$customers = $response['results'];

			$data = array();
			foreach ( $customers as $customer ) {

				$agent = WPSC_Agent::get_by_customer( $customer );
				ob_start();
				?>
				<a href="javascript:wpsc_view_customer_info(<?php echo esc_attr( $customer->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_view_customer_info' ) ); ?>')" class="wpsc-link">
					<?php esc_attr_e( 'View', 'supportcandy' ); ?>
				</a> |
				<a href="javascript:wpsc_get_edit_customer_info(<?php echo esc_attr( $customer->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_edit_customer_info' ) ); ?>')" class="wpsc-link">
					<?php esc_attr_e( 'Edit', 'supportcandy' ); ?>
				</a> |
				<a href="javascript:wpsc_view_customer_logs(<?php echo esc_attr( $customer->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_view_customer_logs' ) ); ?>')" class="wpsc-link">
					<?php esc_attr_e( 'Logs', 'supportcandy' ); ?>
				</a>
				<?php
				if ( ! $agent->id || ( $agent->role != 1 ) ) {
					?>
					| <a href="javascript:wpsc_delete_customer(<?php echo esc_attr( $customer->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_delete_customer' ) ); ?>')" class="wpsc-link">
						<?php esc_attr_e( 'Delete', 'supportcandy' ); ?>
					</a>
					<?php
				}
				?>
				<?php
				$actions = ob_get_clean();

				$data[] = array(
					'name'    => '<a class="wpsc-link" href="javascript:wpsc_view_customer_detailed_info( ' . $customer->id . ', \'' . wp_create_nonce( 'wpsc_view_customer_detailed_info' ) . '\' )">' . $customer->name . '<a>',
					'email'   => $customer->email,
					'type'    => ! is_object( $customer->user ) ? esc_attr__( 'Guest', 'supportcandy' ) : esc_attr__( 'Registered', 'supportcandy' ),
					'tickets' => $customer->ticket_count,
					'actions' => $actions,
				);
			}

			$response = array(
				'draw'                 => intval( $draw ),
				'iTotalRecords'        => $response['total_items'],
				'iTotalDisplayRecords' => $response['total_items'],
				'data'                 => $data,
			);

			wp_send_json( $response );
		}

		/**
		 * View customer info
		 *
		 * @return void
		 */
		public static function view_customer_info() {

			if ( check_ajax_referer( 'wpsc_view_customer_info', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;
			if ( ! $customer_id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$customer = new WPSC_Customer( $customer_id );
			if ( ! $customer->id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$title     = esc_attr__( 'Customer info', 'supportcandy' );
			$unique_id = uniqid();

			ob_start();
			?>
			<div class="wpsc-thread-info">

				<div style="width: 100%;">
					<table class="wpsc-setting-tbl <?php echo esc_attr( $unique_id ); ?>" style="margin-bottom: 15px;">
						<thead>
							<tr>
								<th><?php esc_attr_e( 'Field', 'supportcandy' ); ?></th>
								<th><?php esc_attr_e( 'Value', 'supportcandy' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><?php esc_attr_e( 'Name', 'supportcandy' ); ?>:</td>
								<td><?php echo esc_attr( $customer->name ); ?></td>
							</tr>
							<tr>
								<td><?php esc_attr_e( 'Email Address', 'supportcandy' ); ?>:</td>
								<td><?php echo esc_attr( $customer->email ); ?></td>
							</tr>
							<?php
							foreach ( WPSC_Custom_Field::$custom_fields as $cf ) :
								if ( $cf->field !== 'customer' || in_array( $cf->slug, WPSC_DF_Customer::$ignore_customer_info_cft ) ) {
									continue;
								}
								?>
								<tr>
									<td><?php echo esc_attr( $cf->name ); ?>:</td>
									<td><?php $cf->type::print_widget_customer_field_val( $cf, $customer ); ?></td>
								</tr>
								<?php
							endforeach;
							?>
						</tbody>
					</table>
					<script>
						jQuery('.<?php echo esc_attr( $unique_id ); ?>').DataTable({
							searching: false,
							paging:	   false,
							ordering:  false,
							info:      false
						});
					</script>
				</div>
				<?php do_action( 'wpsc_view_customer_info', $customer ); ?>
			</div>
			<?php
			$body = ob_get_clean();

			ob_start();

			?>
			<button class="wpsc-button small primary" onclick="wpsc_get_edit_customer_info(<?php echo esc_attr( $customer->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_edit_customer_info' ) ); ?>');">
				<?php esc_attr_e( 'Edit Info', 'supportcandy' ); ?>
			</button>
			<button class="wpsc-button small secondary" onclick="wpsc_close_modal();">
				<?php esc_attr_e( 'Close', 'supportcandy' ); ?>
			</button>
			<?php
			do_action( 'wpsc_view_customer_info_footer', $customer );
			$footer = ob_get_clean();

			$response = array(
				'title'  => $title,
				'body'   => $body,
				'footer' => $footer,
			);
			wp_send_json( $response );
		}

		/**
		 * Get edit customer info
		 *
		 * @return void
		 */
		public static function get_edit_customer_info() {

			if ( check_ajax_referer( 'wpsc_get_edit_customer_info', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;
			if ( ! $customer_id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$customer = new WPSC_Customer( $customer_id );
			if ( ! $customer->id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$title = esc_attr__( 'Edit customer info', 'supportcandy' );
			ob_start();
			?>
			<form action="#" onsubmit="return false;" class="frm-edit-customer-info">
				<?php

				$cf = WPSC_Custom_Field::get_cf_by_slug( 'name' )
				?>
				<div class="wpsc-tff">
					<div class="wpsc-tff-label">
						<span class="name"><?php echo esc_attr( $cf->name ); ?></span>
					</div>
					<span class="extra-info"><?php echo esc_attr( $cf->extra_info ); ?></span>
					<input
						type="text"
						name="<?php echo esc_attr( $cf->slug ); ?>"
						value="<?php echo esc_attr( $customer->name ); ?>"
						placeholder="<?php echo esc_attr( $cf->placeholder_text ); ?>"
						autocomplete="off"/>
				</div>
				<?php
				foreach ( WPSC_Custom_Field::$custom_fields as $cf ) {
					if ( $cf->field !== 'customer' || in_array( $cf->slug, WPSC_DF_Customer::$ignore_customer_info_cft ) ) {
						continue;
					}
					$properties = array(
						'is-required' => 0,
						'width'       => 'full',
						'visibility'  => '',
					);
					echo $cf->type::print_edit_customer_info( $cf, $customer, $properties ); // phpcs:ignore
				}
				do_action( 'wpsc_get_edit_customer_info_body', $customer );
				?>
				<input type="hidden" name="action" value="wpsc_set_edit_customer_info"/>
				<input type="hidden" name="id" value="<?php echo esc_attr( $customer->id ); ?>">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_edit_customer_info' ) ); ?>">
			</form>
			<?php
			do_action( 'wpsc_get_edit_customer_info_footer', $customer );
			$body = ob_get_clean();

			ob_start();
			?>
			<button class="wpsc-button small primary" onclick="wpsc_set_edit_customer_info(this, <?php echo esc_attr( $customer->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_view_customer_info' ) ); ?>');">
				<?php esc_attr_e( 'Submit', 'supportcandy' ); ?>
			</button>
			<button class="wpsc-button small secondary" onclick="wpsc_close_modal();">
				<?php esc_attr_e( 'Cancel', 'supportcandy' ); ?>
			</button>
			<?php
			$footer = ob_get_clean();

			$response = array(
				'title'  => $title,
				'body'   => $body,
				'footer' => $footer,
			);
			wp_send_json( $response );
		}

		/**
		 * Save customer info
		 *
		 * @return void
		 */
		public static function set_edit_customer_info() {

			if ( check_ajax_referer( 'wpsc_set_edit_customer_info', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$customer_id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
			if ( ! $customer_id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$customer = new WPSC_Customer( $customer_id );
			if ( ! $customer->id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			if ( ! $name ) {
				wp_send_json_error( new WP_Error( '002', 'Bad request!' ), 400 );
			}

			if ( $customer->name != $name ) {
				$customer->name = $name;
				$customer->save();
				// Update WP User if available.
				if ( $customer->user ) {
					wp_update_user(
						array(
							'ID'           => $customer->user->ID,
							'display_name' => $name,
						)
					);
				}
			}

			$cfs = array();
			foreach ( WPSC_Custom_Field::$custom_fields as $cf ) {

				if ( $cf->field !== 'customer' || $cf->type::$is_default ) {
					continue;
				}
				$cfs[ $cf->type::$slug ][] = $cf;
			}

			foreach ( $cfs as $slug => $fields ) {
				WPSC_Functions::$ref_classes[ $slug ]['class']::set_create_ticket_data( array( 'customer' => $customer->id ), $cfs, true );
			}
			$response = array(
				'customer_id' => $customer->id,
				'nonce'       => wp_create_nonce( 'wpsc_view_customer_detailed_info' ),
			);
			wp_send_json( $response );
		}

		/**
		 * View customer logs
		 *
		 * @return void
		 */
		public static function view_customer_logs() {

			if ( check_ajax_referer( 'wpsc_view_customer_logs', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;
			if ( ! $customer_id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$customer = new WPSC_Customer( $customer_id );
			if ( ! $customer->id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$title     = esc_attr__( 'Customer logs', 'supportcandy' );
			$unique_id = uniqid();

			ob_start();
			$logs = WPSC_Log::find(
				array(
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'slug'    => 'type',
							'compare' => '=',
							'val'     => 'customer',
						),
						array(
							'slug'    => 'ref_id',
							'compare' => '=',
							'val'     => $customer->id,
						),
					),
				)
			)['results'];
			?>
			<div style="width: 100%;">
				<?php
				foreach ( $logs as $log ) {
					self::print_log( $log );
				}
				?>
			</div>
			<?php

			do_action( 'wpsc_view_customer_info', $customer );

			$body = ob_get_clean();

			ob_start();
			?>
			<button class="wpsc-button small secondary" onclick="wpsc_close_modal();">
				<?php esc_attr_e( 'Close', 'supportcandy' ); ?>
			</button>
			<?php
			do_action( 'wpsc_view_customer_logs_footer', $customer );
			$footer = ob_get_clean();

			$response = array(
				'title'  => $title,
				'body'   => $body,
				'footer' => $footer,
			);
			wp_send_json( $response );
		}

		/**
		 * Print customer log
		 *
		 * @param WPSC_Log $log - log object.
		 * @return void
		 */
		public static function print_log( $log ) {

			$advanced      = get_option( 'wpsc-ms-advanced-settings', array() );
			$now           = new DateTime();
			$date          = $log->date_created->setTimezone( wp_timezone() );
			$time_date_str = $date->format( $advanced['thread-date-format'] );
			$time_diff_str = WPSC_Functions::date_interval_highest_unit_ago( $date->diff( $now ) );
			$title         = $advanced['thread-date-display-as'] == 'date' ? $time_diff_str : $time_date_str;
			$time_str      = $advanced['thread-date-display-as'] == 'date' ? $time_date_str : $time_diff_str;

			$body     = json_decode( $log->body );
			$customer = new WPSC_Customer( $log->modified_by );

			$cf = WPSC_Custom_Field::get_cf_by_slug( $body->slug );
			if ( ! $cf ) {
				return;
			}
			?>
			<div class="wpsc-thread log">
				<div class="thread-avatar">
					<?php
					if ( $log->modified_by ) {
						echo get_avatar( $customer->email, 32 );
					} else {
						WPSC_Icons::get( 'system' );
					}
					?>
				</div>
				<div class="thread-body">
					<div class="thread-header">
						<div class="user-info">
							<div>
								<?php
								if ( $log->modified_by ) {
									printf(
										/* translators: %1$s: User Name, %2$s: Field Name */
										esc_attr__( '%1$s changed the %2$s', 'supportcandy' ),
										'<strong>' . esc_attr( $customer->name ) . '</strong>',
										'<strong>' . esc_attr( $cf->name ) . '</strong>'
									);
								} else {
									printf(
										/* translators: %1$s: Field Name */
										esc_attr__( 'The %1$s has been changed', 'supportcandy' ),
										'<strong>' . esc_attr( $cf->name ) . '</strong>'
									);
								}

								?>
							</div>
							<span class="thread-time" title="<?php echo esc_attr( $title ); ?>"><?php echo esc_attr( $time_str ); ?></span>
						</div>
					</div>
					<div class="wpsc-log-diff">
						<div class="lhs"><?php $cf->type::print_val( $cf, $body->prev ); ?></div>
						<div class="transform-icon">
							<?php
							if ( is_rtl() ) {
								WPSC_Icons::get( 'arrow-left' );
							} else {
								WPSC_Icons::get( 'arrow-right' );
							}
							?>
						</div>
						<div class="rhs"><?php $cf->type::print_val( $cf, $body->new ); ?></div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Count customer tickets after create/delete/restore ticket
		 *
		 * @param WPSC_Ticket $ticket - ticket object.
		 * @return void
		 */
		public static function customer_ticket_count( $ticket ) {

			$ticket->customer->update_ticket_count();
		}

		/**
		 * Delete customer from.
		 *
		 * @return void
		 */
		public static function delete_customer_info() {

			if ( check_ajax_referer( 'wpsc_delete_customer', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;
			if ( ! $customer_id ) {
				wp_send_json_error( 'Bad Request', 400 );
			}

			$customer = new WPSC_Customer( $customer_id );
			if ( ! $customer->id ) {
				wp_send_json_error( 'Bad request', 400 );
			}

			// Make agent as a anonymous customer.
			$agent = WPSC_Agent::get_by_customer( $customer );
			if ( $agent->id ) {

				if ( $agent->role == 1 ) {
					wp_send_json_error( 'Unauthorised request!', 401 );
				}

				$agent->customer = WPSC_Functions::anonymous_customer();
				$agent->is_active = 0;
				$agent->save();

				// Remove agent capability.
				if ( $agent->has_cap( 'backend-access' ) ) {
					$agent->user->remove_cap( 'wpsc_agent' );
				}

				do_action( 'wpsc_delete_agent', $agent );
			}

			self::delete_customer_tickets( $customer_id );

			WPSC_Customer::destroy( $customer );
			do_action( 'wpsc_delete_customer', $customer );
			wp_die();
		}

		/**
		 * Delete customer tickets
		 *
		 * @param int $customer_id - customer_id.
		 * @return void
		 */
		public static function delete_customer_tickets( $customer_id ) {

			global $wpdb;

			// Delete threads if records is exists.
			$td_sql = "DELETE thrd FROM wp_psmsc_threads AS thrd LEFT JOIN wp_psmsc_tickets AS t ON thrd.ticket = t.id WHERE t.customer = $customer_id";
			$wpdb->query( $td_sql );

			// is active 0 attachment if records is exists.
			$td_sql = "UPDATE wp_psmsc_attachments AS att LEFT JOIN wp_psmsc_tickets AS t ON att.ticket_id = t.id SET att.is_active = 0 WHERE t.customer = $customer_id";
			$wpdb->query( $td_sql );

			// Delete sla log records if log records is exists.
			if ( $wpdb->get_var( "SHOW TABLES LIKE 'wp_psmsc_sla_logs'" ) ) {
				$sl_sql = "DELETE sl FROM wp_psmsc_sla_logs AS sl LEFT JOIN wp_psmsc_tickets AS t ON sl.ticket = t.id WHERE t.customer = $customer_id";
				$wpdb->query( $sl_sql );
			}

			// Delete timer log records if log records is exists.
			if ( $wpdb->get_var( "SHOW TABLES LIKE 'wp_psmsc_timer_logs'" ) ) {
				$tl_sql = "DELETE tl FROM wp_psmsc_timer_logs AS tl LEFT JOIN wp_psmsc_tickets AS t ON tl.ticket = t.id WHERE t.customer = $customer_id";
				$wpdb->query( $tl_sql );
			}

			// Delete tickets if ticket records is exists.
			$td_sql = "DELETE FROM wp_psmsc_tickets WHERE customer = $customer_id";
			$wpdb->query( $td_sql );
		}

		/**
		 * Get customer profile details
		 *
		 * @return void
		 */
		public static function view_customer_detailed_info() {

			if ( check_ajax_referer( 'wpsc_view_customer_detailed_info', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;
			if ( ! $customer_id ) {
				wp_send_json_error( 'Bad Request', 400 );
			}

			$customer = new WPSC_Customer( $customer_id );
			if ( ! $customer->id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}
			$agent = WPSC_Agent::get_by_customer( $customer );
			?>
			<!-- User profile starts -->
			<div class="wpsc-back-button">
				<a class="wpsc-link" onclick="location.reload()"><?php esc_attr_e( 'Back', 'supportcandy' ); ?></a>
				<?php
				if ( ! $agent->id || ( $agent->role != 1 ) ) {
					?>
					| <a href="javascript:wpsc_delete_customer(<?php echo esc_attr( $customer->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_delete_customer' ) ); ?>')" class="wpsc-link">
						<?php esc_attr_e( 'Delete', 'supportcandy' ); ?>
					</a>
					<?php
				}
				?>
			</div>
			<div class="wpsc-up-section">
				<main>
					<div class="wpsc-up-container">
						<div class="wpsc-up-picture img">
							<?php echo get_avatar( $customer->email, 50 ); ?>
						</div>
						<div class="wpsc-up-info">
							<div class="wpsc-up-name"><?php echo esc_attr( $customer->name ); ?></div>
							<div class="wpsc-up-email"><?php echo esc_attr( $customer->email ); ?></div>
						</div>
					</div>
					<!-- Tabs -->
					<div class="wpsc-up-tab">
						<?php do_action( 'wpsc_add_before_up_tab' ); ?>
						<label class="wpsc-profile-tab active" data-toggle-target="wpsc-up-tickets"><?php esc_attr_e( 'Tickets', 'suuportcandy' ); ?></label>
						<label class="wpsc-profile-tab" data-toggle-target="wpsc-up-cf"><?php esc_attr_e( 'Custom fields', 'suuportcandy' ); ?></label>
						<label class="wpsc-profile-tab" data-toggle-target="wpsc-up-other"><?php esc_attr_e( 'Other', 'suuportcandy' ); ?></label>
						<?php do_action( 'wpsc_add_after_up_tab' ); ?>
					</div>

					<!-- Tab Content -->
					<section>
						<?php do_action( 'wpsc_add_before_up_tab_content' ); ?>
						<div class="wpsc-up-content wpsc-up-tickets active">
							<?php self::get_customers_ticket_list( $customer ); ?>
						</div>
						<div class="wpsc-up-content wpsc-up-cf">
							<?php self::get_customers_customer_fields( $customer ); ?>
						</div>
						<div class="wpsc-up-content wpsc-up-other">
							<?php self::get_customers_other_info( $customer ); ?>
						</div>
						<?php do_action( 'wpsc_add_after_up_tab_content' ); ?>
					</section>
				</main>
			</div>
			<!-- User profile ends -->

			<script>
				jQuery('.wpsc-profile-tab').on('click', function(event) {

					event.preventDefault();
					if ( jQuery(this).hasClass('active') ) return;

					jQuery(this).toggleClass('active');
					var temp = jQuery(this).data('toggle-target');
					jQuery('.wpsc-up-content').removeClass('active').filter('.'+temp).addClass('active');
					jQuery('.wpsc-profile-tab').not(this).removeClass('active');
				});
			</script>
			<?php
			wp_die();
		}

		/**
		 * Get customer's individual tickets
		 *
		 * @param int $customer - customer id.
		 * @return void
		 */
		public static function get_customers_ticket_list( $customer ) {

			$list_items = get_option( 'wpsc-ctl-list-items' );
			?>
			<table class="my-profile-ticket-list wpsc-setting-tbl">
				<thead>
					<tr>
						<?php
						foreach ( $list_items as $slug ) :
							$cf = WPSC_Custom_Field::get_cf_by_slug( $slug );
							if ( ! $cf ) {
								continue;
							}
							?>
							<th style="min-width: <?php echo esc_attr( $cf->tl_width ); ?>px;"><?php echo esc_attr( $cf->name ); ?></th>
							<?php
						endforeach;
						?>
					</tr>
				</thead>
				<tbody>
					<?php
					$filters = array(
						'items_per_page' => 0,
						'orderby'        => 'id',
						'order'          => 'DESC',
						'meta_query'     => array(
							'relation' => 'AND',
							array(
								'slug'    => 'customer',
								'compare' => '=',
								'val'     => $customer->id,
							),
						),
					);
					$tickets = WPSC_Ticket::find( $filters )['results'];
					if ( $tickets ) {
						foreach ( $tickets as $ticket ) {
							?>
							<tr>
							<?php
							foreach ( $list_items as $slug ) {
								$cf = WPSC_Custom_Field::get_cf_by_slug( $slug );
								if ( ! $cf ) {
									continue;
								}
								?>
								<td onmouseover="link=true;">
									<?php
									if ( in_array( $cf->field, array( 'ticket', 'agentonly' ) ) ) {
										$cf->type::print_tl_ticket_field_val( $cf, $ticket );
									} else {
										$cf->type::print_tl_customer_field_val( $cf, $ticket->customer );
									}
									?>
								</td>
								<?php
							}
							?>
							</tr>
							<?php
						}
					}
					?>
				</tbody>
			</table>
			<script>
				jQuery(document).ready(function() {

					jQuery('.my-profile-ticket-list').dataTable({
						'bDestroy': true,
						'searching': false,
						'ordering': false,
						'bLengthChange': false,
						pageLength: 10,
						columnDefs: [ 
							{ targets: '_all', className: 'dt-left' },
						],
						language: supportcandy.translations.datatables
					});
				});
			</script>
			<?php
		}

		/**
		 * Get customer's custom fields
		 *
		 * @param int $customer - customer id.
		 * @return void
		 */
		public static function get_customers_customer_fields( $customer ) {
			?>
			<form action="#" onsubmit="return false;" class="frm-edit-customer-info">
				<?php
				$cf = WPSC_Custom_Field::get_cf_by_slug( 'name' );
				foreach ( WPSC_Custom_Field::$custom_fields as $cf ) {
					if ( $cf->field !== 'customer' || in_array( $cf->slug, WPSC_DF_Customer::$ignore_customer_info_cft ) ) {
						continue;
					}
					$properties = array(
						'is-required' => 0,
						'width'       => 'full',
						'visibility'  => '',
					);
					echo $cf->type::print_edit_customer_info( $cf, $customer, $properties ); // phpcs:ignore
				}
				do_action( 'wpsc_get_edit_customer_info_body', $customer );
				?>
				<input type="hidden" name="action" value="wpsc_set_edit_customer_info"/>
				<input type="hidden" name="id" value="<?php echo esc_attr( $customer->id ); ?>">
				<input type="hidden" name="name" value="<?php echo esc_attr( $customer->name ); ?>">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_edit_customer_info' ) ); ?>">
			</form>
			<button class="wpsc-button small primary" onclick="wpsc_set_edit_customer_info(this, <?php echo esc_attr( $customer->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_view_customer_info' ) ); ?>');">
				<?php esc_attr_e( 'Submit', 'supportcandy' ); ?>
			</button>
			<button class="wpsc-button small secondary" onclick="wpsc_view_customer_logs(<?php echo esc_attr( $customer->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_view_customer_logs' ) ); ?>')">
				<?php esc_attr_e( 'View logs', 'supportcandy' ); ?>
			</button>
			<?php
		}

		/**
		 * Get customer's other info
		 *
		 * @param int $customer - customer id.
		 * @return void
		 */
		public static function get_customers_other_info( $customer ) {

			do_action( 'wpsc_user_profile_widgets_before', $customer );
			?>
			<div class="wpsc-xs-6 wpsc-sm-6 wpsc-md-6 wpsc-lg-6">
				<div class="wpsc-it-widget wpsc-itw-add-rec">
					<div class="wpsc-widget-header">
						<h2><?php echo esc_attr__( 'Recent Activities', 'supportcandy' ); ?></h2>
						<span>
							<div class="info-list-item">
								<div class="info-label">
									<a href="<?php echo esc_url_raw( admin_url( 'admin.php?page=wpsc-recent-activities&cid=' . $customer->id ) ); ?>" target="__blank"><?php echo esc_attr__( 'View All', 'supportcandy' ); ?></a>
								</div>
							</div>
						</span>
					</div>
					<div class="wpsc-widget-body wpsc-widget-scroller">
						<div class="info-list-item">
							<div class="info-val fullwidth">
								<div id="wpsc-upw-recent-activities"></div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<script>
				wpsc_upw_get_recent_activities();
				function wpsc_upw_get_recent_activities() {
					jQuery('#wpsc-upw-recent-activities').html( supportcandy.loader_html );
					var data = { action: 'wpsc_upw_get_recent_activities', view: supportcandy.is_frontend, _ajax_nonce: supportcandy.nonce, customer_id: <?php echo esc_attr( $customer->id ); ?> };
					jQuery.post(
						supportcandy.ajax_url,
						data,
						function (response) {
							jQuery('#wpsc-upw-recent-activities').html(response.html);
						}
					);
				}
			</script>
			<?php
			do_action( 'wpsc_user_profile_widgets_after', $customer );
		}

		/**
		 * Get customer recent activities
		 *
		 * @return void
		 */
		public static function get_upw_recent_activities() {

			if ( check_ajax_referer( 'general', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( new WP_Error( '004', 'Unauthorized!' ), 401 );
			}

			$view = isset( $_POST['view'] ) ? sanitize_text_field( wp_unslash( $_POST['view'] ) ) : '0';

			$customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;
			if ( ! $customer_id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$customer = new WPSC_Customer( $customer_id );
			if ( ! $customer->id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$logs = WPSC_RA_Logs::get_activity_logs( 10, 1, $customer_id );
			$now = new DateTime();
			ob_start();
			?>
			<div class="wpsc-widget-list-section">
				<?php
				if ( $logs['total_items'] ) {
					foreach ( $logs['results'] as $log ) {

						$url = WPSC_Functions::get_ticket_url( $log->ticket->id, $view );
						$time_ago = WPSC_Functions::date_interval_highest_unit_ago( $log->date_created->diff( $now ) );

						if ( $log->type == 'report' ) {
							?>
						<div class="wpsc-widget-list"><?php echo esc_attr( $log->customer->name ) . ' ' . esc_attr__( 'created a ticket', 'supportcandy' ); ?> <a href="<?php echo esc_attr( $url ); ?>" target="_blank">#<?php echo esc_attr( $log->ticket->id ) . ' ' . esc_attr( $log->ticket->subject ); ?></a><?php echo esc_attr( ' (' . $time_ago . ')' ); ?></div>
							<?php
						} elseif ( $log->type == 'reply' ) {
							?>
							<div class="wpsc-widget-list"><?php echo esc_attr( $log->customer->name ) . ' ' . esc_attr__( 'replied to ticket', 'supportcandy' ); ?> <a href="<?php echo esc_attr( $url ); ?>" target="_blank">#<?php echo esc_attr( $log->ticket->id ); ?></a><?php echo esc_attr( ' (' . $time_ago . ')' ); ?></div>
							<?php
						} elseif ( $log->type == 'note' ) {
							?>
							<div class="wpsc-widget-list"><?php echo esc_attr( $log->customer->name ) . ' ' . esc_attr__( 'added a note to ticket', 'supportcandy' ); ?> <a href="<?php echo esc_attr( $url ); ?>" target="_blank">#<?php echo esc_attr( $log->ticket->id ); ?></a><?php echo esc_attr( ' (' . $time_ago . ')' ); ?></div>
							<?php
						} elseif ( $log->type == 'log' ) {
							if ( ! $log->customer ) {
								continue;
							}
							$body = json_decode( $log->body );
							$is_json = ( json_last_error() == JSON_ERROR_NONE ) ? true : false;
							if ( $is_json ) {
								$cf = WPSC_Custom_Field::get_cf_by_slug( $body->slug );
								if ( ! $cf ) {
									continue;
								}
								?>
								<div class="wpsc-widget-list"><?php echo wp_kses_post( $cf->type::print_activity( $cf, $log, $body, '0' ) ); ?><?php echo esc_attr( ' (' . $time_ago . ')' ); ?></div>
								<?php
							}
						}
					}
				} else {
					echo esc_attr__( 'Record not found!', 'supportcandy' );
				}
				?>
			</div>
			<?php
			$table = ob_get_clean();
			wp_send_json( array( 'html' => $table ) );
		}
	}
endif;

WPSC_Customers::init();
