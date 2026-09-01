<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Chatbot_Modal' ) ) :

	final class WPSC_Chatbot_Modal {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// get_modal_template() is only ever needed on demand, via
			// WPSC_ACB_Admin::frontend_config() - no separate 'init' hook call needed.
		}

		/**
		 * Get chatbot modal template
		 *
		 * @return string
		 */
		public static function get_modal_template() {

			$cookie_name = 'wpsc_acb_session_id';
			$session_id = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

			// Reflect whether this session already has a ticket, so the
			// negative-reaction button knows (on initial render) whether to
			// skip the ticket-escalation form - e.g. when a page reload
			// resumes an existing session whose ticket was already created
			// mid-conversation via the AI tool. chatbot.js also keeps this
			// in sync live, for a ticket created without a reload.
			$ticket_created = false;
			if ( '' !== $session_id ) {
				$session = WPSC_ACB_Sessions::get_session_by_session_uuid( $session_id );
				$ticket_created = $session && ! empty( $session->ticket_id );
			}
			ob_start();
			?>
			<div class="wpsc-chatbot__modal">
				<div class="wpsc-chatbot__modal-backdrop"></div>
				<div class="wpsc-chatbot__modal-dialog">
					<button type="button" class="wpsc-chatbot__modal-close wpsc-chatbot__modal-cancel" aria-label="Close" >✕</button>
					<div class="wpsc-chatbot__modal-title"> <?php esc_attr_e( 'Was this conversation helpful?', 'wpsc-ps' ); ?> </div>
					<div class="wpsc-chatbot__modal-content"> <?php esc_attr_e( 'Your feedback helps us improve our support experience.', 'wpsc-ps' ); ?> </div>
					<div class="wpsc-chatbot__modal-reactions">
						<button type="button" class="wpsc-chatbot__modal-reaction wpsc-chatbot__modal-reaction--positive" data-sessionid="<?php echo esc_attr( $session_id ); ?>" data-reaction="<?php echo esc_attr( WPSC_ACB_Reaction::HAPPY ); ?>" title="<?php esc_attr_e( 'Helpful', 'wpsc-ps' ); ?>" > 👍 </button>
						<button type="button" class="wpsc-chatbot__modal-reaction wpsc-chatbot__modal-reaction--negative" data-sessionid="<?php echo esc_attr( $session_id ); ?>" data-reaction="<?php echo esc_attr( WPSC_ACB_Reaction::UNHAPPY ); ?>" data-ticketcreated="<?php echo esc_attr( $ticket_created ? 'true' : 'false' ); ?>" title="<?php esc_attr_e( 'Not Helpful', 'wpsc-ps' ); ?>" > 👎 </button>
					</div>
					<div class="wpsc-chatbot__modal-content-footer">
						<div class="wpsc-chatbot__modal-footer-ask-me-later" data-sessionid="<?php echo esc_attr( $session_id ); ?>"> <?php esc_attr_e( 'Skip', 'wpsc-ps' ); ?> </div>
					</div>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}
	}
endif;
WPSC_Chatbot_Modal::init();
