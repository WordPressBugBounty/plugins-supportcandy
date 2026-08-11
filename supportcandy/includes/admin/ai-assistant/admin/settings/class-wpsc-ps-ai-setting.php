<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Setting' ) ) :

	final class WPSC_PS_AI_Setting {

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

			// add settings section.
			add_filter( 'wpsc_icons', array( __CLASS__, 'add_icons' ) );

			// Load tabs for this section.
			add_action( 'admin_init', array( __CLASS__, 'load_tabs' ) );

			// Add current tab to admin localization data.
			add_filter( 'wpsc_admin_localizations', array( __CLASS__, 'localizations' ) );

			// Load sections for this screen.
			add_filter( 'wpsc_settings_page_sections', array( __CLASS__, 'add_settings_tab' ) );

			// List.
			add_action( 'wp_ajax_wpsc_ai_assistant_setting', array( __CLASS__, 'get_ai_assistant_setting' ) );
		}

		/**
		 * Add icons to library
		 *
		 * @param array $icons - icon list.
		 * @return array
		 */
		public static function add_icons( $icons ) {

			$icons['ai-assistant'] = file_get_contents( WPSC_ABSPATH . 'asset/icons/ai-assistant.svg' ); //phpcs:ignore
			$icons['cancel'] = file_get_contents( WPSC_ABSPATH . 'asset/icons/cancel.svg' ); //phpcs:ignore
			return $icons;
		}

		/**
		 * Settings tab
		 *
		 * @param array $sections - setting menus.
		 * @return array
		 */
		public static function add_settings_tab( $sections ) {

			$sections['ai-assistant'] = array(
				'slug'     => 'ai_assistant',
				'icon'     => 'ai-assistant',
				'label'    => esc_attr__( 'AI Assistant', 'wpsc-ps' ),
				'callback' => 'wpsc_ai_assistant_setting',
			);
			return $sections;
		}

		/**
		 * Load tabs for this section
		 *
		 * @return void
		 */
		public static function load_tabs() {

			self::$tabs = apply_filters(
				'wpsc_ai_assistant_settings_tabs',
				array(
					'general'   => array(
						'slug'     => 'general',
						'label'    => esc_attr__( 'Connection', 'wpsc-ps' ),
						'callback' => 'wpsc_get_aia_general_setting',
					),
					'assistant' => array(
						'slug'     => 'assistant',
						'label'    => esc_attr__( 'AI Assistant', 'wpsc-ps' ),
						'callback' => 'wpsc_get_aia_assistant_setting',
					),
				)
			);
			if ( WPSC_PS_AI_Functions::is_allowed_ai_training() ) {
				self::$tabs = array_merge(
					self::$tabs,
					array(
						'website'     => array(
							'slug'     => 'website',
							'label'    => esc_attr__( 'Websites', 'wpsc-ps' ),
							'callback' => 'wpsc_get_aia_website_setting',
						),
						'file-upload' => array(
							'slug'     => 'file_upload',
							'label'    => esc_attr__( 'File Uploads', 'wpsc-ps' ),
							'callback' => 'wpsc_get_aia_file_upload_setting',
						),
					),
					array(
						'ai-logs' => array(
							'slug'     => 'ai_logs',
							'label'    => esc_attr__( 'AI Logs', 'wpsc-ps' ),
							'callback' => 'wpsc_get_aia_logs_setting',
						),
					)
				);
			}
			self::$current_tab = isset( $_REQUEST['tab'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) ) : 'general'; // phpcs:ignore
		}

		/**
		 * Add localizations to local JS
		 *
		 * @param array $localizations - localization.
		 * @return array
		 */
		public static function localizations( $localizations ) {

			if ( ! ( WPSC_Settings::$is_current_page && WPSC_Settings::$current_section === 'ai-assistant' ) ) {
				return $localizations;
			}

			// Current section.
			$localizations['current_tab'] = self::$current_tab;

			return $localizations;
		}

		/**
		 * Load AI assistant settings
		 *
		 * @return void
		 */
		public static function get_ai_assistant_setting() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
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
WPSC_PS_AI_Setting::init();
