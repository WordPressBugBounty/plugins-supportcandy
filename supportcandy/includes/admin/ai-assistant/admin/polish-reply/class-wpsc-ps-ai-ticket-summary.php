<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_PS_AI_Ticket_Summary' ) ) :

	final class WPSC_PS_AI_Ticket_Summary {

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// add ticket_summary to ticket schema.
			add_action( 'wpsc_ticket_schema', array( __CLASS__, 'add_ticket_schema' ) );

			add_action( 'wpsc_it_after_reply_section', array( __CLASS__, 'load_summary_section' ) );
			add_action( 'wpsc_it_before_reply_section', array( __CLASS__, 'load_summary_section' ) );

			add_action( 'wp_ajax_wpsc_get_ticket_summary', array( __CLASS__, 'generate_ticket_summary' ) );

			add_action( 'wpsc_post_reply', array( __CLASS__, 'clear_ticket_summary_on_reply' ), 99, 1 );
		}

		/**
		 * Add ticket_summary schema for ticket
		 *
		 * @param array $schema - schema name.
		 * @return array
		 */
		public static function add_ticket_schema( $schema ) {

			$ticket_summary_schema = array(
				'ticket_summary' => array(
					'has_ref'          => false,
					'ref_class'        => '',
					'has_multiple_val' => false,
				),
			);

			return array_merge( $schema, $ticket_summary_schema );
		}

		/**
		 * Load summary section in ticket details page
		 *
		 * @param WPSC_Ticket $ticket - current ticket object.
		 * @return void
		 */
		public static function load_summary_section( $ticket ) {

			$current_user = WPSC_Current_User::$current_user;
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );

			// Check agent permission and ticket restriction in a single conditional for clarity.
			if (
				! $current_user->is_agent ||
				! WPSC_Individual_Ticket::has_ticket_cap( 'view' ) ||
				WPSC_Ticket_Restrictions_Manager::is_restricted( $ticket ) ||
				empty( $ai_settings['is-active'] )
			) {
				return;
			}

			$summary = $ticket->ticket_summary;
			?>
			<div class="wpsc-it-widget wpsc-itw-ticket-summary wpsc-ticket-summary-section">
				<div class="wpsc-widget-header">
					<h2><?php esc_attr_e( 'AI Overview', 'wpsc-ps' ); ?></h2>
					<span class="wpsc-itw-toggle" data-widget="wpsc-itw-ticket-summary"><?php WPSC_Icons::get( 'chevron-up' ); ?></span>
				</div>
				<div class="wpsc-widget-body wpsc-ticket-summary-container">
					<?php
					if ( ! $summary ) {
						?>
						<div class="wpsc-ticket-summary-btn-container">
							<button class="wpsc-button small secondary" onclick="wpsc_generate_ticket_summary(this,'<?php echo esc_attr( $ticket->id ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_generate_ticket_summary' ) ); ?>');"><?php esc_attr_e( 'Generate', 'wpsc-ps' ); ?></button>
						</div>
						<?php
					} else {
						echo wp_kses(
							$summary,
							array(
								'ul'     => array(),
								'li'     => array(),
								'p'      => array(),
								'strong' => array(),
							)
						);
					}
					?>
				</div>
			</div>
			<?php
		}

		/**
		 * Generate ticket summary using AI and return the result
		 *
		 * @return void
		 */
		public static function generate_ticket_summary() {

			if ( check_ajax_referer( 'wpsc_generate_ticket_summary', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$ticket_id = isset( $_POST['ticket_id'] ) ? intval( $_POST['ticket_id'] ) : 0;
			if ( ! $ticket_id ) {
				wp_send_json_error( array( 'message' => 'Invalid ticket ID.' ) );
			}

			$ticket = new WPSC_Ticket( $ticket_id );
			if ( ! $ticket->id ) {
				wp_send_json_error( array( 'message' => 'Ticket not found.' ) );
			}

			WPSC_Individual_Ticket::$ticket = $ticket;
			$current_user = WPSC_Current_User::$current_user;
			$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );

			// Check agent permission and ticket restriction in a single conditional for clarity.
			if (
				! $current_user->is_agent ||
				! WPSC_Individual_Ticket::has_ticket_cap( 'view' ) ||
				WPSC_Ticket_Restrictions_Manager::is_restricted( $ticket ) ||
				empty( $ai_settings['is-active'] )
			) {
				wp_send_json_error( __( 'Unauthorized request!', 'wpsc-ps' ), 401 );
			}

			$history = WPSC_PS_AI_Functions::wpsc_get_clean_ticket_history( $ticket_id, 0 );
			$provider = WPSC_PS_AIT_Provider_Factory::get_current_provider( $ai_settings['provider'] );

			$summary = false;
			$summary = $provider->wpsc_generate_ticket_summary( $ai_settings, $history, $ticket_id );

			if ( ! $summary ) {
				wp_send_json_error( array( 'message' => 'Failed to generate summary.' ) );
			}

			$ticket->ticket_summary = $summary['summary'];
			$ticket->save();

			WPSC_PS_AI_Logs::insert(
				array(
					'customer'     => $current_user->customer->id,
					'ticket'       => $ticket_id,
					'provider'     => $ai_settings['provider'],
					'model'        => $ai_settings['model'] ?? 'gpt-4o-mini',
					'feature'      => 'ticket_summary',
					'tokens'       => $summary['tokens'],
					'prompt'       => 'Generate ticket summary for ticket ID ' . $ticket_id,
					'date_created' => ( new DateTime() )->format( 'Y-m-d H:i:s' ),
				)
			);

			wp_send_json_success( array( 'summary' => $summary['summary'] ) );
		}

		/**
		 * Clear ticket summary when a new reply is posted to ensure the summary is always up-to-date with the latest conversation.
		 *
		 * @param WPSC_Thread $thread The thread object for which a reply was posted.
		 * @return void
		 */
		public static function clear_ticket_summary_on_reply( $thread ) {
			$ticket = $thread->ticket;
			$ticket->ticket_summary = '';
			$ticket->save();
		}
	}
endif;
WPSC_PS_AI_Ticket_Summary::init();
