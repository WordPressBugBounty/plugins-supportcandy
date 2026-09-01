<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Ticket_Widgets' ) ) :

	final class WPSC_Ticket_Widgets {

		/**
		 * Initialize this class
		 */
		public static function init() {

			// Ticket widget.
			add_action( 'wp_ajax_wpsc_get_ticket_widget', array( __CLASS__, 'get_ticket_widget' ) );
			add_action( 'wp_ajax_wpsc_set_tw_load_order', array( __CLASS__, 'set_tw_load_order' ) );

			// Toggle enable/disable of a single ticket widget.
			add_action( 'wp_ajax_wpsc_toggle_ticket_widget_status', array( __CLASS__, 'toggle_ticket_widget_status' ) );

			// Enable/disable all ticket widgets at once.
			add_action( 'wp_ajax_wpsc_toggle_all_ticket_widgets_status', array( __CLASS__, 'toggle_all_ticket_widgets_status' ) );

			// allow access to new agent role.
			add_action( 'wpsc_after_add_agent_role', array( __CLASS__, 'after_add_agent_role' ) );

			// allow access to cloned agent role wherever the source role was allowed.
			add_action( 'wpsc_after_clone_agent_role', array( __CLASS__, 'after_clone_agent_role' ), 10, 2 );
		}

		/**
		 * Load ticket widgets
		 */
		public static function get_ticket_widget() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$ticket_widgets = get_option( 'wpsc-ticket-widget', array() );
			ob_start(); ?>

			<div class="wpsc-setting-header">
				<h2><?php esc_attr_e( 'Ticket Widgets', 'supportcandy' ); ?></h2>
			</div>
			<div class="wpsc-setting-section-body">
				<div class="wpsc-dock-container">
					<?php
					printf(
						/* translators: Click here to see the documentation */
						esc_attr__( '%s to see the documentation!', 'supportcandy' ),
						'<a href="https://supportcandy.net/docs/ticket-widget-settings/" target="_blank">' . esc_attr__( 'Click here', 'supportcandy' ) . '</a>'
					);
					?>
				</div>
				<?php
				$enabled_count = 0;
				foreach ( $ticket_widgets as $ticket_widget ) {
					if ( ! empty( $ticket_widget['is_enable'] ) ) {
						++$enabled_count;
					}
				}
				$all_enabled = $ticket_widgets && $enabled_count === count( $ticket_widgets );
				?>
				<div class="wpsc-setting-cards-toggle-all">
					<label class="wpsc-dbc-toggle-switch">
						<input
							type="checkbox"
							class="wpsc-toggle-all-ticket-widgets"
							<?php checked( $all_enabled ); ?>
							onchange="wpsc_toggle_all_ticket_widgets( this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_toggle_all_ticket_widgets_status' ) ); ?>' );"
						/>
						<span class="wpsc-dbc-slider"></span>
					</label>
					<span class="wpsc-toggle-all-label"><?php esc_attr_e( 'Enable All / Disable All', 'supportcandy' ); ?></span>
				</div>
				<div class="wpsc-setting-cards-container ui-sortable">
					<?php
					foreach ( $ticket_widgets as $key => $ticket_widget ) {
						if ( ! class_exists( $ticket_widget['class'] ) ) {
							continue;
						}
						$style = ! $ticket_widget['is_enable'] ? 'background-color:#eec7ca;color:#dc2222' : '';
						?>
						<div class="wpsc-setting-card" data-id="<?php echo esc_attr( $key ); ?>" style="<?php echo esc_attr( $style ); ?>">
							<span class="wpsc-sort-handle action-btn"><?php WPSC_Icons::get( 'sort' ); ?></span>
							<span class="title">
								<?php
								$ticket_widget_title = $ticket_widget['title'] ? WPSC_Translations::get( 'wpsc-twt-' . $key, stripslashes( htmlspecialchars( $ticket_widget['title'] ) ) ) : stripslashes( htmlspecialchars( $ticket_widget['title'] ) );
								echo esc_attr( $ticket_widget_title );
								?>
							</span>
							<div class="actions">
								<label class="wpsc-dbc-toggle-switch" onclick="event.stopPropagation();">
									<input
										type="checkbox"
										class="wpsc-toggle-ticket-widget"
										<?php checked( ! empty( $ticket_widget['is_enable'] ) ); ?>
										onchange="wpsc_toggle_ticket_widget_status( this, '<?php echo esc_attr( $key ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_toggle_ticket_widget_status' ) ); ?>' );"
									/>
									<span class="wpsc-dbc-slider"></span>
								</label>
								<span class="action-btn"  onclick="<?php echo esc_attr( $ticket_widget['callback'] ); ?>"><?php WPSC_Icons::get( 'edit' ); ?></span>
							</div>
						</div>
						<?php
					}
					?>
				</div>
				<div class="setting-footer-actions">
					<button class="wpsc-button normal secondary wpsc-save-sort-order"><?php esc_attr_e( 'Save Order', 'supportcandy' ); ?></button>
				</div>
			</div>
			<script>
				var items = jQuery( ".wpsc-setting-cards-container" ).sortable({ handle: '.wpsc-sort-handle' });
				jQuery(".wpsc-save-sort-order").click(function(){
					var slugs = items.sortable( "toArray", {attribute: 'data-id'} );
					wpsc_set_tw_load_order(slugs, '<?php echo esc_attr( wp_create_nonce( 'wpsc_set_tw_load_order' ) ); ?>');
				});
			</script>
			<?php
			wp_die();
		}

		/**
		 * Set ticket widgets order
		 *
		 * @return void
		 */
		public static function set_tw_load_order() {

			if ( check_ajax_referer( 'wpsc_set_tw_load_order', '_ajax_nonce', false ) != 1 ) {
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

			$ticket_widgets      = get_option( 'wpsc-ticket-widget', array() );
			$ticket_widgets_keys = array_keys( $ticket_widgets );
			// Verifying if slug is present in list item.
			foreach ( $slugs as $slug ) {
				if ( ! in_array( $slug, $ticket_widgets_keys ) ) {
					wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
				}
			}

			foreach ( $slugs as $slug ) :
				$sorted_widgets[ $slug ] = $ticket_widgets[ $slug ];
			endforeach;
			update_option( 'wpsc-ticket-widget', $sorted_widgets );
			wp_die();
		}

		/**
		 * Toggle enable/disable status of a single ticket widget
		 *
		 * @return void
		 */
		public static function toggle_ticket_widget_status() {

			if ( check_ajax_referer( 'wpsc_toggle_ticket_widget_status', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$slug      = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
			$is_enable = isset( $_POST['is_enable'] ) ? intval( $_POST['is_enable'] ) : 0;

			$ticket_widgets = get_option( 'wpsc-ticket-widget', array() );
			if ( ! $slug || ! isset( $ticket_widgets[ $slug ] ) ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$ticket_widgets[ $slug ]['is_enable'] = $is_enable;
			update_option( 'wpsc-ticket-widget', $ticket_widgets );
			wp_die();
		}

		/**
		 * Enable or disable all ticket widgets at once
		 *
		 * @return void
		 */
		public static function toggle_all_ticket_widgets_status() {

			if ( check_ajax_referer( 'wpsc_toggle_all_ticket_widgets_status', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$is_enable = isset( $_POST['is_enable'] ) ? intval( $_POST['is_enable'] ) : 0;

			$ticket_widgets = get_option( 'wpsc-ticket-widget', array() );
			foreach ( $ticket_widgets as $key => $widget ) {
				$ticket_widgets[ $key ]['is_enable'] = $is_enable;
			}
			update_option( 'wpsc-ticket-widget', $ticket_widgets );
			wp_die();
		}

		/**
		 * After new agent role added add that role in ticket widgets
		 *
		 * @param integer $role_id - agent role id.
		 * @return void
		 */
		public static function after_add_agent_role( $role_id ) {

			$ticket_widgets = get_option( 'wpsc-ticket-widget', array() );
			foreach ( $ticket_widgets as $key => $widget ) {

				$widget['allowed-agent-roles'][] = $role_id;
				$ticket_widgets[ $key ]          = $widget;
			}
			update_option( 'wpsc-ticket-widget', $ticket_widgets );
		}

		/**
		 * After agent role cloned, add the new role to every ticket widget the source role was allowed on.
		 *
		 * @param integer $new_role_id - newly cloned agent role id.
		 * @param integer $source_role_id - source agent role id that was cloned.
		 * @return void
		 */
		public static function after_clone_agent_role( $new_role_id, $source_role_id ) {

			$ticket_widgets = get_option( 'wpsc-ticket-widget', array() );
			foreach ( $ticket_widgets as $key => $widget ) {

				$allowed_agent_roles = isset( $widget['allowed-agent-roles'] ) ? $widget['allowed-agent-roles'] : array();

				if ( in_array( $source_role_id, $allowed_agent_roles ) ) {
					$widget['allowed-agent-roles'][] = $new_role_id;
					$ticket_widgets[ $key ]          = $widget;
				}
			}
			update_option( 'wpsc-ticket-widget', $ticket_widgets );
		}
	}

endif;

WPSC_Ticket_Widgets::init();
