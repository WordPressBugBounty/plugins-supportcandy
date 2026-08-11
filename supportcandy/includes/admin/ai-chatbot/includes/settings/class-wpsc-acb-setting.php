<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Setting' ) ) :

	final class WPSC_ACB_Setting {

		/**
		 * Tabs for this section
		 *
		 * @var array
		 */
		private static $sections;

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

			// Add AI chatbot source.
			add_filter( 'wpsc_source_list', array( __CLASS__, 'add_ticket_source' ) );

			// add settings section.
			add_filter( 'wpsc_icons', array( __CLASS__, 'add_icons' ) );

			// Load sections for this screen.
			add_filter( 'wpsc_settings_page_sections', array( __CLASS__, 'add_settings_tab' ) );

			// Add current tab to admin localization data.
			add_filter( 'wpsc_admin_localizations', array( __CLASS__, 'localizations' ) );

			// Load tabs for this section.
			add_action( 'admin_init', array( __CLASS__, 'load_tabs' ) );

			// List.
			add_action( 'wp_ajax_wpsc_ai_chatbot_settings', array( __CLASS__, 'get_ai_chatbot_settings' ) );
		}

		/**
		 * Chatbot source list
		 *
		 * @param array $sources - source name.
		 * @return array
		 */
		public static function add_ticket_source( $sources ) {

			$sources['chatbot'] = 'ChatBot';
			return $sources;
		}

		/**
		 * Add icons to library
		 *
		 * @param array $icons - icon list.
		 * @return array
		 */
		public static function add_icons( $icons ) {

			$icons['send'] = file_get_contents( WPSC_ABSPATH . 'asset/icons/send.svg' ); //phpcs:ignore
			$icons['headphone'] = file_get_contents( WPSC_ABSPATH . 'asset/icons/headphone.svg' ); //phpcs:ignore
			$icons['expand'] = file_get_contents( WPSC_ABSPATH . 'asset/icons/expand.svg' ); //phpcs:ignore
			$icons['compress'] = file_get_contents( WPSC_ABSPATH . 'asset/icons/compress.svg' ); //phpcs:ignore
			$icons['minimize'] = file_get_contents( WPSC_ABSPATH . 'asset/icons/minimize.svg' ); //phpcs:ignore
			$icons['poweroff'] = file_get_contents( WPSC_ABSPATH . 'asset/icons/poweroff.svg' ); //phpcs:ignore
			$icons['sc_logo'] = file_get_contents( WPSC_ABSPATH . 'asset/icons/sc-logo.svg' ); //phpcs:ignore
			return $icons;
		}

		/**
		 * Settings tab
		 *
		 * @param array $sections - setting menus.
		 * @return array
		 */
		public static function add_settings_tab( $sections ) {

			$sections['ai-chatbot-setting'] = array(
				'slug'     => 'ai_chatbot_setting',
				'icon'     => 'headphone',
				'label'    => esc_attr__( 'AI ChatBot', 'wpsc-ps' ),
				'callback' => 'wpsc_ai_chatbot_settings',
			);
			return $sections;
		}

		/**
		 * Add localizations to local JS
		 *
		 * @param array $localizations - localization.
		 * @return array
		 */
		public static function localizations( $localizations ) {

			if ( ! ( WPSC_Settings::$is_current_page && WPSC_Settings::$current_section === 'ai-chatbot-setting' ) ) {
				return $localizations;
			}

			// Current section.
			$localizations['current_tab'] = self::$current_tab;

			return $localizations;
		}

		/**
		 * Load tabs for this section
		 *
		 * @return void
		 */
		public static function load_tabs() {

			self::$tabs        = apply_filters(
				'wpsc_ai_chatbot_settings_tabs',
				array(
					'general'        => array(
						'slug'     => 'general',
						'label'    => esc_attr__( 'General', 'wpsc-ps' ),
						'callback' => 'wpsc_get_acb_general_setting',
					),
					'acb-appearance' => array(
						'slug'     => 'acb_appearance',
						'label'    => esc_attr__( 'Appearance', 'wpsc-ps' ),
						'callback' => 'wpsc_get_acb_appearance_setting',
					),
				)
			);
			self::$current_tab = isset( $_REQUEST['tab'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) ) : 'general'; // phpcs:ignore
		}

		/**
		 * Load AI ChatBot settings
		 *
		 * @return void
		 */
		public static function get_ai_chatbot_settings() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}
			?>
			<div class="wpsc-setting-tab-container">
				<?php
				foreach ( self::$tabs as $key => $tab ) :
					$active = self::$current_tab === $key ? 'active' : '';
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
	}

endif;
WPSC_ACB_Setting::init();
