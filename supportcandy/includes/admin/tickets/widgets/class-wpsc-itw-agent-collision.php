<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ITW_Agent_Collision' ) ) :

	final class WPSC_ITW_Agent_Collision {

		/**
		 * Initialization
		 *
		 * @return void
		 */
		public static function init() {

			// agent collision.
			add_action( 'wp_ajax_wpsc_check_live_agents', array( __CLASS__, 'check_live_agents' ) );
			add_action( 'wp_ajax_nopriv_wpsc_check_live_agents', array( __CLASS__, 'check_live_agents' ) );

			// edit widget settings.
			add_action( 'wp_ajax_wpsc_get_tw_agent_collision', array( __CLASS__, 'get_tw_agent_collision' ) );
			add_action( 'wp_ajax_wpsc_set_tw_agent_collision', array( __CLASS__, 'set_tw_agent_collision' ) );

			add_action( 'wpsc_it_layout_section', array( __CLASS__, 'print_agent_collision_scripts' ) );
		}

		/**
		 * Print body of current widget
		 *
		 * @param WPSC_Ticket $ticket - ticket object.
		 * @param array       $settings - setting array.
		 * @return void
		 */
		public static function print_widget( $ticket, $settings ) {

			$current_user = WPSC_Current_User::$current_user;
			$ms_advanced = get_option( 'wpsc-ms-advanced-settings' );

			if (
				! $current_user->is_agent ||
				! $ms_advanced['agent-collision'] ||
				WPSC_Individual_Ticket::$view_profile !== 'agent'
			) {
				return;
			}

			WPSC_Individual_Ticket::$ticket = $ticket;
			if ( WPSC_Individual_Ticket::$is_restricted ) {
				return;
			}

			$title = $settings['title']
					? WPSC_Translations::get( 'wpsc-twt-agent-collision', stripslashes( $settings['title'] ) )
					: stripslashes( $settings['title'] );
			$no_agent_html = '<div class="wpsc-no-agents">No live agent found</div>';
			?>
			<div class="wpsc-it-widget wpsc-itw-ac">
				<div class="wpsc-widget-header">
					<h2><?php echo esc_html( $title ); ?></h2>
					<span class="wpsc-itw-toggle" data-widget="wpsc-itw-ac"><?php WPSC_Icons::get( 'chevron-up' ); ?></span>
				</div>
				<div class="wpsc-widget-body" style="max-height: 100px;">
					<div class="wpsc-widget-default">
						<div class="wpsc-agent-collision">
							<div class="wpsc-live-agents"></div>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Print necessary scripts for agent collision widget
		 *
		 * @param WPSC_Ticket $ticket - ticket object.
		 * @return void
		 */
		public static function print_agent_collision_scripts( $ticket ) {
			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_agent ) {
				return;
			}
			?>
			<script>
				supportcandy.agent_collision = true;
				wpsc_get_live_agents(<?php echo intval( $current_user->agent->id ); ?>, <?php echo intval( $ticket->id ); ?>);
				function wpsc_get_live_agents(agent_id, ticket_id){

					// ALWAYS re-select DOM (important)
					let container = jQuery('.wpsc-live-agents');
					let parent = jQuery('.wpsc-agent-collision');

					var current_tid = jQuery('#wpsc-current-ticket').val();
					if (current_tid != ticket_id){
						schedule_next(agent_id, ticket_id);
						return;
					}
	
					const urlParams = new URLSearchParams(window.location.search);
					let section = supportcandy.is_frontend === '0'
						? urlParams.get('section')
						: urlParams.get('wpsc-section');

					if (!(section == 'ticket-list' && (urlParams.has('id') || urlParams.has('ticket-id')))){
						schedule_next(agent_id, ticket_id);
						return;
					}

					var data = { 
						action: 'wpsc_check_live_agents', 
						agent_id, 
						ticket_id, 
						operation: 'check', 
						_ajax_nonce: supportcandy.nonce 
					};

					jQuery.post(supportcandy.ajax_url, data, function (response) {
						if (response && response.agents){
							if (container.html() !== response.agents){
								container.html(response.agents);
							}
							parent.stop(true, true).fadeIn(200);
						} else {

							let noAgentHTML = '<div class="wpsc-no-agents"><?php echo esc_attr__( 'No live agent found', 'supportcandy' ); ?></div>';
							if (container.html() !== noAgentHTML){
								container.html(noAgentHTML);
							}
							parent.stop(true, true).fadeIn(200);
						}
					});
					schedule_next(agent_id, ticket_id);
				}

				// Keep polling simple & reliable
				function schedule_next(agent_id, ticket_id){
					setTimeout(function(){
						wpsc_get_live_agents(agent_id, ticket_id);
					}, 60000);
				}

				jQuery(document).ready(function(){
					window.addEventListener('beforeunload', function () {
						const urlParams = new URLSearchParams(window.location.search);

						// Determine section
						let section = supportcandy.is_frontend === '0'
							? urlParams.get('section')
							: urlParams.get('wpsc-section');

						// Only trigger if we are on the ticket detail page
						if (!(section === 'ticket-list' && (urlParams.has('id') || urlParams.has('ticket-id')))) {
							return;
						}

						// Prepare data
						const data = new FormData();
						data.append('action', 'wpsc_check_live_agents');
						data.append('agent_id', '<?php echo intval( $current_user->agent->id ); ?>');
						data.append('ticket_id', '<?php echo intval( $ticket->id ); ?>');
						data.append('operation', 'leave');
						data.append('_ajax_nonce', supportcandy.nonce);

						// Send reliably on tab close
						navigator.sendBeacon(supportcandy.ajax_url, data);
					});
				});
			</script>
			<?php
		}

		/**
		 * Get list of live agents.
		 *
		 * @return void
		 */
		public static function check_live_agents() {

			if ( check_ajax_referer( 'general', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$current_user = WPSC_Current_User::$current_user;
			$ms_advanced = get_option( 'wpsc-ms-advanced-settings' );
			if ( ! ( $current_user->is_agent && $ms_advanced['agent-collision'] ) ) {
				wp_send_json_error( new WP_Error( '001', 'Unauthorized!' ), 401 );
			}

			$id = isset( $_POST['ticket_id'] ) ? intval( $_POST['ticket_id'] ) : 0; // phpcs:ignore
			if ( ! $id ) {
				wp_send_json_error( new WP_Error( '001', 'Unauthorized!' ), 401 );
			}

			// Always use the authenticated user's agent ID, not the request's agent_id.
			if ( ! isset( $current_user->agent ) || ! $current_user->agent->id ) {
				wp_send_json_error( new WP_Error( '001', 'Unauthorized!' ), 401 );
			}

			// If agent_id is present in the request, it must match the authenticated user's agent ID.
			if ( isset( $_POST['agent_id'] ) && intval( $_POST['agent_id'] ) !== intval( $current_user->agent->id ) ) {
				wp_send_json_error( new WP_Error( '004', 'Agent ID mismatch!' ), 401 );
			}

			$agent_id = intval( $current_user->agent->id );
			$agent = new WPSC_Agent( $agent_id );
			if ( ! $agent->id ) {
				wp_send_json_error( new WP_Error( '002', 'Something went wrong!' ), 400 );
			}

			$ticket = new WPSC_Ticket( $id );
			if ( ! $ticket->id ) {
				wp_send_json_error( new WP_Error( '002', 'Something went wrong!' ), 400 );
			}

			WPSC_Individual_Ticket::$ticket = $ticket;
			if ( ! WPSC_Individual_Ticket::has_ticket_cap( 'view' ) ) {
				wp_send_json_error( new WP_Error( '003', 'Unauthorized!' ), 401 );
			}

			$operation = isset( $_POST['operation'] ) ? esc_attr( $_POST['operation'] ) : 'check'; // phpcs:ignore

			$agents = json_decode( $ticket->live_agents, true );
			$agents = $agents ? $agents : array();

			if ( $operation == 'leave' ) {

				if ( array_key_exists( $agent->id, $agents ) ) {
					unset( $agents[ $agent->id ] );
					$agents = wp_json_encode( $agents );
					$ticket->live_agents = $agents;
					$ticket->save();
				}
				wp_die();
			}
			// check inactive agents using timestamp comparison; remove if parsing fails or inactive > 60s.
			foreach ( $agents as $key => $tm ) {
				if ( $key == $agent->id ) {
					continue;
				}
				$timestamp = strtotime( $tm );
				if ( $timestamp === false || $timestamp < ( time() - 60 ) ) {
					unset( $agents[ $key ] );
				}
			}

			$agents[ $agent->id ] = ( new DateTime() )->format( 'Y-m-d H:i:s' );

			$html = '';
			foreach ( $agents as $ag_id => $tmp ) {
				if ( $ag_id == $agent->id ) {
					continue;
				}
				$agt = new WPSC_Agent( $ag_id );

				$html .= '<div class="wpsc-agent-avatar">' .
					get_avatar( $agt->customer->email, 28 ) .
					'<span class="wpsc-agent-name">' . esc_html( $agt->name ) . '</span>' .
				'</div>';
			}

			$agents = wp_json_encode( $agents );
			$ticket->live_agents = $agents;
			$ticket->save();
			$response = array(
				'agents' => $html,
			);
			wp_send_json( $response );
		}

		/**
		 * Add/edit Agent Collision title
		 *
		 * @return void
		 */
		public static function get_tw_agent_collision() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$ticket_widgets = get_option( 'wpsc-ticket-widget', array() );
			$agent_collision = $ticket_widgets['agent-collision'];
			$title = $agent_collision['title'];
			ob_start();
			?>
			<form action="#" onsubmit="return false;" class="wpsc-frm-edit-ac">
				<div class="wpsc-input-group">
					<div class="label-container">
						<label for=""><?php echo esc_attr( wpsc__( 'Title', 'supportcandy' ) ); ?></label>
					</div>
					<input name="label" type="text" value="<?php echo esc_attr( $agent_collision['title'] ); ?>" autocomplete="off">
				</div>
				<input type="hidden" name="action" value="wpsc_set_tw_agent_collision">
				<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_set_tw_agent_collision' ) ); ?>">
			</form>
			<?php
			$body = ob_get_clean();
			ob_start();
			?>
			<button class="wpsc-button small primary" onclick="wpsc_set_tw_agent_collision(this);">
				<?php echo esc_attr( wpsc__( 'Submit', 'supportcandy' ) ); ?>
			</button>
			<button class="wpsc-button small secondary" onclick="wpsc_close_modal();">
				<?php echo esc_attr( wpsc__( 'Cancel', 'supportcandy' ) ); ?>
			</button>
			<?php
			$footer   = ob_get_clean();
			$response = array(
				'title'  => $title,
				'body'   => $body,
				'footer' => $footer,
			);
			wp_send_json( $response );
		}

		/**
		 * Save Agent Collision label
		 *
		 * @return void
		 */
		public static function set_tw_agent_collision() {

			if ( check_ajax_referer( 'wpsc_set_tw_agent_collision', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 400 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
			if ( ! $label ) {
				wp_send_json_error( 'Bad Request', 400 );
			}

			$ticket_widgets = get_option( 'wpsc-ticket-widget', array() );
			$ticket_widgets['agent-collision']['title'] = $label;
			update_option( 'wpsc-ticket-widget', $ticket_widgets );

			// remove string translations.
			WPSC_Translations::remove( 'wpsc-twt-agent-collision' );
			WPSC_Translations::add( 'wpsc-twt-agent-collision', stripslashes( $label ) );
			wp_die();
		}
	}
endif;

WPSC_ITW_Agent_Collision::init();
