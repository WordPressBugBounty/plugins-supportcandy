<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Dashboard_Settings' ) ) :

	final class WPSC_Dashboard_Settings {

		/**
		 * Tabs for this section
		 *
		 * @var array
		 */
		private static $tabs;

		/**
		 * Current tab
		 *
		 * @var string
		 */
		public static $current_tab;

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Load tabs for this section.
			add_action( 'admin_init', array( __CLASS__, 'load_tabs' ) );

			// Add current tab to admin localization data.
			add_filter( 'wpsc_admin_localizations', array( __CLASS__, 'localizations' ) );

			// Load section tab layout.
			add_action( 'wp_ajax_wpsc_get_dashboard_settings', array( __CLASS__, 'get_dashboard_settings' ) );

			// Dashboard edit/set edit card/widget.
			add_action( 'wp_ajax_wpsc_get_edit_dashboard_card_widget', array( __CLASS__, 'get_edit_dashboard_card_widget' ) );
			add_action( 'wp_ajax_wpsc_set_edit_dashboard_card_widget', array( __CLASS__, 'set_edit_dashboard_card_widget' ) );
		}

		/**
		 * Load tabs for this section
		 */
		public static function load_tabs() {

			self::$tabs        = apply_filters(
				'wpsc_dashboard_setting_tabs',
				array(
					'dashboard-general' => array(
						'slug'     => 'dashboard_general',
						'label'    => esc_attr__( 'General', 'supportcandy' ),
						'callback' => 'wpsc_get_dashboard_general_settings',
					),
					'dashboard-cards'   => array(
						'slug'     => 'dashboard_cards',
						'label'    => esc_attr__( 'Cards', 'supportcandy' ),
						'callback' => 'wpsc_get_dashboard_cards_settings',
					),
					'dashboard-widgets' => array(
						'slug'     => 'dashboard_widgets',
						'label'    => esc_attr__( 'Widgets', 'supportcandy' ),
						'callback' => 'wpsc_get_dashboard_widgets_settings',
					),
				)
			);
			self::$current_tab = isset( $_REQUEST['tab'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) ) : 'dashboard-cards'; // phpcs:ignore
		}

		/**
		 * Add localizations to local JS
		 *
		 * @param array $localizations - localization.
		 * @return array
		 */
		public static function localizations( $localizations ) {

			if ( ! ( WPSC_Settings::$is_current_page && WPSC_Settings::$current_section === 'dashboard-settings' ) ) {
				return $localizations;
			}

			// Current section.
			$localizations['current_tab'] = self::$current_tab;

			return $localizations;
		}

		/**
		 * General setion body layout
		 *
		 * @return void
		 */
		public static function get_dashboard_settings() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}?>

			<div class="wpsc-setting-tab-container">
				<?php
				foreach ( self::$tabs as $key => $tab ) :
					$active = self::$current_tab === $key ? 'active' : ''
					?>
					<button 
						class="<?php echo esc_attr( $key ) . ' ' . esc_attr( $active ); ?>"
						onclick="<?php echo esc_attr( $tab['callback'] ) . '();'; ?>">
						<?php echo esc_attr( $tab['label'] ); ?>
						</button>
					<?php
				endforeach;
				?>
			</div>
			<div class="wpsc-setting-section-body"></div>
			<?php
			wp_die();
		}

		/**
		 * Get edit dashboard cards
		 *
		 * @return void
		 */
		public static function get_edit_dashboard_card_widget() {

			if ( check_ajax_referer( 'wpsc_get_edit_dashboard_card_widget', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : ''; //phpcs:ignore
			if ( ! $type ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : ''; //phpcs:ignore
			if ( ! $slug ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$dashboard_cards  = get_option( $type, array() );
			$card = $dashboard_cards[ $slug ];
			$title = $card['title'];
			$roles = get_option( 'wpsc-agent-roles', array() );
			ob_start();
			?>

			<form action="#" onsubmit="return false;" class="wpsc-frm-edit-dashboard-cards-widgets">
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Title', 'supportcandy' ); ?></label>
					</div>
					<?php
						$translation_slug = $type == 'wpsc-dashboard-cards' ? 'wpsc-dashboard-card-' . $slug : 'wpsc-dashboard-widget-' . $slug;
						$title = $card['title'] ? WPSC_Translations::get( $translation_slug, stripslashes( htmlspecialchars( $card['title'] ) ) ) : stripslashes( htmlspecialchars( $card['title'] ) );
					?>
					<input name="label" type="text" value="<?php echo esc_attr( $title ); ?>" autocomplete="off">
				</div>
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Enable', 'supportcandy' ); ?></label>
					</div>
					<select name="is_enable">
						<option <?php selected( $card['is_enable'], '1' ); ?> value="1"><?php esc_attr_e( 'Yes', 'supportcandy' ); ?></option>
						<option <?php selected( $card['is_enable'], '0' ); ?>  value="0"><?php esc_attr_e( 'No', 'supportcandy' ); ?></option>
					</select>
				</div>
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php esc_attr_e( 'Allowed agent roles', 'supportcandy' ); ?></label>
					</div>
					<select  multiple id="wpsc-select-agents" name="agents[]" placeholder="search agent...">
						<?php
						foreach ( $roles as $key => $role ) :
							$selected = in_array( $key, $card['allowed-agent-roles'] ) ? 'selected="selected"' : ''
							?>
							<option <?php echo esc_attr( $selected ); ?> value="<?php echo esc_attr( $key ); ?>"><?php echo esc_attr( $role['label'] ); ?></option>
							<?php
						endforeach;
						?>
					</select>
				</div>
				<script>
					jQuery('#wpsc-select-agents').selectWoo({
						allowClear: false,
						placeholder: ""
					});
				</script>

				<?php
				if ( method_exists( $card['class'], 'get_edit_dbw_properties' ) ) {
					$card['class']::get_edit_dbw_properties( $card );
				}
				?>

				<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
				<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>">
				<input type="hidden" name="action" value="wpsc_set_edit_dashboard_card_widget">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_edit_dashboard_card_widget' ) ); ?>">
			</form>
			<?php
			$body = ob_get_clean();

			ob_start();
			?>
			<button class="wpsc-button small primary" onclick="wpsc_set_edit_dashboard_card_widget(this);">
				<?php esc_attr_e( 'Submit', 'supportcandy' ); ?>
			</button>
			<button class="wpsc-button small secondary" onclick="wpsc_close_modal();">
				<?php esc_attr_e( 'Cancel', 'supportcandy' ); ?>
			</button>
			<?php
			do_action( 'wpsc_get_edit_dashboard_card_widget_footer' );
			$footer = ob_get_clean();

			$response = array(
				'title'  => $title,
				'body'   => $body,
				'footer' => $footer,
			);
			wp_send_json( $response );
		}

		/**
		 *  Set edit dashboard cards
		 */
		public static function set_edit_dashboard_card_widget() {

			if ( check_ajax_referer( 'wpsc_set_edit_dashboard_card_widget', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
			if ( ! $type ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
			if ( ! $slug ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
			if ( ! $label ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$is_enable  = isset( $_POST['is_enable'] ) ? intval( $_POST['is_enable'] ) : 0;
			$agents     = isset( $_POST['agents'] ) ? array_filter( array_map( 'intval', $_POST['agents'] ) ) : array();

			$chart_type = isset( $_POST['chart-type'] ) ? sanitize_text_field( wp_unslash( $_POST['chart-type'] ) ) : '';

			$list = get_option( $type, array() );
			$list[ $slug ]['title'] = $label;
			$list[ $slug ]['is_enable'] = $is_enable;
			$list[ $slug ]['allowed-agent-roles'] = $agents;
			$list[ $slug ]['chart-type'] = $chart_type;
			update_option( $type, $list );

			// remove string translations.
			$strings = $type == 'wpsc-dashboard-cards' ? 'wpsc-dashboard-card-' . $slug : 'wpsc-dashboard-widget-' . $slug;
			WPSC_Translations::remove( $strings );
			WPSC_Translations::add( $strings, stripslashes( $label ) );
			wp_die();
		}
	}
endif;

WPSC_Dashboard_Settings::init();
