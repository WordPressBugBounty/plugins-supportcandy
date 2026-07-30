<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Ai_Legacy_Compat' ) ) :

	/**
	 * AI Assistant / AI ChatBot used to ship inside the "Productivity Suite" add-on and now
	 * ship with core instead. An outdated Productivity Suite install still bundles its own
	 * copy of these features and will load it (under its own 'wpsc-ps-settings' toggle)
	 * during its own bootstrap, before this class ever runs.
	 *
	 * Rather than also loading core's copy and hoping load order keeps the two from
	 * colliding, this class checks - after every plugin has finished loading - whether
	 * that outdated copy is actually active, and if so, defers to it instead.
	 */
	final class WPSC_Ai_Legacy_Compat {

		/**
		 * Feature names currently deferred to an outdated Productivity Suite copy.
		 *
		 * @var array
		 */
		private static $deferred_features = array();

		/**
		 * Initialize the class
		 *
		 * @return void
		 */
		public static function init() {

			add_action( 'plugins_loaded', array( __CLASS__, 'load' ), 20 );
		}

		/**
		 * Load AI assistant / AI chatbot classes, deferring to an outdated Productivity
		 * Suite copy of either feature if one is already active and enabled.
		 *
		 * @return void
		 */
		public static function load() {

			$ps_path = defined( 'WPSC_PS_ABSPATH' ) ? WPSC_PS_ABSPATH : false;
			$ps_settings = $ps_path ? get_option( 'wpsc-ps-settings', array() ) : array();

			$defer_ai_assistant = $ps_path && is_dir( $ps_path . 'includes/ai-assistant' ) && ! empty( $ps_settings['ai-assistant'] );
			$defer_ai_chatbot = $ps_path && is_dir( $ps_path . 'includes/ai-chatbot' ) && ! empty( $ps_settings['ai-chatbot'] );

			if ( ! $defer_ai_assistant ) {
				foreach ( glob( __DIR__ . '/ai-assistant/*.php' ) as $filename ) {
					include_once $filename;
				}
			}

			if ( ! $defer_ai_chatbot ) {
				foreach ( glob( __DIR__ . '/ai-chatbot/*.php' ) as $filename ) {
					include_once $filename;
				}
			}

			if ( $defer_ai_assistant || $defer_ai_chatbot ) {

				$ps_settings['ai-assistant'] = 0;
				$ps_settings['ai-chatbot'] = 0;
				update_option( 'wpsc-ps-settings', $ps_settings );
			}
		}
	}
endif;

WPSC_Ai_Legacy_Compat::init();
