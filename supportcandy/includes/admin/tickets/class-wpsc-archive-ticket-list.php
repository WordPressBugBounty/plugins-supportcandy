<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Archive_Ticket_List' ) ) :

	final class WPSC_Archive_Ticket_List {

		/**
		 * All default and saved filters for logged-in user
		 *
		 * @var array
		 */
		private static $at_filters;

		/**
		 * Default filters based on logged-in user
		 *
		 * @var array
		 */
		public static $default_at_filters;

		/**
		 * Saved filters of logged-in user
		 *
		 * @var array
		 */
		private static $saved_at_filters;

		/**
		 * Flag to check whether current filter is saved filter or not
		 *
		 * @var integer
		 */
		private static $saved_at_flag;

		/**
		 * Numeric type flag ID for current filter
		 *
		 * @var integer
		 */
		private static $at_filter_id = 0;

		/**
		 * Set if current screen is tickets page
		 *
		 * @var boolean
		 */
		public static $is_current_page;

		/**
		 * Sections for this view
		 *
		 * @var [type]
		 */
		private static $sections = array();

		/**
		 * Current section to load
		 *
		 * @var [type]
		 */
		public static $current_section;

		/**
		 * Total number of tickets found for $filters
		 *
		 * @var integer
		 */
		private static $total_items = 0;

		/**
		 * Total number of pages
		 *
		 * @var integer
		 */
		private static $total_pages = 0;

		/**
		 * Set if there is next page is available
		 *
		 * @var integer
		 */
		private static $has_next_page = false;

		/**
		 * More settings for agent/customer view based on logged-in user
		 *
		 * @var array
		 */
		public static $more_settings;

		/**
		 * Ticket list bulk actions
		 *
		 * @var array
		 */
		private static $bulk_actions = array();

		/**
		 * Ticket found for the filter
		 *
		 * @var array
		 */
		private static $tickets = array();

		/**
		 * Flag for current view
		 *
		 * @var integer
		 */
		private static $is_frontend = 0;

		/**
		 * Flag to check whether current filter is numeric default filter or not
		 *
		 * @var integer
		 */
		private static $default_at_flag;

		/**
		 * Cookie filters to be sent to JS with tickets
		 *
		 * @var array
		 */
		private static $cookie_filters;

		/**
		 * Set whether tickets to be queried is active or deleted
		 *
		 * @var integer
		 */
		public static $is_active = 1;

		/**
		 * Initialize the class
		 *
		 * @return void
		 */
		public static function init() {

			// Load sections for this screen.
			add_action( 'admin_init', array( __CLASS__, 'load_sections' ) );

			// Add current section to admin localization data.
			add_filter( 'wpsc_admin_localizations', array( __CLASS__, 'localizations' ) );

			// Humbargar modal.
			add_action( 'admin_footer', array( __CLASS__, 'humbargar_menu' ) );

			// JS dynamic functions.
			add_action( 'wpsc_js_ready', array( __CLASS__, 'register_js_ready_function' ) );

			// Get tickets ajax request.
			add_action( 'wp_ajax_wpsc_get_archive_tickets', array( __CLASS__, 'get_archive_tickets' ) );
			add_action( 'wp_ajax_nopriv_wpsc_get_archive_tickets', array( __CLASS__, 'get_archive_tickets' ) );
			add_action( 'wp_ajax_wpsc_get_atl_custom_filter', array( __CLASS__, 'get_atl_custom_filter_ui' ) );
			add_action( 'wp_ajax_nopriv_wpsc_get_atl_custom_filter', array( __CLASS__, 'get_atl_custom_filter_ui' ) );

			// Get ticket list ajax request.
			add_action( 'wp_ajax_wpsc_get_archive_ticket_list', array( __CLASS__, 'archive_ticket_list' ) );
			add_action( 'wp_ajax_nopriv_wpsc_get_archive_ticket_list', array( __CLASS__, 'archive_ticket_list' ) );

			add_action( 'wp_ajax_wpsc_bulk_delete_archive_tickets', array( __CLASS__, 'bulk_delete_archive_tickets' ) );
			add_action( 'wp_ajax_nopriv_wpsc_bulk_delete_archive_tickets', array( __CLASS__, 'bulk_delete_archive_tickets' ) );
			add_action( 'wp_ajax_wpsc_bulk_restore_archive_tickets', array( __CLASS__, 'bulk_restore_archive_tickets' ) );
			add_action( 'wp_ajax_nopriv_wpsc_bulk_restore_archive_tickets', array( __CLASS__, 'bulk_restore_archive_tickets' ) );
		}

		/**
		 * Load ticket list
		 *
		 * @return void
		 */
		public static function archive_ticket_list() {
			self::load_tickets();
			self::set_bulk_actions();
			self::get_filters();
			self::get_ticket_list();
			self::print_tl_snippets();
			wp_die();
		}

		/**
		 * Ajax callback for get_archive_tickets on ticket list with filters and page
		 *
		 * @return void
		 */
		public static function get_archive_tickets() {

			if ( check_ajax_referer( 'general', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			self::load_tickets();
			self::set_bulk_actions();
			$filters = self::$cookie_filters;
			$filters['filters'] = isset( $filters['filters'] ) ? $filters['filters'] : '[]';

			$response = array(
				'tickets'        => self::print_tickets(),
				'filter_actions' => self::get_filter_actions(),
				'bulk_actions'   => self::get_bulk_actions(),
				'pagination_str' => self::get_pagination_str(),
				'pagination'     => array(
					'current_page'  => self::$at_filters['page_no'],
					'has_next_page' => self::$has_next_page,
					'total_pages'   => self::$total_pages,
					'total_items'   => self::$total_items,
				),
				'filters'        => $filters,
			);
			wp_send_json( $response );
		}

		/**
		 * Print ticket filters layout
		 *
		 * @return void
		 */
		private static function get_filters() {

			$current_user  = WPSC_Current_User::$current_user;
			$custom_fields = WPSC_Custom_Field::$custom_fields;
			$list_items    = $current_user->get_tl_list_items();

			?>
			<div class="wpsc-filter">
				<div class="wpsc-search">
					<div class="search-field">
						<?php WPSC_Icons::get( 'search' ); ?>
						<input class="wpsc-search-input" type="text" placeholder="<?php esc_attr_e( 'Search...', 'supportcandy' ); ?>" spellcheck="false" value="<?php echo esc_attr( stripslashes( self::$at_filters['search'] ) ); ?>" onkeyup="wpsc_tl_search_keyup(event, this, 'archive_ticket_list');"/>
					</div>
				</div>
				<div class="wpsc-filter-container">
					<div class="wpsc-filter-item">
						<label for="wpsc-input-filter"><?php esc_attr_e( 'Filter', 'supportcandy' ); ?></label>
						<select id="wpsc-input-filter" class="wpsc-input-filter" name="filter" onchange="wpsc_tl_filter_change(this, 'archive_ticket_list');">
							<option <?php selected( self::$at_filters['filterSlug'], 'all' ); ?> value="all"><?php esc_attr_e( 'All', 'supportcandy' ); ?></option>
							<option <?php selected( self::$at_filters['filterSlug'], 'custom' ); ?> value="custom"><?php esc_attr_e( 'Custom...', 'supportcandy' ); ?></option>

						</select>
					</div>
					<div class="wpsc-filter-item">
						<label for="wpsc-input-sort-by"><?php esc_attr_e( 'Sort By', 'supportcandy' ); ?></label>
						<select id="wpsc-input-sort-by" class="wpsc-input-sort-by" name="sort-by">
							<?php
							foreach ( $list_items as $slug ) :
								$cf = WPSC_Custom_Field::get_cf_by_slug( $slug );
								if ( ! $cf || ! $cf->type::$is_sort ) {
									continue;
								}
								?>
								<option <?php selected( $cf->slug, self::$at_filters['orderby'] ); ?> value="<?php echo esc_attr( $cf->slug ); ?>"><?php echo esc_attr( $cf->name ); ?></option>
								<?php
							endforeach;
							?>
						</select>
					</div>
					<div class="wpsc-filter-item">
						<select id="wpsc-input-sort-order" class="wpsc-input-sort-order" name="sort-order">
							<option <?php selected( self::$at_filters['order'], 'ASC' ); ?> value="ASC"><?php esc_attr_e( 'ASC', 'supportcandy' ); ?></option>
							<option <?php selected( self::$at_filters['order'], 'DESC' ); ?> value="DESC"><?php esc_attr_e( 'DESC', 'supportcandy' ); ?></option>
						</select>
					</div>
					<div class="wpsc-filter-submit">
						<button class="wpsc-button normal primary margin-right" onclick="wpsc_tl_apply_filter_btn_click( 'archive_ticket_list' );"><?php esc_attr_e( 'Apply', 'supportcandy' ); ?></button>
						<div class="wpsc-filter-actions">
							<?php echo self::get_filter_actions(); // phpcs:ignore?> 
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Load tickets based on current filter
		 *
		 * @return void
		 */
		private static function load_tickets() {

			if ( check_ajax_referer( 'general', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_customer ) {
				wp_send_json_error( new WP_Error( '001', 'Unauthorized!' ), 400 );
			}

			self::$is_frontend = isset( $_POST['is_frontend'] ) ? sanitize_text_field( wp_unslash( $_POST['is_frontend'] ) ) : '0';

			$filters = null;

			// check whether filters are given in post.
			$filters = isset( $_POST['filters'] ) ? map_deep( wp_unslash( $_POST['filters'] ), 'sanitize_text_field' ) : array();
			// get filters from cookies.
			$tl_filters = isset( $_COOKIE['wpsc-atl-filters'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wpsc-atl-filters'] ) ) : '';

			// use cookie filter if filters are not given.
			$filters = $filters == null && $tl_filters ? json_decode( $tl_filters, true ) : $filters;

			self::$more_settings = get_option( $current_user->is_agent ? 'wpsc-tl-ms-agent-view' : 'wpsc-tl-ms-customer-view' );

			$current_user_filters = $current_user->get_tl_filters();
			self::$default_at_filters = $current_user_filters['default'];
			self::$saved_at_filters = $current_user_filters['saved'];

			if ( ! $filters || ! self::has_filter_access( $filters ) ) {
				$filters = array(
					'filterSlug' => 'all',
				);
				if ( isset( self::$default_at_filters[ $filters['filterSlug'] ] ) ) {
					$filters['orderby'] = self::$more_settings['default-sort-by'];
					$filters['order']   = self::$more_settings['default-sort-order'];
				}
			}

			self::$default_at_flag = preg_match( '/default-(\d*)$/', $filters['filterSlug'], $default_matches );
			if ( self::$default_at_flag ) {
				self::$at_filter_id = $default_matches[1];
			}

			self::$saved_at_flag = preg_match( '/saved-(\d*)$/', $filters['filterSlug'], $saved_matches );
			if ( self::$saved_at_flag ) {
				self::$at_filter_id = $saved_matches[1];
			}

			// Order by.
			if ( ! isset( $filters['orderby'] ) && ! self::$default_at_flag && isset( self::$default_at_filters[ $filters['filterSlug'] ] ) ) {
				$filters['orderby'] = self::$more_settings['default-sort-by'];
			}
			if ( ! isset( $filters['orderby'] ) && self::$default_at_flag ) {
				$filters['orderby'] = self::$default_at_filters[ self::$at_filter_id ]['sort-by'];
			}
			if ( ! isset( $filters['orderby'] ) && self::$saved_at_flag ) {
				$filters['orderby'] = self::$saved_at_filters[ self::$at_filter_id ]['sort-by'];
			}

			// Order.
			if ( ! isset( $filters['order'] ) && ! self::$default_at_flag && isset( self::$default_at_filters[ $filters['filterSlug'] ] ) ) {
				$filters['order'] = self::$more_settings['default-sort-order'];
			}
			if ( ! isset( $filters['order'] ) && self::$default_at_flag ) {
				$filters['order'] = self::$default_at_filters[ self::$at_filter_id ]['sort-order'];
			}
			if ( ! isset( $filters['order'] ) && self::$saved_at_flag ) {
				$filters['order'] = self::$saved_at_filters[ self::$at_filter_id ]['sort-order'];
			}

			// Search.
			$filters['search'] = isset( $filters['search'] ) ? sanitize_text_field( $filters['search'] ) : '';

			// Current page number.
			$filters['page_no'] = isset( $filters['page_no'] ) ? intval( $filters['page_no'] ) : 1;

			// Set cookie here so that front-end should have access to filters until here only.
			setcookie( 'wpsc-atl-filters', wp_json_encode( $filters ), time() + 3600 );
			self::$cookie_filters = $filters;

			$filters['items_per_page'] = self::$more_settings['number-of-tickets'];

			// System query.
			$filters['system_query'] = $current_user->get_atl_system_query( $filters );

			// Meta query.
			$meta_query = array( 'relation' => 'AND' );

			if (
				isset( self::$default_at_filters[ $filters['filterSlug'] ] ) ||
				( self::$default_at_flag && isset( self::$default_at_filters[ self::$at_filter_id ] ) ) ||
				( self::$saved_at_flag && isset( self::$saved_at_filters[ self::$at_filter_id ] ) ) ||
				$filters['filterSlug'] == 'custom'
			) {

				$slug = self::$default_at_flag || self::$saved_at_flag ? self::$at_filter_id : '';
				if ( ! $slug ) {
					$slug = $filters['filterSlug'];
				}

				$parent_slug = is_numeric( $slug ) && self::$default_at_flag ? self::$default_at_filters[ $slug ]['parent-filter'] : '';
				if ( ! $parent_slug && is_numeric( $slug ) && self::$saved_at_flag ) {
					$parent_slug = self::$saved_at_filters[ $slug ]['parent-filter'];
				}
				if ( ! $parent_slug && $slug == 'custom' ) {
					$parent_slug = $filters['parent-filter'];
				}
				if ( ! $parent_slug ) {
					$parent_slug = $slug;
				}

				if ( self::$default_at_flag ) {
					$meta_query = array_merge( $meta_query, WPSC_Ticket_Conditions::get_meta_query( self::$default_at_filters[ $slug ]['filters'] ) );
				}

				if ( self::$saved_at_flag ) {
					$json_str = str_replace( PHP_EOL, '\n', self::$saved_at_filters[ $slug ]['filters'] );
					$meta_query = array_merge( $meta_query, WPSC_Ticket_Conditions::get_meta_query( $json_str, true ) );
				}

				if ( $filters['filterSlug'] == 'custom' ) {
					$meta_query = array_merge( $meta_query, WPSC_Ticket_Conditions::get_meta_query( $filters['filters'], true ) );
				}
			}

			$filters['meta_query'] = $meta_query;
			self::$at_filters         = apply_filters( 'wpsc_archived_ticket_list_filters', $filters );

			$response            = WPSC_Archive_Ticket::find( self::$at_filters );
			self::$tickets       = $response['results'];
			self::$total_items   = intval( $response['total_items'] );
			self::$total_pages   = intval( $response['total_pages'] );
			self::$has_next_page = $response['has_next_page'];
		}

		/**
		 * Check whether current user has access to given filter
		 *
		 * @param array $filters - ticket filters.
		 * @return boolean
		 */
		public static function has_filter_access( $filters ) {

			$current_user = WPSC_Current_User::$current_user;

			$filter_slug = isset( $filters['filterSlug'] ) ? $filters['filterSlug'] : '';

			if ( ! $filter_slug ) {
				return false;
			}

			if ( isset( self::$default_at_filters[ $filter_slug ] ) ) {
				return true;
			}

			$flag = preg_match( '/default-(\d*)$/', $filter_slug, $matches );
			if ( $flag && isset( self::$default_at_filters[ $matches[1] ] ) ) {
				return true;
			}

			$flag = preg_match( '/saved-(\d*)$/', $filter_slug, $matches );
			if ( $flag && isset( self::$saved_at_filters[ $matches[1] ] ) ) {
				return true;
			}

			if ( $filter_slug == 'custom' && WPSC_Ticket_Conditions::is_valid_input_conditions( 'wpsc_custom_filter_conditions', $filters['filters'] ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Print ticket list layout along with first page tickets
		 *
		 * @return void
		 */
		private static function get_ticket_list() {

			$current_user    = WPSC_Current_User::$current_user;
			$pagination_html = self::get_pagination_html();
			?>
			<div class="wpsc-tickets-container">
				<div class="wpsc-bulk-actions">
					<?php
					if ( $current_user->is_agent && self::$bulk_actions ) :
						?>
						<button 
							id="wpsc-bulk-actions-btn"
							class="wpsc-button small secondary"
							type="button"
							data-popover="wpsc-bulk-actions">
							<?php esc_attr_e( 'Bulk Actions', 'supportcandy' ); ?>
							<?php WPSC_Icons::get( 'chevron-down' ); ?>
						</button>
						<div id="wpsc-bulk-actions" class="gpopover wpsc-popover-menu wpsc-ticket-bulk-actions" >
							<?php
							echo self::get_bulk_actions(); // phpcs:ignore
							?>
						</div>
						<?php
					endif;
					$more_actions = apply_filters( 'wpsc_admin_atl_more_actions', array() );
					if ( is_array( $more_actions ) && ! empty( $more_actions ) ) {
						?>
						<button 
							id="wpsc-more-actions-btn"
							class="wpsc-button small secondary"
							type="button"
							data-popover="wpsc-more-actions">
							<?php esc_attr_e( 'List Actions', 'supportcandy' ); ?>
							<?php WPSC_Icons::get( 'chevron-down' ); ?>
						</button>
						<div id="wpsc-more-actions" class="gpopover wpsc-popover-menu">
							<?php
							foreach ( $more_actions as $action ) :
								?>
								<div class="wpsc-popover-menu-item" onclick="<?php echo esc_attr( $action['callback'] ) . '(\'' . esc_attr( wp_create_nonce( $action['callback'] ) ) . '\');'; ?>">
								<?php WPSC_Icons::get( $action['icon'] ); ?>
									<span><?php echo esc_attr( $action['label'] ); ?></span>
								</div>
								<?php
							endforeach;
							?>
						</div>
						<?php
					}
					?>
					<script>
						jQuery('#wpsc-more-actions-btn, #wpsc-bulk-actions-btn').gpopover({width: 200});
					</script>
					<div class="wpsc-ticket-pagination-header wpsc-hidden-xs">
						<?php echo $pagination_html; // phpcs:ignore?>
					</div>
				</div>

				<div class="wpsc-ticket-list">
					<?php echo self::print_tickets(); // phpcs:ignore?>
				</div>

				<div class="wpsc-ticket-pagination-footer">
					<?php echo $pagination_html; // phpcs:ignore?>
				</div>
			</div>
			<?php
		}

		/**
		 * Get tickets based on current filter
		 *
		 * @return string
		 */
		private static function print_tickets() {

			ob_start();
			$current_user = WPSC_Current_User::$current_user;
			$list_items   = $current_user->get_tl_list_items();
			?>

			<div style="overflow-x:auto;">
				<table class="wpsc-ticket-list-tbl">

					<thead>
						<tr>
							<?php
							if ( $current_user->is_agent && self::$bulk_actions ) :
								?>
								<th style="width: 45px;">
									<div class="checkbox-container">
										<?php $unique_id = uniqid( 'wpsc_' ); ?>
										<input id="<?php echo esc_attr( $unique_id ); ?>" class="wpsc-bulk-selector" type="checkbox" onchange="wpsc_bulk_select_change();"/>
										<label for="<?php echo esc_attr( $unique_id ); ?>"></label>
									</div>
								</th>
								<?php
							endif;
							do_action( 'wpsc_print_archive_ticket_list_th' );
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
						if ( self::$tickets ) {
							foreach ( self::$tickets as $ticket ) :
								$class = apply_filters( 'wpsc_ticket_list_tr_classes', array( 'wpsc_tl_tr' ), $ticket );
								$url = admin_url( 'admin.php?page=wpsc-archive-tickets&section=archive-ticket-list&id=' . $ticket->id );
								?>
								<tr class="<?php echo esc_attr( implode( ' ', $class ) ); ?>" onclick="if(link)wpsc_tl_handle_click(event, <?php echo esc_attr( $ticket->id ); ?>, '<?php echo esc_url( $url ); ?>')">
									<?php
									if ( $current_user->is_agent && self::$bulk_actions ) :
										?>
										<td class="bulk-selector" onmouseover="link=false;" onmouseout="link=true;">
											<div class="wpsc-tl-item-selector">
												<div class="checkbox-container">
													<?php $unique_id = uniqid( 'wpsc_' ); ?>
													<input id="<?php echo esc_attr( $unique_id ); ?>" class="wpsc-bulk-select" type="checkbox" onchange="wpsc_bulk_item_select_change();" value="<?php echo esc_attr( $ticket->id ); ?>"/>
													<label for="<?php echo esc_attr( $unique_id ); ?>"></label>
												</div>
											</div>
										</td>
										<?php
									endif;
									do_action( 'wpsc_print_archive_ticket_list_td', $ticket );
									foreach ( $list_items as $slug ) :
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
									endforeach;
									?>
								</tr>
								<?php
							endforeach;
						} else {
							$col_span = count( $list_items );
							if ( $current_user->is_agent && self::$bulk_actions ) {
								++$col_span;
							}
							?>
							<tr><td colspan="<?php echo esc_attr( $col_span ); ?>" style="text-align:left;"><?php esc_attr_e( 'No tickets found!', 'supportcandy' ); ?></td></tr>
							<?php
						}
						?>
					</tbody>
				</table>
			</div>
			<script>
				function wpsc_tl_handle_click(event, id, url) {
					if ( ( event.ctrlKey || event.metaKey ) && url ) {
						window.open(url, '_blank');
					} else {
						wpsc_get_individual_archive_ticket(id);
					}
				}
			</script>
			<?php
			return ob_get_clean();
		}

		/**
		 * Ticket list snippets. It may include static modal screens, JS initializers, etc.
		 *
		 * @return void
		 */
		public static function print_tl_snippets() {

			$filters = self::$cookie_filters;
			$filter_json = isset( $filters['filters'] ) && $filters['filters'] ? $filters['filters'] : '[]';
			$filters['filters'] = $filter_json;

			$ticket_list_js_vars = array(
				'pagination' => array(
					'current_page'  => self::$at_filters['page_no'],
					'has_next_page' => self::$has_next_page,
					'total_pages'   => self::$total_pages,
					'total_items'   => self::$total_items,
				),
				'filters'    => $filters,
			);
			?>

			<div class="wpsc-tl_snippets" style="display:none;">
				<script>
					supportcandy.ticketList = <?php echo wp_json_encode( $ticket_list_js_vars ); ?>;
				</script>
				<?php do_action( 'wpsc_tl_snippets' ); ?>
			</div>
			<?php
		}

		/**
		 * Return HTML content of pagination section
		 */
		private static function get_pagination_html() {

			$pagination_str = self::get_pagination_str();
			$btn_style      = self::$total_items <= self::$more_settings['number-of-tickets'] ? 'display:none;' : '';
			ob_start();
			?>
			<span 
				class="wpsc-pagination-btn wpsc-pagination-first wpsc-link"
				style="<?php echo esc_attr( $btn_style ); ?>"
				onclick="wpsc_tl_set_page('first', 'archive_ticket_list');">
				<?php esc_attr_e( 'First Page', 'supportcandy' ); ?>
			</span>
			<?php
			if ( is_rtl() ) {
				?>
				<span 
					class="wpsc-pagination-btn wpsc-pagination-next wpsc-link"
					style="<?php echo esc_attr( $btn_style ); ?>"
					onclick="wpsc_tl_set_page('next', 'archive_ticket_list');">
					<?php WPSC_Icons::get( 'chevron-right' ); ?>
				</span>
				<?php
			} else {
				?>
				<span 
					class="wpsc-pagination-btn wpsc-pagination-prev wpsc-link"
					style="<?php echo esc_attr( $btn_style ); ?>"
					onclick="wpsc_tl_set_page('prev', 'archive_ticket_list');">
					<?php WPSC_Icons::get( 'chevron-left' ); ?>
				</span>
				<?php
			}
			?>
			<span class="wpsc-pagination-txt"><?php echo esc_attr( $pagination_str ); ?></span>
			<?php
			if ( is_rtl() ) {
				?>
				<span 
					class="wpsc-pagination-btn wpsc-pagination-prev wpsc-link"
					style="<?php echo esc_attr( $btn_style ); ?>"
					onclick="wpsc_tl_set_page('prev', 'archive_ticket_list');">
					<?php WPSC_Icons::get( 'chevron-left' ); ?>
				</span>
				<?php
			} else {
				?>
				<span 
					class="wpsc-pagination-btn wpsc-pagination-next wpsc-link"
					style="<?php echo esc_attr( $btn_style ); ?>"
					onclick="wpsc_tl_set_page('next', 'archive_ticket_list');">
					<?php WPSC_Icons::get( 'chevron-right' ); ?>
				</span>
				<?php
			}
			?>
			<span 
				class="wpsc-pagination-btn wpsc-pagination-last wpsc-link"
				style="<?php echo esc_attr( $btn_style ); ?>"
				onclick="wpsc_tl_set_page('last', 'archive_ticket_list');">
				<?php esc_attr_e( 'Last Page', 'supportcandy' ); ?>
			</span>
			<?php
			return ob_get_clean();
		}

		/**
		 * Pagination string
		 *
		 * @return string
		 */
		private static function get_pagination_str() {

			if ( self::$total_items < 1 ) {
				return '';
			}

			if ( self::$total_items == 1 ) {
				return esc_attr__( '1 Ticket', 'supportcandy' );
			}

			if ( self::$total_items <= self::$more_settings['number-of-tickets'] ) {
				/* translators: %1$s: total tickets */
				return sprintf( esc_attr__( '%1$d Tickets', 'supportcandy' ), self::$total_items );
			}

			$from = ( self::$more_settings['number-of-tickets'] * ( self::$at_filters['page_no'] - 1 ) ) + 1;
			$to   = self::$at_filters['page_no'] == self::$total_pages ?
					self::$total_items :
					self::$more_settings['number-of-tickets'] * self::$at_filters['page_no'];

			/* translators: e.g. 1-20 of 100 Tickets */
			return sprintf( esc_attr__( '%1$d-%2$d of %3$d Tickets', 'supportcandy' ), $from, $to, self::$total_items );
		}

		/**
		 * Set bulk actions for current user
		 */
		public static function set_bulk_actions() {

			$current_user = WPSC_Current_User::$current_user;
			$bulk_actions = array();

			if ( $current_user->is_agent && $current_user->agent->has_cap( 'at-delete-access' ) ) {
				$bulk_actions['delete'] = array(
					'icon'     => 'trash-alt',
					'label'    => esc_attr__( 'Delete Permanently', 'supportcandy' ),
					'callback' => 'wpsc_bulk_delete_archive_tickets',
				);
			}

			if ( $current_user->is_agent && $current_user->agent->has_cap( 'at-access' ) ) {
				$bulk_actions['restore'] = array(
					'icon'     => 'archive-restore',
					'label'    => esc_attr__( 'Restore', 'supportcandy' ),
					'callback' => 'wpsc_bulk_restore_archive_tickets',
				);
			}

			$bulk_actions = apply_filters( 'wpsc_atl_bulk_actions', $bulk_actions );
			self::$bulk_actions = $bulk_actions;
		}

		/**
		 * Agent ticket access for cap
		 *
		 * @param string $cap - capability type.
		 * @return boolean
		 */
		public static function has_ticket_cap( $cap ) {

			$current_user = WPSC_Current_User::$current_user;
			$flag         = false;
			if (
				$current_user->agent->has_cap( $cap . '-unassigned' ) ||
				$current_user->agent->has_cap( $cap . '-assigned-me' ) ||
				$current_user->agent->has_cap( $cap . '-assigned-others' )
			) {
				$flag = true;
			}

			return apply_filters( 'wpsc_bulk_has_archive_ticket_cap', $flag );
		}

		/**
		 * Get Bulk actions
		 *
		 * @return string
		 */
		public static function get_bulk_actions() {

			$current_user = WPSC_Current_User::$current_user;
			$actions_arr = array();
			foreach ( self::$bulk_actions as $action ) :
				ob_start();
				?>
				<div class="wpsc-popover-menu-item" onclick="<?php echo esc_attr( $action['callback'] ) . '(\'' . esc_attr( wp_create_nonce( $action['callback'] ) ) . '\');'; ?>">
					<?php WPSC_Icons::get( $action['icon'] ); ?>
					<span><?php echo esc_attr( $action['label'] ); ?></span>
				</div>
				<?php
				$actions_arr[] = ob_get_clean();
			endforeach;

			return implode( '', $actions_arr );
		}

		/**
		 * Get filter actions based on current filter applied
		 *
		 * @return string
		 */
		private static function get_filter_actions() {

			$actions = array(
				'reset' => array(
					'label'    => esc_attr__( 'Reset', 'supportcandy' ),
					'callback' => 'wpsc_tl_reset_filter',
				),
			);

			if ( self::$saved_at_flag || self::$at_filters['filterSlug'] == 'custom' ) {
				$actions['edit'] = array(
					'label'    => esc_attr__( 'Edit', 'supportcandy' ),
					'callback' => 'wpsc_tl_edit_filter',
				);
			}

			$actions_arr = array();
			foreach ( $actions as $key => $action ) :
				ob_start();
				?>
				<span 
					class="wpsc-link"
					onclick="<?php echo esc_attr( $action['callback'] ) . '( \'archive_ticket_list\' );'; ?>">
					<?php echo esc_attr( $action['label'] ); ?>
				</span>
				<?php
				$actions_arr[] = ob_get_clean();
			endforeach;

			return implode( '<div class="action-devider"></div>', $actions_arr );
		}

		/**
		 * UI foundation for this screen
		 *
		 * @return void
		 */
		public static function layout() {

			?>
			<div class="wrap">
				<hr class="wp-header-end">
				<div id="wpsc-container" style="display:none;">
					<?php do_action( 'wpsc_before_tickets_page' ); ?>
					<div class="wpsc-shortcode-container">
						<div class="wpsc-header wpsc-hidden-xs">
							<?php
							foreach ( self::$sections as $key => $section ) :
								$active = self::$current_section === $key ? 'active' : '';
								?>
								<div class="wpsc-menu-list wpsc-tickets-nav <?php echo esc_attr( $key ) . ' ' . esc_attr( $active ); ?>" onclick="<?php echo esc_attr( $section['callback'] ) . '();'; ?>">
									<?php WPSC_Icons::get( $section['icon'] ); ?>
									<label><?php echo esc_attr( $section['label'] ); ?></label>
								</div>
								<?php
							endforeach;
							?>
						</div>
						<div class="wpsc-header wpsc-visible-xs">
							<div class="wpsc-humbargar-title">
								<?php WPSC_Icons::get( self::$sections[ self::$current_section ]['icon'] ); ?>
								<label><?php echo esc_attr( self::$sections[ self::$current_section ]['label'] ); ?></label>
							</div>
							<div class="wpsc-humbargar" onclick="wpsc_toggle_humbargar();">
								<?php WPSC_Icons::get( 'bars' ); ?>
							</div>
						</div>
						<div class="wpsc-body"></div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Add localizations to local JS
		 *
		 * @param array $localizations - localization list.
		 * @return array
		 */
		public static function localizations( $localizations ) {

			if ( ! self::$is_current_page ) {
				return $localizations;
			}

			// Humbargar Titles.
			$localizations['humbargar_titles'] = self::get_humbargar_titles();

			// Current section.
			$localizations['current_section'] = self::$current_section;

			// Current archived ticket id.
			if ( self::$current_section === 'archive-ticket-list' && isset( $_REQUEST['id'] ) ) { // phpcs:ignore
				$localizations['current_archived_ticket_id'] = intval( $_REQUEST['id'] ); // phpcs:ignore
			}

			return $localizations;
		}

		/**
		 * Load section (nav elements) for this screen
		 *
		 * @return void
		 */
		public static function load_sections() {

			self::$is_current_page = isset( $_REQUEST['page'] ) && $_REQUEST['page'] === 'wpsc-archive-tickets' ? true : false; // phpcs:ignore

			$current_user = WPSC_Current_User::$current_user;
			if ( ! ( $current_user->is_agent && self::$is_current_page ) ) {
				return;
			}

			// get default tab.
			$gs = get_option( 'wpsc-gs-general' );
			$ms = get_option( 'wpsc-ms-advanced-settings' );

			// allow create ticket.
			$allow_create_ticket = in_array( $current_user->agent->role, $gs['allow-create-ticket'] ) ? true : false;

			// archived ticket list.
			$sections = array();
			if ( $current_user->is_agent && $current_user->agent->has_cap( 'at-access' ) ) {
				$sections['archive-ticket-list'] = array(
					'slug'     => 'archived_ticket_list',
					'icon'     => 'archive',
					'label'    => esc_attr__( 'Archived Tickets', 'supportcandy' ),
					'callback' => 'wpsc_get_archive_ticket_list',
				);
			}

			self::$sections        = apply_filters( 'wpsc_archived_tickets_page_sections', $sections );
			self::$current_section = 'archive-ticket-list';
		}

		/**
		 * Print humbargar menu in footer
		 *
		 * @return void
		 */
		public static function humbargar_menu() {

			if ( ! self::$is_current_page ) {
				return;
			}

			?>
			<div class="wpsc-humbargar-overlay" onclick="wpsc_toggle_humbargar();" style="display:none"></div>
			<div class="wpsc-humbargar-menu" style="display:none">
				<div class="box-inner">
					<div class="wpsc-humbargar-close" onclick="wpsc_toggle_humbargar();">
						<?php WPSC_Icons::get( 'times' ); ?>
					</div>
					<?php
					foreach ( self::$sections as $key => $section ) :
						$active = self::$current_section === $key ? 'active' : '';
						?>
						<div 
							class="wpsc-humbargar-menu-item <?php echo esc_attr( $key ) . ' ' . esc_attr( $active ); ?>"
							onclick="<?php echo esc_attr( $section['callback'] ) . '(true);'; ?>">
							<?php WPSC_Icons::get( $section['icon'] ); ?>
							<label><?php echo esc_attr( $section['label'] ); ?></label>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Custom filter modal pop-up
		 *
		 * @return void
		 */
		public static function get_atl_custom_filter_ui() {

			if ( check_ajax_referer( 'general', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$current_user = WPSC_Current_User::$current_user;
			if ( ! ( $current_user->is_agent || $current_user->is_customer ) ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$title           = esc_attr__( 'Custom filter', 'supportcandy' );
			$default_filters = get_option( $current_user->is_agent ? 'wpsc-atl-default-filters' : 'wpsc-ctl-default-filters' );
			$list_items      = $current_user->get_tl_list_items();
			$more_settings   = get_option( $current_user->is_agent ? 'wpsc-tl-ms-agent-view' : 'wpsc-tl-ms-customer-view' );

			// check whether filters are passed.
			$filters = isset( $_POST['filters'] ) ? map_deep( wp_unslash( $_POST['filters'] ), 'sanitize_text_field' ) : array();
			$custom_filters = isset( $filters['filters'] ) && WPSC_Ticket_Conditions::is_valid_input_conditions( 'wpsc_custom_filter_conditions', $filters['filters'] ) ? $filters['filters'] : '';

			ob_start();
			?>
			<form action="#" onsubmit="return false;" class="wpsc-atl-custom-filter">
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="">
							<?php esc_attr_e( 'Parent filter', 'supportcandy' ); ?>
							<span class="required-char">*</span>
						</label>
					</div>
					<select name="parent-filter">
						<?php
						foreach ( $default_filters as $slug => $filter ) :
							$selected = isset( $filters['parent-filter'] ) && $filters['parent-filter'] == $slug ? 'selected="selected"' : ''
							?>
							<option <?php echo esc_attr( $selected ); ?> value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_attr( $filter['label'] ); ?></option>
							<?php
						endforeach;
						?>
					</select>
				</div>
				<?php WPSC_Ticket_Conditions::print( 'custom_filters', 'wpsc_custom_filter_conditions', $custom_filters, true, __( 'Filters', 'supportcandy' ) ); ?>
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="">
							<?php esc_attr_e( 'Sort by', 'supportcandy' ); ?>
							<span class="required-char">*</span>
						</label>
					</div>
					<select name="sort-by">
						<?php
						$orderby = isset( $filters['orderby'] ) ? $filters['orderby'] : $more_settings['default-sort-by'];
						foreach ( $list_items as $slug ) :
							$cf = WPSC_Custom_Field::get_cf_by_slug( $slug );
							if ( ! $cf ) {
								continue;
							}
							if ( $cf->type::$is_sort ) :
								?>
								<option <?php selected( $orderby, $cf->slug ); ?> value="<?php echo esc_attr( $cf->slug ); ?>"><?php echo esc_attr( $cf->name ); ?></option>
								<?php
							endif;
						endforeach;
						?>
					</select>
				</div>
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for="">
							<?php esc_attr_e( 'Sort order', 'supportcandy' ); ?>
							<span class="required-char">*</span>
						</label>
					</div>
					<select name="sort-order">
						<?php
						$order = isset( $filters['order'] ) ? $filters['order'] : $more_settings['default-sort-order'];
						?>
						<option <?php selected( $order, 'ASC' ); ?> value="ASC"><?php esc_attr_e( 'ASC', 'supportcandy' ); ?></option>
						<option <?php selected( $order, 'DESC' ); ?> value="DESC"><?php esc_attr_e( 'DESC', 'supportcandy' ); ?></option>
					</select>
				</div>
			</form>
			<?php
			$body = ob_get_clean();

			ob_start();
			?>
			<button class="wpsc-button small primary" onclick="wpsc_tl_apply_custom_filter(this, 'archive_ticket_list');">
				<?php esc_attr_e( 'Apply', 'supportcandy' ); ?>
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
		 * Humbargar mobile titles to be used in localizations
		 *
		 * @return array
		 */
		private static function get_humbargar_titles() {

			$titles = array();
			foreach ( self::$sections as $section ) {

				ob_start();
				WPSC_Icons::get( $section['icon'] );
				echo '<label>' . esc_attr( $section['label'] ) . '</label>';
				$titles[ $section['slug'] ] = ob_get_clean();
			}
			return $titles;
		}

		/**
		 * Register JS functions to call on document ready
		 *
		 * @return void
		 */
		public static function register_js_ready_function() {

			if ( ! self::$is_current_page ) {
				return;
			}

			echo esc_attr( self::$sections[ self::$current_section ]['callback'] ) . '();' . PHP_EOL;
		}

		/**
		 * Delete ticket ajax request
		 */
		public static function bulk_delete_archive_tickets() {

			if ( check_ajax_referer( 'wpsc_bulk_delete_archive_tickets', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_agent || ! $current_user->agent->has_cap( 'at-delete-access' ) ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$ticket_ids = isset( $_POST['ticket_ids'] ) ? array_filter( array_map( 'intval', $_POST['ticket_ids'] ) ) : array();
			if ( ! $ticket_ids ) {
				wp_send_json_error( 'Something went wrong!', 400 );
			}

			foreach ( $ticket_ids as $ticket_id ) {

				$ticket = new WPSC_Archive_Ticket( $ticket_id );
				if ( ! $ticket->id ) {
					continue;
				}
				WPSC_Individual_Archive_Ticket::delete_archive_ticket( $ticket );
			}
			wp_die();
		}

		/**
		 * Restore bulk tickets
		 *
		 * @return void
		 */
		public static function bulk_restore_archive_tickets() {

			if ( check_ajax_referer( 'wpsc_bulk_restore_archive_tickets', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_agent ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$ticket_ids = isset( $_POST['ticket_ids'] ) ? array_filter( array_map( 'intval', $_POST['ticket_ids'] ) ) : array();
			if ( ! $ticket_ids ) {
				wp_send_json_error( 'Something went wrong!', 400 );
			}

			foreach ( $ticket_ids as $ticket_id ) {

				$ticket = new WPSC_Archive_Ticket( $ticket_id );
				if ( ! $ticket->id || ! self::has_ticket_cap( 'at' ) ) {
					continue;
				}

				$success = WPSC_Archive_Ticket::restore_archive_ticket( $ticket );
				if ( $success ) {
					$ticket = new WPSC_Ticket( $ticket->id );
					do_action( 'wpsc_ticket_restore', $ticket );
				}
			}
			wp_die();
		}
	}
endif;

WPSC_Archive_Ticket_List::init();
