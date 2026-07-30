<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Ai_Frontend' ) ) :

	final class WPSC_Ai_Frontend {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// load scripts & styles.
			add_action( 'wpsc_js_frontend', array( __CLASS__, 'frontend_scripts' ) );
			add_action( 'wpsc_css_frontend', array( __CLASS__, 'frontend_styles' ) );
		}

		/**
		 * Frontend scripts
		 *
		 * @return void
		 */
		public static function frontend_scripts() {

			echo file_get_contents( WPSC_ABSPATH . 'asset/js/ai-assistant/script.js' ) . PHP_EOL . PHP_EOL; // phpcs:ignore
		}

		/**
		 * Frontend styles
		 *
		 * @return void
		 */
		public static function frontend_styles() {

			if ( is_rtl() ) {
				echo file_get_contents( WPSC_ABSPATH . 'asset/css/ai-assistant/public-rtl.css' ) . PHP_EOL . PHP_EOL; // phpcs:ignore
			} else {
				echo file_get_contents( WPSC_ABSPATH . 'asset/css/ai-assistant/public.css' ) . PHP_EOL . PHP_EOL; // phpcs:ignore
			}
		}
	}
endif;
WPSC_Ai_Frontend::init();
