<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Dashboard_Cards_Setting' ) ) :

	final class WPSC_Dashboard_Cards_Setting {

		/**
		 * Initialize this class
		 */
		public static function init() {

			// Dashboard cards.
			add_action( 'wp_ajax_wpsc_get_dashboard_cards_settings', array( __CLASS__, 'get_dashboard_cards' ) );

			// Set load order.
			add_action( 'wp_ajax_wpsc_set_dashboard_card_load_order', array( __CLASS__, 'set_dashboard_card_load_order' ) );

			// Toggle enable/disable of a single card.
			add_action( 'wp_ajax_wpsc_toggle_dashboard_card_status', array( __CLASS__, 'toggle_dashboard_card_status' ) );

			// Enable/disable all cards at once.
			add_action( 'wp_ajax_wpsc_toggle_all_dashboard_cards_status', array( __CLASS__, 'toggle_all_dashboard_cards_status' ) );

			// allow access to new agent role.
			add_action( 'wpsc_after_add_agent_role', array( __CLASS__, 'after_add_agent_role' ) );

			// allow access to cloned agent role wherever the source role was allowed.
			add_action( 'wpsc_after_clone_agent_role', array( __CLASS__, 'after_clone_agent_role' ), 10, 2 );
		}

		/**
		 * Load dashboard cards
		 */
		public static function get_dashboard_cards() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$dbc = get_option( 'wpsc-dashboard-cards', array() );
			ob_start(); ?>

			<div class="wpsc-dock-container">
				<?php
				printf(
					/* translators: Click here to see the documentation */
					esc_attr__( '%s to see the documentation!', 'supportcandy' ),
					'<a href="https://supportcandy.net/docs/dashboard-cards/" target="_blank">' . esc_attr__( 'Click here', 'supportcandy' ) . '</a>'
				);
				?>
			</div>
			<?php
			$enabled_count = 0;
			foreach ( $dbc as $card ) {
				if ( ! empty( $card['is_enable'] ) ) {
					++$enabled_count;
				}
			}
			$all_enabled = $dbc && $enabled_count === count( $dbc );
			?>
			<div class="wpsc-setting-cards-toggle-all">
				<label class="wpsc-dbc-toggle-switch">
					<input
						type="checkbox"
						class="wpsc-toggle-all-dashboard-cards"
						<?php checked( $all_enabled ); ?>
						onchange="wpsc_toggle_all_dashboard_cards( this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_toggle_all_dashboard_cards_status' ) ); ?>' );"
					/>
					<span class="wpsc-dbc-slider"></span>
				</label>
				<span class="wpsc-toggle-all-label"><?php esc_attr_e( 'Enable All / Disable All', 'supportcandy' ); ?></span>
			</div>
			<div class="wpsc-setting-cards-container ui-sortable">
				<?php
				foreach ( $dbc as $key => $card ) {
					if ( ! class_exists( $card['class'] ) ) {
						continue;
					}
					$style = ! $card['is_enable'] ? 'background-color:#eec7ca;color:#dc2222' : '';
					?>
					<div class="wpsc-setting-card" data-id="<?php echo esc_attr( $key ); ?>" style="<?php echo esc_attr( $style ); ?>">
						<span class="wpsc-sort-handle action-btn"><?php WPSC_Icons::get( 'sort' ); ?></span>
						<span class="title">
							<?php
							$card_title = $card['title'] ? WPSC_Translations::get( 'wpsc-dashboard-card-' . $key, stripslashes( htmlspecialchars( $card['title'] ) ) ) : stripslashes( htmlspecialchars( $card['title'] ) );
							echo esc_attr( $card_title );
							?>
						</span>
						<div class="actions">
							<label class="wpsc-dbc-toggle-switch" onclick="event.stopPropagation();">
								<input
									type="checkbox"
									class="wpsc-toggle-dashboard-card"
									<?php checked( ! empty( $card['is_enable'] ) ); ?>
									onchange="wpsc_toggle_dashboard_card_status( this, '<?php echo esc_attr( $key ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_toggle_dashboard_card_status' ) ); ?>' );"
								/>
								<span class="wpsc-dbc-slider"></span>
							</label>
							<span class="action-btn" onclick="wpsc_get_edit_dashboard_card_widget( 'wpsc-dashboard-cards', '<?php echo esc_attr( $key ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_edit_dashboard_card_widget' ) ); ?>' );"><?php WPSC_Icons::get( 'edit' ); ?></span>
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
					wpsc_set_dashboard_card_load_order(slugs, '<?php echo esc_attr( wp_create_nonce( 'wpsc_set_dashboard_card_load_order' ) ); ?>');
				});
			</script>
			<?php
			wp_die();
		}

		/**
		 * Set dashboard cards order
		 *
		 * @return void
		 */
		public static function set_dashboard_card_load_order() {

			if ( check_ajax_referer( 'wpsc_set_dashboard_card_load_order', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$slugs = isset( $_POST['slugs'] ) ? array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['slugs'] ) ) ) : array();
			if ( ! $slugs ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}
			$sorted_cards = array();

			$dbc      = get_option( 'wpsc-dashboard-cards', array() );
			$dbc_keys = array_keys( $dbc );
			// Verifying if slug is present in list item.
			foreach ( $slugs as $slug ) {
				if ( ! in_array( $slug, $dbc_keys ) ) {
					wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
				}
			}

			foreach ( $slugs as $slug ) :
				$sorted_cards[ $slug ] = $dbc[ $slug ];
			endforeach;
			update_option( 'wpsc-dashboard-cards', $sorted_cards );
			wp_die();
		}

		/**
		 * Toggle enable/disable status of a single dashboard card
		 *
		 * @return void
		 */
		public static function toggle_dashboard_card_status() {

			if ( check_ajax_referer( 'wpsc_toggle_dashboard_card_status', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$slug      = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
			$is_enable = isset( $_POST['is_enable'] ) ? intval( $_POST['is_enable'] ) : 0;

			$dbc = get_option( 'wpsc-dashboard-cards', array() );
			if ( ! $slug || ! isset( $dbc[ $slug ] ) ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$dbc[ $slug ]['is_enable'] = $is_enable;
			update_option( 'wpsc-dashboard-cards', $dbc );
			wp_die();
		}

		/**
		 * Enable or disable all dashboard cards at once
		 *
		 * @return void
		 */
		public static function toggle_all_dashboard_cards_status() {

			if ( check_ajax_referer( 'wpsc_toggle_all_dashboard_cards_status', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$is_enable = isset( $_POST['is_enable'] ) ? intval( $_POST['is_enable'] ) : 0;

			$dbc = get_option( 'wpsc-dashboard-cards', array() );
			foreach ( $dbc as $key => $card ) {
				$dbc[ $key ]['is_enable'] = $is_enable;
			}
			update_option( 'wpsc-dashboard-cards', $dbc );
			wp_die();
		}

		/**
		 * After new agent role added add that role in ticket cards
		 *
		 * @param integer $role_id - agent role id.
		 * @return void
		 */
		public static function after_add_agent_role( $role_id ) {

			$dbc = get_option( 'wpsc-dashboard-cards', array() );
			foreach ( $dbc as $key => $card ) {

				$card['allowed-agent-roles'][] = $role_id;
				$dbc[ $key ]          = $card;
			}
			update_option( 'wpsc-dashboard-cards', $dbc );
		}

		/**
		 * After agent role cloned, add the new role to every card the source role was allowed on.
		 *
		 * @param integer $new_role_id - newly cloned agent role id.
		 * @param integer $source_role_id - source agent role id that was cloned.
		 * @return void
		 */
		public static function after_clone_agent_role( $new_role_id, $source_role_id ) {

			$dbc = get_option( 'wpsc-dashboard-cards', array() );
			foreach ( $dbc as $key => $card ) {

				if ( in_array( $source_role_id, $card['allowed-agent-roles'] ) ) {
					$card['allowed-agent-roles'][] = $new_role_id;
					$dbc[ $key ]                   = $card;
				}
			}
			update_option( 'wpsc-dashboard-cards', $dbc );
		}
	}

endif;

WPSC_Dashboard_Cards_Setting::init();
