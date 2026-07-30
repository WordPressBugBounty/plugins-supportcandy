<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Ai_Admin' ) ) :

	final class WPSC_Ai_Admin {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// load scripts & styles.
			add_action( 'wpsc_js_backend', array( __CLASS__, 'backend_scripts' ) );
			add_action( 'wpsc_css_backend', array( __CLASS__, 'backend_styles' ) );

			// Add localizations for AI assistant.
			add_filter( 'wpsc_admin_localizations', array( __CLASS__, 'localizations' ) );
			add_filter( 'wpsc_frontend_localizations', array( __CLASS__, 'localizations' ) );
		}

		/**
		 * Backend scripts
		 *
		 * @return void
		 */
		public static function backend_scripts() {

			echo file_get_contents( WPSC_ABSPATH . 'asset/js/ai-assistant/admin.js' ) . PHP_EOL . PHP_EOL; // phpcs:ignore
			echo file_get_contents( WPSC_ABSPATH . 'asset/js/ai-assistant/ai-training.js' ) . PHP_EOL . PHP_EOL; // phpcs:ignore
			echo file_get_contents( WPSC_ABSPATH . 'asset/js/ai-assistant/script.js' ) . PHP_EOL . PHP_EOL; // phpcs:ignore
		}

		/**
		 * Backend styles
		 *
		 * @return void
		 */
		public static function backend_styles() {

			if ( is_rtl() ) {
				echo file_get_contents( WPSC_ABSPATH . 'asset/css/ai-assistant/admin-rtl.css' ) . PHP_EOL . PHP_EOL; // phpcs:ignore
			} else {
				echo file_get_contents( WPSC_ABSPATH . 'asset/css/ai-assistant/admin.css' ) . PHP_EOL . PHP_EOL; // phpcs:ignore
			}
		}

		/**
		 * Add localizations for AI assistant
		 *
		 * @param array $localizations - existing localizations.
		 * @return array
		 */
		public static function localizations( $localizations ) {

			$localizations['ai_loader_html'] = self::ai_loader_html();
			$localizations['translations']['empty_desc_warning'] = esc_attr__( 'Write something to get AI assistance.', 'wpsc-ps' );
			$localizations['translations']['valid_url'] = esc_attr__( 'Please enter valid URL(s)', 'wpsc-ps' );
			$localizations['translations']['delete_all_posts'] = esc_attr__( 'Deleting all posts will permanently remove any existing training data. Do you want to proceed?', 'wpsc-ps' );
			return $localizations;
		}

		/**
		 * Loader HTML
		 *
		 * @return string - Returns HTML.
		 */
		public static function ai_loader_html() {

			ob_start();
			?>
			<div class="wpsc-ai-loader">
				<img src="<?php echo esc_url( WPSC_PLUGIN_URL . 'asset/images/loader.gif' ); ?>" alt="Loading..." />
			</div>
			<?php
			return ob_get_clean();
		}
	}
endif;

WPSC_Ai_Admin::init();
