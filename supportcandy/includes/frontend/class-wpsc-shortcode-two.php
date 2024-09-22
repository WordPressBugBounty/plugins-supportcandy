<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Shortcode_Two' ) ) :

	final class WPSC_Shortcode_Two {

		/**
		 * Initialize this class
		 */
		public static function init() {

			// register shortcode.
			add_shortcode( 'wpsc_create_ticket', array( __CLASS__, 'layout' ) );
		}

		/**
		 * Layout for this shortcode
		 *
		 * @param array $attrs - Shortcode attributes.
		 * @return string
		 */
		public static function layout( $attrs ) {

			$current_user = WPSC_Current_User::$current_user;
			$gs           = get_option( 'wpsc-gs-general' );

			// logout if guest login is open-ticket.
			if ( WPSC_Current_User::$login_type == 'guest' && WPSC_Current_User::$guest_login_type == 'open-ticket' ) {
				$current_user->logout();
			}

			ob_start();?>
			<div id="wpsc-container" style="display:none;">
				<div class="wpsc-shortcode-container" style="border: none !important;">
					<?php

					if (
						! $current_user->is_guest ||
						$current_user->is_customer ||
						( $current_user->is_guest && in_array( 'guest', $gs['allow-create-ticket'] ) )
					) {

						// js ready function.
						add_action( 'wpsc_js_ready', array( __CLASS__, 'register_js_ready_function' ) );
						?>
						<div class="wpsc-body"></div>
						<?php

					} else {

						WPSC_Frontend::load_authentication_screen();
					}
					?>

				</div>
			</div>
			<?php
			WPSC_Frontend::load_html_snippets();
			self::load_js_functions();
			return ob_get_clean();
		}

		/**
		 * Load js functions for this shortcode
		 *
		 * @return void
		 */
		public static function load_js_functions() {
			?>

			<script type="text/javascript">
				/**
				 * Get create ticket form
				 */
				function wpsc_get_ticket_form() {

					if (wpsc_is_description_text()) {
						if ( confirm(supportcandy.translations.warning_message)) {
							current_ticket = jQuery('#wpsc-current-ticket').val();
							wpsc_clear_saved_draft_reply( current_ticket );
						}else{
							return;
						}
					}

					jQuery('.wpsc-body').html(supportcandy.loader_html);

					if (supportcandy.is_reload != 1) {
						wpsc_scroll_top();
					} else { supportcandy.is_reload = 0 }

					var url = new URL(window.location.href);
					var search_params = url.searchParams;

					var data = {
						action: 'wpsc_get_ticket_form',
						_ajax_nonce: supportcandy.nonce
					};
					search_params.forEach(function(value, key) {
						data[key] = value;
					});
					jQuery.post(supportcandy.ajax_url, data, function (response) {
						jQuery('.wpsc-body').html(response);
						wpsc_reset_responsive_style();
					});
				}

				/**
				 * Get individual ticket
				 */
				function wpsc_get_individual_ticket(id) {

					jQuery('.wpsc-tickets-nav, .wpsc-humbargar-menu-item').removeClass('active');
					jQuery('.wpsc-tickets-nav.ticket-list, .wpsc-humbargar-menu-item.ticket-list').addClass('active');
					jQuery('.wpsc-humbargar-title').html(supportcandy.humbargar_titles.ticket_list);

					// set url
					var url = new URL(window.location.href);
					var search_params = url.searchParams;
					search_params.set('wpsc-section', 'ticket-list');
					search_params.set('ticket-id', id);
					url.search = search_params.toString();
					window.history.replaceState({}, null, url.toString());

					jQuery('.wpsc-body').html(supportcandy.loader_html);

					// set flag to differenciate between ticket list and individual ticket
					supportcandy.ticketListIsIndividual = true;

					var data = {
						action: 'wpsc_get_individual_ticket',
						ticket_id: id,
					};
					search_params.forEach(function(value, key) {
						data[key] = value;
					});
					jQuery.post(supportcandy.ajax_url, data, function (response) {
					jQuery('.wpsc-body').html(response);
					wpsc_reset_responsive_style();
					});
				}
			</script>
			<?php
		}

		/**
		 * JS ready function
		 *
		 * @return void
		 */
		public static function register_js_ready_function() {

			echo 'wpsc_get_ticket_form();' . PHP_EOL;
		}
	}
endif;

WPSC_Shortcode_Two::init();
