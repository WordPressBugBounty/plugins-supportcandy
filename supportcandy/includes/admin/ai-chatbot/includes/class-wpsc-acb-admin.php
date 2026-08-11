<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Admin' ) ) :

	final class WPSC_ACB_Admin {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			if ( self::is_frontend_enabled() ) {
				add_action( 'wp_footer', array( __CLASS__, 'shadow_css' ) );
				add_action( 'wp_footer', array( __CLASS__, 'render_chatbot_root' ) );
				add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_scripts' ), 1 );
			}

			add_action( 'wpsc_js_backend', array( __CLASS__, 'backend_scripts' ), 1 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_scripts' ), 1 );

			add_action( 'wpsc_css_backend', array( __CLASS__, 'backend_styles' ) );
		}

		/**
		 * Determine whether chatbot should load on frontend.
		 *
		 * @return bool
		 */
		private static function is_frontend_enabled() {

			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );
			$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );

			return ! empty( $ai_settings['is-active'] ) && isset( $acb_settings['status'] ) && '1' === $acb_settings['status'];
		}

		/**
		 * Backend scripts
		 *
		 * @return void
		 */
		public static function backend_scripts() {

			echo file_get_contents( WPSC_ABSPATH . 'asset/js/ai-chatbot/admin.js' ) . PHP_EOL . PHP_EOL; // phpcs:ignore
		}

		/**
		 * Backend styles
		 *
		 * @return void
		 */
		public static function backend_styles() {

			if ( is_rtl() ) {
				echo file_get_contents( WPSC_ABSPATH . 'asset/css/ai-chatbot/admin-rtl.css' ) . PHP_EOL . PHP_EOL; // phpcs:ignore
			} else {
				echo file_get_contents( WPSC_ABSPATH . 'asset/css/ai-chatbot/admin.css' ) . PHP_EOL . PHP_EOL; // phpcs:ignore
			}
		}

		/**
		 * Get chatbot configuration.
		 *
		 * @return array
		 */
		public static function frontend_config() {

			$css = file_get_contents( WPSC_ABSPATH . 'asset/css/ai-chatbot/chatbot.css' ); // phpcs:ignore
			$modal_css = file_get_contents( WPSC_ABSPATH . 'asset/css/ai-chatbot/chatbot-modal.css' ); // phpcs:ignore
			$ticket_form_css = file_get_contents( WPSC_ABSPATH . 'asset/css/ai-chatbot/chatbot-ticket-form.css' ); // phpcs:ignore

			$appearance_setting = get_option(
				'wpsc-acb-appearance-general',
				array(
					'background-color' => '#2271b1',
					'icon-color'       => '#ffffff',
				)
			);

			$launcher_bg = ! empty( $appearance_setting['background-color'] )
				? sanitize_hex_color( $appearance_setting['background-color'] )
				: '#2271b1';
			$launcher_icon = ! empty( $appearance_setting['icon-color'] )
				? sanitize_hex_color( $appearance_setting['icon-color'] )
				: '#ffffff';

			if ( ! $launcher_bg ) {
				$launcher_bg = '#2271b1';
			}

			if ( ! $launcher_icon ) {
				$launcher_icon = '#ffffff';
			}

			$header_btn_hover = self::hex_to_rgba( $launcher_icon, 0.18 );
			$header_border = self::hex_to_rgba( $launcher_icon, 0.24 );

			if ( ! $header_btn_hover ) {
				$header_btn_hover = 'rgba(255, 255, 255, 0.18)';
			}

			if ( ! $header_border ) {
				$header_border = '#eeeeee';
			}

			$css = ':host { --wpsc-chatbot-launcher-bg: ' . $launcher_bg . '; --wpsc-chatbot-launcher-icon: ' . $launcher_icon . '; --wpsc-chatbot-header-bg: ' . $launcher_bg . '; --wpsc-chatbot-header-fg: ' . $launcher_icon . '; --wpsc-chatbot-header-btn-fg: ' . $launcher_icon . '; --wpsc-chatbot-header-btn-hover: ' . $header_btn_hover . '; --wpsc-chatbot-header-btn-hover-fg: ' . $launcher_icon . '; --wpsc-chatbot-header-btn-focus: ' . $launcher_icon . '; --wpsc-chatbot-header-border: ' . $header_border . '; }' . PHP_EOL . $css;

			return array(
				'css'                   => $css,
				'modal_css'             => $modal_css,
				'ticket_form_css'       => $ticket_form_css,
				'icons'                 => array(),
				'template'              => WPSC_Chatbot::get_template(),
				'modal_template'        => WPSC_Chatbot_Modal::get_modal_template(),
				'ticket_modal_template' => WPSC_Chatbot_Ticket_Modal::get_ticket_modal_template(),
				'welcome_template'      => WPSC_Chatbot_Welcome::get_welcome_template(),
				'ticket_form_template'  => WPSC_Chatbot_Ticket_Form::get_ticket_form_template(),
			);
		}

		/**
		 * Convert hex color into RGBA value.
		 *
		 * @param string $hex Hex color.
		 * @param float  $alpha Alpha value between 0 and 1.
		 * @return string
		 */
		private static function hex_to_rgba( $hex, $alpha ) {

			if ( empty( $hex ) ) {
				return '';
			}

			$hex = ltrim( $hex, '#' );

			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}

			if ( 6 !== strlen( $hex ) ) {
				return '';
			}

			$rgb = sscanf( $hex, '%02x%02x%02x' );

			if ( ! is_array( $rgb ) || 3 !== count( $rgb ) ) {
				return '';
			}

			$alpha = max( 0, min( 1, floatval( $alpha ) ) );

			return sprintf( 'rgba(%d, %d, %d, %s)', $rgb[0], $rgb[1], $rgb[2], $alpha );
		}

		/**
		 * Resolve the current visitor's identity if they are logged in, so the chatbot's
		 * ticket-creation forms can be prefilled instead of asking a known user to retype it.
		 *
		 * Must use the same "known customer" condition as
		 * WPSC_ACB_Chats::get_known_user_context() (is_customer + customer
		 * record on WPSC_Current_User::$current_user) rather than a raw
		 * WP_User/wp_get_current_user() check, so this prefill can never
		 * disagree with what the model was told about the visitor.
		 *
		 * @return array{is_logged_in: bool, name: string, email: string}
		 */
		private static function get_logged_in_identity() {

			$current_user = WPSC_Current_User::$current_user;

			// Note: WPSC_Customer exposes 'id' via a magic __get() with no
			// __isset(), and empty( $current_user->customer->id ) always
			// evaluates true regardless of the actual value in that case -
			// verified directly (empty() on a magic-getter-only property
			// short-circuits before ever calling __get()). Read the value
			// out first via a plain property access and test that instead.
			$customer_id = empty( $current_user ) || empty( $current_user->is_customer ) || empty( $current_user->customer ) ? '' : $current_user->customer->id;
			if ( ! $customer_id ) {
				return array(
					'is_logged_in' => false,
					'name'         => '',
					'email'        => '',
				);
			}

			$name = sanitize_text_field( (string) $current_user->customer->name );
			$email = sanitize_email( (string) $current_user->customer->email );

			if ( '' === $name || '' === $email || ! is_email( $email ) ) {
				return array(
					'is_logged_in' => false,
					'name'         => '',
					'email'        => '',
				);
			}

			return array(
				'is_logged_in' => true,
				'name'         => $name,
				'email'        => $email,
			);
		}

		/**
		 * Enqueue chatbot scripts.
		 *
		 * @return void
		 */
		public static function enqueue_frontend_scripts() {

			if ( ! self::is_frontend_enabled() ) {
				return;
			}

			$files = array(
				'chatbot-template.js',
				'chatbot-ticket-modal.js',
				'chatbot-modal.js',
				'chatbot-welcome.js',
				'chatbot-ticket-form.js',
				'chatbot-icons.js',
				'chatbot.js',
			);
			$dependency = array();
			$config_js = 'window.WPSC_AI_Chatbot_Config = ' . wp_json_encode( self::frontend_config() ) . ';';
			$identity = self::get_logged_in_identity();
			$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );
			$ajax_data = array(
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'nonce'               => wp_create_nonce( 'general' ),
				'current_user_name'   => $identity['name'],
				'current_user_email'  => $identity['email'],
				'popup_delay_status'  => isset( $acb_settings['popup-delay-status'] ) ? intval( $acb_settings['popup-delay-status'] ) : 1,
				'popup_delay'         => isset( $acb_settings['popup-delay'] ) ? intval( $acb_settings['popup-delay'] ) : 10,
				'popup_display_limit' => isset( $acb_settings['popup-display-limit'] ) ? intval( $acb_settings['popup-display-limit'] ) : 3,
				// Mirrors the server's WP_DEBUG-gated [WPSC ACB] error_log tracing
				// (see WPSC_ACB_Chats) so the browser console can be turned on/off
				// the same way, for tracing the send-message flow end to end.
				'debug'               => defined( 'WP_DEBUG' ) && WP_DEBUG,
			);

			foreach ( $files as $index => $file ) {

				$handle = 'wpsc-acb-' . str_replace( '.js', '', $file );
				$file_path = WPSC_ABSPATH . 'asset/js/ai-chatbot/' . $file;

				// Bust the browser cache on every file change (not just a
				// plugin version bump) - during active development these
				// scripts change far more often than WPSC_VERSION does, and
				// a static version string lets browsers keep serving a
				// stale cached copy indefinitely after an edit.
				$version = file_exists( $file_path ) ? (string) filemtime( $file_path ) : WPSC_VERSION;

				wp_enqueue_script(
					$handle,
					WPSC_PLUGIN_URL . 'asset/js/ai-chatbot/' . $file,
					$dependency,
					$version,
					true
				);

				if ( 0 === $index ) {
					wp_add_inline_script( $handle, $config_js, 'before' );
					wp_localize_script( $handle, 'wpsc_ai_chatbot', $ajax_data );
				}

				$dependency = array( $handle );
			}
		}

		/**
		 * Output dynamic CSS
		 *
		 * @return void
		 */
		public static function shadow_css() {
			?>
			<style id="wpsc-chatbot-shadow-css">
				/* Add your dynamic CSS here. */
			</style>
			<?php
		}

		/**
		 * Render chatbot root element
		 *
		 * @return void
		 */
		public static function render_chatbot_root() {
			?>
			<div id="wpsc-chatbot-root"></div>
			<?php
		}
	}

endif;
WPSC_ACB_Admin::init();
