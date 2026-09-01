<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Dashboard_Widgets_Setting' ) ) :

	final class WPSC_Dashboard_Widgets_Setting {

		/**
		 * List of allowed custom field types in dashboard widgets
		 *
		 * @var array
		 */
		public static $allowed_cft = array();

		/**
		 * Initialize this class
		 */
		public static function init() {

			add_action( 'init', array( __CLASS__, 'allowed_cft' ) );

			// Ticket widget.
			add_action( 'wp_ajax_wpsc_get_dashboard_widgets_settings', array( __CLASS__, 'get_dashboard_widgets' ) );

			// Add new dashboard widget.
			add_action( 'wp_ajax_wpsc_get_new_dashboard_widget', array( __CLASS__, 'get_new_dashboard_widget' ) );
			add_action( 'wp_ajax_wpsc_set_new_dashboard_widget', array( __CLASS__, 'set_new_dashboard_widget' ) );

			// Delete dashboard widget.
			add_action( 'wp_ajax_wpsc_delete_dashboard_widget', array( __CLASS__, 'delete_dashboard_widget' ) );

			// Set load order.
			add_action( 'wp_ajax_wpsc_set_dashboard_widget_load_order', array( __CLASS__, 'set_dashboard_widget_load_order' ) );

			// Toggle enable/disable of a single widget.
			add_action( 'wp_ajax_wpsc_toggle_dashboard_widget_status', array( __CLASS__, 'toggle_dashboard_widget_status' ) );

			// Enable/disable all widgets at once.
			add_action( 'wp_ajax_wpsc_toggle_all_dashboard_widgets_status', array( __CLASS__, 'toggle_all_dashboard_widgets_status' ) );

			// allow access to new agent role.
			add_action( 'wpsc_after_add_agent_role', array( __CLASS__, 'after_add_agent_role' ) );

			// allow access to cloned agent role wherever the source role was allowed.
			add_action( 'wpsc_after_clone_agent_role', array( __CLASS__, 'after_clone_agent_role' ), 10, 2 );
		}

		/**
		 * List of allowed custom field types in reports
		 *
		 * @return void
		 */
		public static function allowed_cft() {

			self::$allowed_cft = apply_filters(
				'allowed_cft_dashboard_widgets',
				array(
					'cf_single_select',
					'cf_multi_select',
					'cf_checkbox',
					'cf_radio_button',
				)
			);
		}

		/**
		 * Load ticket widgets
		 */
		public static function get_dashboard_widgets() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$dashboard_widgets = get_option( 'wpsc-dashboard-widgets', array() );
			ob_start(); ?>

			<div class="wpsc-dock-container">
				<?php
				printf(
					/* translators: Click here to see the documentation */
					esc_attr__( '%s to see the documentation!', 'supportcandy' ),
					'<a href="https://supportcandy.net/docs/dashboard-widgets/" target="_blank">' . esc_attr__( 'Click here', 'supportcandy' ) . '</a>'
				);
				?>
			</div>
			<?php
			$enabled_count = 0;
			foreach ( $dashboard_widgets as $widget ) {
				if ( ! empty( $widget['is_enable'] ) ) {
					++$enabled_count;
				}
			}
			$all_enabled = $dashboard_widgets && $enabled_count === count( $dashboard_widgets );
			?>
			<div class="wpsc-setting-cards-container ui-sortable">
				<div class="wpsc-actions-btn-setting">
					<button class="wpsc-button normal primary margin-right" onclick="wpsc_get_new_dashboard_widget('<?php echo esc_attr( wp_create_nonce( 'wpsc_get_new_dashboard_widget' ) ); ?>');"><?php esc_attr_e( 'Add widget', 'supportcandy' ); ?></button>
					<div class="wpsc-setting-cards-toggle-all">
						<label class="wpsc-dbc-toggle-switch">
							<input
								type="checkbox"
								class="wpsc-toggle-all-dashboard-widgets"
								<?php checked( $all_enabled ); ?>
								onchange="wpsc_toggle_all_dashboard_widgets( this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_toggle_all_dashboard_widgets_status' ) ); ?>' );"
							/>
							<span class="wpsc-dbc-slider"></span>
						</label>
						<span class="wpsc-toggle-all-label"><?php esc_attr_e( 'Enable All / Disable All', 'supportcandy' ); ?></span>
					</div>
				</div>
				<?php
				foreach ( $dashboard_widgets as $key => $widget ) {
					if ( ! class_exists( $widget['class'] ) ) {
						continue;
					}
					$style = ! $widget['is_enable'] ? 'background-color:#eec7ca;color:#dc2222' : '';
					?>
					<div class="wpsc-setting-card" data-id="<?php echo esc_attr( $key ); ?>" style="<?php echo esc_attr( $style ); ?>">
						<span class="wpsc-sort-handle action-btn"><?php WPSC_Icons::get( 'sort' ); ?></span>
						<span class="title">
							<?php
							$widget_title = $widget['title'] ? WPSC_Translations::get( 'wpsc-dashboard-widget-' . $key, stripslashes( htmlspecialchars( $widget['title'] ) ) ) : stripslashes( htmlspecialchars( $widget['title'] ) );
							echo esc_attr( $widget_title );
							?>
						</span>
						<div class="actions">
							<label class="wpsc-dbc-toggle-switch" onclick="event.stopPropagation();">
								<input
									type="checkbox"
									class="wpsc-toggle-dashboard-widget"
									<?php checked( ! empty( $widget['is_enable'] ) ); ?>
									onchange="wpsc_toggle_dashboard_widget_status( this, '<?php echo esc_attr( $key ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_toggle_dashboard_widget_status' ) ); ?>' );"
								/>
								<span class="wpsc-dbc-slider"></span>
							</label>
							<span class="action-btn" onclick="wpsc_get_edit_dashboard_card_widget( 'wpsc-dashboard-widgets', '<?php echo esc_attr( $key ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_edit_dashboard_card_widget' ) ); ?>' );"><?php WPSC_Icons::get( 'edit' ); ?></span>
							<?php if ( $widget['type'] != 'default' ) { ?>
								<span class="action-btn" onclick="wpsc_delete_dashboard_widget( '<?php echo esc_attr( $key ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_delete_dashboard_widget' ) ); ?>' );"><?php WPSC_Icons::get( 'trash-alt' ); ?></span>
							<?php } ?>
						</div>
					</div>
					<?php
				}
				?>
			</div>
			<div class="setting-footer-actions">
				<button class="wpsc-button normal secondary wpsc-save-sort-order"><?php esc_attr_e( 'Save Order', 'supportcandy' ); ?></button>
			</div>
			<script>
				var items = jQuery( ".wpsc-setting-cards-container" ).sortable({ handle: '.wpsc-sort-handle' });
				jQuery(".wpsc-save-sort-order").click(function(){
					var slugs = items.sortable( "toArray", {attribute: 'data-id'} );
					wpsc_set_dashboard_widget_load_order(slugs, '<?php echo esc_attr( wp_create_nonce( 'wpsc_set_dashboard_widget_load_order' ) ); ?>');
				});
			</script>
			<?php
			wp_die();
		}

		/**
		 * Get add dashboard widgets
		 *
		 * @return void
		 */
		public static function get_new_dashboard_widget() {

			if ( check_ajax_referer( 'wpsc_get_new_dashboard_widget', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 400 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( 'Unauthorized!', 401 );
			}

			$custom_fields = WPSC_Custom_Field::$custom_fields;
			$dbw = get_option( 'wpsc-dashboard-widgets', array() );

			$title = esc_attr__( 'Add new widget', 'supportcandy' );
			ob_start();
			?>
			<form action="#" onsubmit="return false;" class="wpsc-add-new-dashboard-widget">
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Select custom fields', 'supportcandy' ); ?></label>
					</div>
					<select multiple id="wpsc-select-card-items" name="cf_id[]">
						<?php
						foreach ( $custom_fields as $cf ) {
							if ( key_exists( $cf->slug, $dbw ) ) {
								continue;
							}
							if (
								in_array( $cf->type::$slug, self::$allowed_cft ) &&
								in_array( $cf->field, array( 'ticket', 'agentonly' ) )
							) {
								?>
								<option value="<?php echo esc_attr( $cf->id ); ?>"><?php echo esc_attr( $cf->name ); ?></option>
								<?php
							}
						}
						?>
					</select>
					<script>
						jQuery('#wpsc-select-card-items').selectWoo({
							allowClear: false,
							placeholder: ""
						});
					</script>
				</div>
				<input type="hidden" name="action" value="wpsc_set_new_dashboard_widget">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_new_dashboard_widget' ) ); ?>">
			</form>
			<?php
			$body = ob_get_clean();

			ob_start();
			?>
			<button class="wpsc-button small primary" onclick="wpsc_set_new_dashboard_widget(this);">
				<?php echo esc_attr( wpsc__( 'Submit', 'supportcandy' ) ); ?>
			</button>
			<button class="wpsc-button small secondary" onclick="wpsc_close_modal();">
				<?php echo esc_attr( wpsc__( 'Cancel', 'supportcandy' ) ); ?>
			</button>
			<?php
			do_action( 'wpsc_get_edit_agent_footer' );
			$footer = ob_get_clean();

			$response = array(
				'title'  => $title,
				'body'   => $body,
				'footer' => $footer,
			);

			wp_send_json( $response, 200 );
		}

		/**
		 * Set new dashboard widgets
		 *
		 * @return void
		 */
		public static function set_new_dashboard_widget() {

			if ( check_ajax_referer( 'wpsc_set_new_dashboard_widget', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 400 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$cf_ids = isset( $_POST['cf_id'] ) ? array_filter( array_map( 'intval', $_POST['cf_id'] ) ) : array();

			$dbw = get_option( 'wpsc-dashboard-widgets', array() );
			$cf_dashboard_widget = array();
			foreach ( $cf_ids as $id ) {

				$cf = new WPSC_Custom_Field( $id );
				if ( ! $cf->id ) {
					continue;
				}

				if ( $cf->type::$slug == 'cf_single_select' ) {
					$cf_class = 'WPSC_RP_Single_Select';
				} elseif ( $cf->type::$slug == 'cf_multi_select' ) {
					$cf_class = 'WPSC_RP_Multi_Select';
				} elseif ( $cf->type::$slug == 'cf_checkbox' ) {
					$cf_class = 'WPSC_RP_Checkbox';
				} elseif ( $cf->type::$slug == 'cf_radio_button' ) {
					$cf_class = 'WPSC_RP_Radio_Button';
				}

				$cf_dashboard_widget[ $cf->slug ] = array(
					'title'               => $cf->name,
					'is_enable'           => 1,
					'allowed-agent-roles' => array( 1 ),
					'callback'            => 'wpsc_dbw_cf_widgets()',
					'class'               => $cf_class,
					'type'                => 'custom_field',
					'chart-type'          => 'doughnut',
				);

				// add string translations.
				WPSC_Translations::add( 'wpsc-dashboard-widget-' . $cf->slug, stripslashes( $cf->name ) );
			}
			update_option( 'wpsc-dashboard-widgets', array_filter( array_merge( $dbw, $cf_dashboard_widget ) ) );

			wp_die();
		}

		/**
		 * Delete dashboard card
		 *
		 * @return void
		 */
		public static function delete_dashboard_widget() {

			if ( check_ajax_referer( 'wpsc_delete_dashboard_widget', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 400 );
			}

			$db_widget = get_option( 'wpsc-dashboard-widgets' );
			$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : 0;
			if ( ! $slug || ! isset( $db_widget[ $slug ] ) ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			if ( in_array( $slug, array( 'ticket-statistics', 'todays-trends', 'recent-activities', 'category-report', 'recent-tickets', 'agent-list', 'unresolved-statuses', 'unresolved-priorities' ) ) ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			unset( $db_widget[ $slug ] );
			update_option( 'wpsc-dashboard-widgets', $db_widget );

			// remove string translations.
			WPSC_Translations::remove( 'wpsc-dashboard-widget-' . $slug );
			wp_die();
		}

		/**
		 * Set dashboard cards order
		 *
		 * @return void
		 */
		public static function set_dashboard_widget_load_order() {

			if ( check_ajax_referer( 'wpsc_set_dashboard_widget_load_order', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$slugs = isset( $_POST['slugs'] ) ? array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['slugs'] ) ) ) : array();
			if ( ! $slugs ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}
			$sorted_widgets = array();

			$dashboard_widgets      = get_option( 'wpsc-dashboard-widgets', array() );
			$dashboard_widgets_keys = array_keys( $dashboard_widgets );
			// Verifying if slug is present in list item.
			foreach ( $slugs as $slug ) {
				if ( ! in_array( $slug, $dashboard_widgets_keys ) ) {
					wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
				}
			}

			foreach ( $slugs as $slug ) :
				$sorted_widgets[ $slug ] = $dashboard_widgets[ $slug ];
			endforeach;
			update_option( 'wpsc-dashboard-widgets', $sorted_widgets );
			wp_die();
		}

		/**
		 * Toggle enable/disable status of a single dashboard widget
		 *
		 * @return void
		 */
		public static function toggle_dashboard_widget_status() {

			if ( check_ajax_referer( 'wpsc_toggle_dashboard_widget_status', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$slug      = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
			$is_enable = isset( $_POST['is_enable'] ) ? intval( $_POST['is_enable'] ) : 0;

			$dashboard_widgets = get_option( 'wpsc-dashboard-widgets', array() );
			if ( ! $slug || ! isset( $dashboard_widgets[ $slug ] ) ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$dashboard_widgets[ $slug ]['is_enable'] = $is_enable;
			update_option( 'wpsc-dashboard-widgets', $dashboard_widgets );
			wp_die();
		}

		/**
		 * Enable or disable all dashboard widgets at once
		 *
		 * @return void
		 */
		public static function toggle_all_dashboard_widgets_status() {

			if ( check_ajax_referer( 'wpsc_toggle_all_dashboard_widgets_status', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$is_enable = isset( $_POST['is_enable'] ) ? intval( $_POST['is_enable'] ) : 0;

			$dashboard_widgets = get_option( 'wpsc-dashboard-widgets', array() );
			foreach ( $dashboard_widgets as $key => $widget ) {
				$dashboard_widgets[ $key ]['is_enable'] = $is_enable;
			}
			update_option( 'wpsc-dashboard-widgets', $dashboard_widgets );
			wp_die();
		}

		/**
		 * After new agent role added add that role in ticket widgets
		 *
		 * @param integer $role_id - agent role id.
		 * @return void
		 */
		public static function after_add_agent_role( $role_id ) {

			$dashboard_widgets = get_option( 'wpsc-dashboard-widgets', array() );
			foreach ( $dashboard_widgets as $key => $widget ) {

				$widget['allowed-agent-roles'][] = $role_id;
				$dashboard_widgets[ $key ]          = $widget;
			}
			update_option( 'wpsc-dashboard-widgets', $dashboard_widgets );
		}

		/**
		 * After agent role cloned, add the new role to every widget the source role was allowed on.
		 *
		 * @param integer $new_role_id - newly cloned agent role id.
		 * @param integer $source_role_id - source agent role id that was cloned.
		 * @return void
		 */
		public static function after_clone_agent_role( $new_role_id, $source_role_id ) {

			$dashboard_widgets = get_option( 'wpsc-dashboard-widgets', array() );
			foreach ( $dashboard_widgets as $key => $widget ) {

				if ( in_array( $source_role_id, $widget['allowed-agent-roles'] ) ) {
					$widget['allowed-agent-roles'][] = $new_role_id;
					$dashboard_widgets[ $key ]       = $widget;
				}
			}
			update_option( 'wpsc-dashboard-widgets', $dashboard_widgets );
		}
	}

endif;

WPSC_Dashboard_Widgets_Setting::init();
