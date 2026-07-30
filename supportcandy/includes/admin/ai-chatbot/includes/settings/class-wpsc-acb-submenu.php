<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_ACB_Submenu' ) ) :

	final class WPSC_ACB_Submenu {

		/**
		 * Initialize the class
		 *
		 * @return void
		 */
		public static function init() {

			add_action( 'wpsc_admin_submenus_data', array( __CLASS__, 'load_admin_menu' ) );
			add_action( 'wp_ajax_wpsc_get_ai_chatbot_logs', array( __CLASS__, 'get_ai_chatbot_logs' ) );
			add_action( 'wp_ajax_wpsc_view_session_detailed_info', array( __CLASS__, 'view_session_detailed_info' ) );

			add_action( 'wp_ajax_wpsc_get_ai_chatbot_sessions', array( __CLASS__, 'get_ai_chatbot_sessions' ) );
		}

		/**
		 * Load admin submenu
		 *
		 * @param array $submenus - The existing submenus.
		 * @return array
		 */
		public static function load_admin_menu( $submenus ) {

			if ( ! WPSC_Functions::is_site_admin() ) {
				return $submenus;
			}

			$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );
			if ( empty( $acb_settings['status'] ) || '1' !== $acb_settings['status'] ) {
				return $submenus;
			}

			$submenus[] = array(
				'parent_slug' => 'wpsc-tickets',
				'page_title'  => esc_attr__( 'Chat Sessions', 'wpsc-ps' ),
				'menu_title'  => esc_attr__( 'Chat Sessions', 'wpsc-ps' ),
				'capability'  => 'manage_options',
				'menu_slug'   => 'wpsc-ai-chatbot',
				'callback'    => array( __CLASS__, 'layout' ),
			);
			return $submenus;
		}

		/**
		 * Chatbot sessions admin submenu layout
		 *
		 * @return void
		 */
		public static function layout() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				return;
			}
			?>
			<div class="wrap">
				<hr class="wp-header-end">
				<div id="wpsc-container">
					<div class="wpsc-setting-header">
						<h2><?php esc_attr_e( 'AI Chatbot Sessions', 'wpsc-ps' ); ?></h2>
					</div>
					<div class="wpsc-setting-section-body"></div>
					<script>
						function get_ai_chatbot_sessions() {
							jQuery(".wpsc-setting-section-body").html(supportcandy.loader_html);
							jQuery.ajax({
								url: supportcandy.ajax_url,
								type: 'GET',
								data: {
									action: 'wpsc_get_ai_chatbot_sessions',
									_ajax_nonce: '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_ai_chatbot_sessions' ) ); ?>'
								},
								success: function(response) {
									jQuery(".wpsc-setting-section-body").html(response);
								},
								error: function(xhr, status, error) {
									console.error('AJAX error:', error);
								}
							});
						}
						jQuery(document).ready(function() {
							var sessionId = new URLSearchParams(window.location.search).get('session_id');
							if (sessionId && !isNaN(parseInt(sessionId, 10))) {
								wpsc_view_session_detailed_info(
									parseInt(sessionId, 10),
									'<?php echo esc_attr( wp_create_nonce( 'wpsc_view_session_detailed_info' ) ); ?>'
								);
							}else {
								get_ai_chatbot_sessions();
							}
						});
					</script>
				</div>
			</div>
			<?php
		}

		/**
		 * Get list for AI chatbot logs
		 *
		 * @return void
		 */
		public static function get_ai_chatbot_logs() {

			if ( check_ajax_referer( 'wpsc_get_ai_chatbot_logs', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$status_raw = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'all';
			if ( 'all' === $status_raw ) {
				$status = 'all';
			} else {
				$status = intval( $status_raw );
				if ( ! WPSC_ACB_Status::is_valid( $status ) ) {
					$status = 'all';
				}
			}

			$reaction_raw = isset( $_POST['reaction'] ) ? sanitize_text_field( wp_unslash( $_POST['reaction'] ) ) : 'all';
			if ( 'all' === $reaction_raw ) {
				$reaction = 'all';
			} else {
				$reaction = intval( $reaction_raw );
				if ( ! WPSC_ACB_Reaction::is_valid( $reaction ) ) {
					$reaction = 'all';
				}
			}

			$from_date_raw = isset( $_POST['from_date'] ) ? sanitize_text_field( wp_unslash( $_POST['from_date'] ) ) : '';
			$to_date_raw = isset( $_POST['to_date'] ) ? sanitize_text_field( wp_unslash( $_POST['to_date'] ) ) : '';

			$from_date = '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $from_date_raw ) ) {
				$from_date = $from_date_raw;
			} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_date_raw ) ) {
				$from_date = $from_date_raw . ' 00:00:00';
			}

			$to_date = '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $to_date_raw ) ) {
				$to_date = $to_date_raw;
			} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to_date_raw ) ) {
				$to_date = $to_date_raw . ' 23:59:59';
			}

			$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );
			$default_rowperpage = isset( $acb_settings['sessions-per-page'] ) ? intval( $acb_settings['sessions-per-page'] ) : 20;
			if ( $default_rowperpage < 1 ) {
				$default_rowperpage = 20;
			}
			$search     = isset( $_POST['search'] ) && isset( $_POST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_POST['search']['value'] ) ) : '';
			$draw       = isset( $_POST['draw'] ) ? intval( $_POST['draw'] ) : 1;
			$start      = isset( $_POST['start'] ) ? intval( $_POST['start'] ) : 0;
			$rowperpage = isset( $_POST['length'] ) ? intval( $_POST['length'] ) : $default_rowperpage;
			if ( $rowperpage < 1 ) {
				$rowperpage = $default_rowperpage;
			}
			$page_no = ( $start / $rowperpage ) + 1;

			$allowed_orderby = array( 'id', 'status', 'last_activity' );
			$orderby         = 'last_activity';
			$order           = 'DESC';

			if ( isset( $_POST['order'][0]['column'] ) ) {
				$column_index = intval( $_POST['order'][0]['column'] );
				$column_data  = isset( $_POST['columns'][ $column_index ]['data'] ) ? sanitize_key( wp_unslash( $_POST['columns'][ $column_index ]['data'] ) ) : '';

				if ( in_array( $column_data, $allowed_orderby, true ) ) {
					$orderby = $column_data;
				}

				$order_dir = isset( $_POST['order'][0]['dir'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['order'][0]['dir'] ) ) ) : 'DESC';
				if ( in_array( $order_dir, array( 'ASC', 'DESC' ), true ) ) {
					$order = $order_dir;
				}
			}

			$args = array(
				'search'         => $search,
				'items_per_page' => $rowperpage,
				'page_no'        => $page_no,
				'orderby'        => $orderby,
				'order'          => $order,
				'meta_query'     => array(
					'relation' => 'AND',
				),
			);

			if ( 'all' !== $status ) {
				$args['meta_query'][] = array(
					'slug'    => 'status',
					'compare' => '=',
					'val'     => $status,
				);
			}

			if ( 'all' !== $reaction ) {
				$args['meta_query'][] = array(
					'slug'    => 'reaction',
					'compare' => '=',
					'val'     => $reaction,
				);
			}

			if ( $from_date && $to_date ) {
				$args['meta_query'][] = array(
					'slug'    => 'date_created',
					'compare' => 'BETWEEN',
					'val'     => array(
						$from_date,
						$to_date,
					),
				);
			}

			$response = WPSC_ACB_Sessions::find( $args );
			$sessions = $response['results'];

			$data = array();
			foreach ( $sessions as $session ) {

				ob_start();
				?>
				<span class="wpsc-link" onclick="wpsc_view_session_detailed_info(<?php echo esc_attr( $session->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_view_session_detailed_info' ) ); ?>')">
					<?php echo esc_html( $session->subject ? $session->subject : '-' ); ?>
				</span>
				<?php
				$subject = ob_get_clean();

				$visitors_name = esc_attr__( 'Guest', 'wpsc-ps' );
				$raw_visitor_id = is_scalar( $session->visitor_id ) ? trim( (string) $session->visitor_id ) : '';
				$visitor_id = ctype_digit( $raw_visitor_id ) ? absint( $raw_visitor_id ) : 0;
				if ( $visitor_id > 0 ) {
					$visitor = WPSC_Current_User::get_customer_by_user_id( $visitor_id );
					if ( $visitor ) {
						$visitors_name = $visitor->name ? $visitor->name : $visitor->email;
					}
				}

				$url = '-';
				if ( $session->ticket_id ) {
					$url = '<a href="' . esc_url( WPSC_Functions::get_ticket_url( $session->ticket_id->id, 0 ) ) . '" target="_blank">' . esc_attr( $session->ticket_id->id ) . '</a>';
				}

				$data[] = array(
					'id'            => $session->id,
					'subject'       => $subject,
					'name'          => $visitors_name,
					'status'        => ucfirst( WPSC_ACB_Status::get_badge( $session->status ) ),
					'reaction'      => $session->reaction ? WPSC_ACB_Reaction::get_badge( $session->reaction ) : '-',
					'token_count'   => $session->token_count ? intval( $session->token_count ) : 0,
					'ticket'        => $url,
					'last_activity' => $session->last_activity ? wp_date( 'M d, Y h:i A', ( $session->last_activity )->setTimezone( wp_timezone() )->getTimestamp() ) : '-',
				);
			}

			$response = array(
				'draw'                 => intval( $draw ),
				'iTotalRecords'        => $response['total_items'],
				'iTotalDisplayRecords' => $response['total_items'],
				'data'                 => $data,
			);

			wp_send_json( $response );
		}

		/**
		 * Get detailed info for a specific AI chatbot session
		 *
		 * @return void
		 */
		public static function view_session_detailed_info() {

			if ( check_ajax_referer( 'wpsc_view_session_detailed_info', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$session_id = isset( $_POST['session_id'] ) ? intval( $_POST['session_id'] ) : 0;
			if ( ! $session_id ) {
				wp_send_json_error( 'Invalid session ID!', 400 );
			}

			$session = new WPSC_ACB_Sessions( $session_id );
			if ( ! $session || ! $session->id ) {
				wp_send_json_error( 'Session not found!', 404 );
			}

			$messages = WPSC_ACB_Messages::find(
				array(
					'items_per_page' => 0,
					'orderby'        => 'id',
					'order'          => 'ASC',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'slug'    => 'session_id',
							'compare' => '=',
							'val'     => $session_id,
						),
					),
				)
			);
			if ( ! $messages || empty( $messages['results'] ) ) {
				wp_send_json_error( 'No messages found!', 404 );
			}

			$total_tokens = 0;
			foreach ( $messages['results'] as $message ) {
				$total_tokens += $message->token_count ? intval( $message->token_count ) : 0;
			}

			// Prepare session info.
			$subject = $session->subject ? $session->subject : '-';
			$messages_count = isset( $messages['total_items'] ) ? $messages['total_items'] : '-';

			$ticket_url = '-';
			if ( $session->ticket_id ) {
				$ticket_url = '<a href="' . esc_url( WPSC_Functions::get_ticket_url( $session->ticket_id->id, 0 ) ) . '" target="_blank">' . esc_attr( $session->ticket_id->id ) . '</a>';
			}

			$created_at = wp_date( 'M d, Y h:i A', ( $session->date_created )->setTimezone( wp_timezone() )->getTimestamp() );
			$visitor_email = '';
			$visitors_name = esc_attr__( 'Guest', 'wpsc-ps' );
			$raw_visitor_id = is_scalar( $session->visitor_id ) ? trim( (string) $session->visitor_id ) : '';
			$visitor_id = ctype_digit( $raw_visitor_id ) ? absint( $raw_visitor_id ) : 0;
			if ( $visitor_id > 0 ) {
				$visitor = WPSC_Current_User::get_customer_by_user_id( $visitor_id );
				if ( $visitor ) {
					$visitor_email = $visitor->email ? $visitor->email : '';
					$visitors_name = $visitor->name ? $visitor->name : '';
				}
			}
			?>
			<div class="wrap">
				<div id="wpsc-container">
					<div class="wpsc-acb-session">
						<div class="wpsc-acb-back">
							<a href="#" class="button button-secondary"><?php esc_attr_e( '← Back to Sessions', 'wpsc-ps' ); ?></a>
						</div>
						<div class="wpsc-acb-session-info">
							<h3><span class="wpsc-acb-info-icon" aria-hidden="true">💬</span><?php esc_attr_e( 'Session Information', 'wpsc-ps' ); ?></h3>
							<div class="wpsc-acb-subject">
								<label><?php esc_attr_e( 'Subject', 'wpsc-ps' ); ?></label>
								<span><?php echo esc_html( $subject ); ?></span>
							</div>
							<div class="wpsc-acb-info-grid">
								<div class="wpsc-acb-info-item">
									<label><?php esc_attr_e( 'Name', 'wpsc-ps' ); ?></label>
									<span><?php echo esc_html( $visitors_name ); ?></span>
								</div>
								<div class="wpsc-acb-info-item">
									<label><?php esc_attr_e( 'Status', 'wpsc-ps' ); ?></label>
									<span>
										<?php echo wp_kses_post( WPSC_ACB_Status::get_badge( $session->status ) ); ?>
									</span>
								</div>
								<div class="wpsc-acb-info-item">
									<label><?php esc_attr_e( 'Reaction', 'wpsc-ps' ); ?></label>
									<span><?php echo $session->reaction ? wp_kses_post( WPSC_ACB_Reaction::get_badge( $session->reaction ) ) : '—'; ?></span>
								</div>
								<div class="wpsc-acb-info-item">
									<label><?php esc_attr_e( 'Messages', 'wpsc-ps' ); ?></label>
									<span><?php echo esc_html( $messages_count ); ?></span>
								</div>
								<div class="wpsc-acb-info-item">
									<label><?php esc_attr_e( 'Total Tokens', 'wpsc-ps' ); ?></label>
									<span><?php echo esc_html( $total_tokens ); ?></span>
								</div>
								<div class="wpsc-acb-info-item">
									<label><?php esc_attr_e( 'Ticket', 'wpsc-ps' ); ?></label>
									<span class="wpsc-acb-ticket-link"><?php echo wp_kses_post( $ticket_url ); ?></span>
								</div>
								<div class="wpsc-acb-info-item">
									<label><?php esc_attr_e( 'Created At', 'wpsc-ps' ); ?></label>
									<span><?php echo esc_html( $created_at ); ?></span>
								</div>
							</div>
						</div>
						<div class="wpsc-acb-chat-container">
							<?php
							foreach ( $messages['results'] as $key => $message ) {
								$class = $message->sender === 'user' ? 'user' : 'bot';
								if ( isset( $visitor ) && $visitor->id && $message->sender === 'user' ) {
									$creator = $visitor->name;
								} else {
									$creator = $message->sender === 'user' ? 'Visitor' : 'AI Assistant';
								}
								$avatar = $message->sender === 'user' ? '👤' : '🤖';
								$time = $message->date_created ? wp_date( 'M d, Y h:i A', ( $message->date_created )->setTimezone( wp_timezone() )->getTimestamp() ) : '-';
								$tokens = $message->token_count ? intval( $message->token_count ) : 0;
								$content = $message->message ? $message->message : '-';
								?>
								<div class="wpsc-acb-message <?php echo esc_attr( $class ); ?>">
									<div class="wpsc-acb-avatar">
										<?php echo esc_html( $avatar ); ?>
									</div>
									<div class="wpsc-acb-bubble">
										<div class="wpsc-acb-author"><?php echo esc_html( $creator ); ?></div>
										<div class="wpsc-acb-content"><?php echo wp_kses_post( $content ); ?></div>
										<div class="wpsc-acb-time">
											<span class="wpsc-acb-time-value"><?php echo esc_html( $time ); ?></span>
											<span class="wpsc-acb-time-sep">•</span>
											<span class="wpsc-acb-tokens"><?php echo esc_html( $tokens ); ?> tokens</span>
										</div>
									</div>
								</div>
								<?php
							}
							?>
						</div>
					</div>
					<script>
						jQuery(document).ready(function() {
							jQuery('.wpsc-acb-back a').on('click', function(e) {
								e.preventDefault();
								get_ai_chatbot_sessions();
								window.history.replaceState({}, null, 'admin.php?page=wpsc-ai-chatbot');
							});
						});
					</script>
				</div>
			</div>
			<?php
			wp_die();
		}

		/**
		 * Get AI chatbot sessions
		 *
		 * @return void
		 */
		public static function get_ai_chatbot_sessions() {

			if ( check_ajax_referer( 'wpsc_get_ai_chatbot_sessions', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );
			$default_rowperpage = isset( $acb_settings['sessions-per-page'] ) ? intval( $acb_settings['sessions-per-page'] ) : 20;
			if ( $default_rowperpage < 1 ) {
				$default_rowperpage = 20;
			}
			?>
			<div class="wpsc-acb-toolbar">

				<div class="wpsc-acb-toolbar-item">
					<label><?php esc_attr_e( 'Duration', 'wpsc-ps' ); ?></label>
					<select id="wpsc-message-duration-filter">
						<option value="today"><?php esc_attr_e( 'Today', 'wpsc-reports' ); ?></option>
						<option value="yesterday"><?php esc_attr_e( 'Yesterday', 'wpsc-reports' ); ?></option>
						<option value="this-week"><?php esc_attr_e( 'This Week', 'wpsc-reports' ); ?></option>
						<option value="last-week"><?php esc_attr_e( 'Last Week', 'wpsc-reports' ); ?></option>
						<option value="last-30-days" selected="selected"><?php esc_attr_e( 'Last 30 Days', 'wpsc-reports' ); ?></option>
						<option value="this-month"><?php esc_attr_e( 'This Month', 'wpsc-reports' ); ?></option>
						<option value="last-month"><?php esc_attr_e( 'Last Month', 'wpsc-reports' ); ?></option>
						<option value="custom"><?php esc_attr_e( 'Custom', 'wpsc-reports' ); ?></option>
					</select>
				</div>

				<div class="wpsc-acb-toolbar-item">
					<label><?php esc_attr_e( 'Status', 'wpsc-ps' ); ?></label>
					<select id="wpsc-message-status-filter">
						<option value="all"><?php esc_attr_e( 'All statuses', 'wpsc-ps' ); ?></option>
						<option value="<?php echo esc_attr( WPSC_ACB_Status::ACTIVE ); ?>"><?php echo esc_attr( WPSC_ACB_Status::get_label( WPSC_ACB_Status::ACTIVE ) ); ?></option>
						<option value="<?php echo esc_attr( WPSC_ACB_Status::INACTIVE ); ?>"><?php echo esc_attr( WPSC_ACB_Status::get_label( WPSC_ACB_Status::INACTIVE ) ); ?></option>
						<option value="<?php echo esc_attr( WPSC_ACB_Status::ABANDONED ); ?>"><?php echo esc_attr( WPSC_ACB_Status::get_label( WPSC_ACB_Status::ABANDONED ) ); ?></option>
						<option value="<?php echo esc_attr( WPSC_ACB_Status::HANDOFF ); ?>"><?php echo esc_attr( WPSC_ACB_Status::get_label( WPSC_ACB_Status::HANDOFF ) ); ?></option>
						<option value="<?php echo esc_attr( WPSC_ACB_Status::RESOLVED ); ?>"><?php echo esc_attr( WPSC_ACB_Status::get_label( WPSC_ACB_Status::RESOLVED ) ); ?></option>
						<option value="<?php echo esc_attr( WPSC_ACB_Status::CLOSED ); ?>"><?php echo esc_attr( WPSC_ACB_Status::get_label( WPSC_ACB_Status::CLOSED ) ); ?></option>
					</select>
				</div>

				<div class="wpsc-acb-toolbar-item">
					<label><?php esc_attr_e( 'Reactions', 'wpsc-ps' ); ?></label>
					<select id="wpsc-message-reaction-filter">
						<option value="all"><?php esc_attr_e( 'All reactions', 'wpsc-ps' ); ?></option>
						<option value="<?php echo esc_attr( WPSC_ACB_Reaction::HAPPY ); ?>"><?php echo esc_attr( WPSC_ACB_Reaction::get_label( WPSC_ACB_Reaction::HAPPY ) ); ?></option>
						<option value="<?php echo esc_attr( WPSC_ACB_Reaction::UNHAPPY ); ?>"><?php echo esc_attr( WPSC_ACB_Reaction::get_label( WPSC_ACB_Reaction::UNHAPPY ) ); ?></option>
					</select>
				</div>

				<div class="wpsc-acb-toolbar-item wpsc-acb-from-date-filter" style="display:none;">
					<label><?php esc_attr_e( 'From', 'wpsc-ps' ); ?></label>
					<input type="text" id="wpsc-message-from-date" placeholder="YYYY-MM-DD" autocomplete="off" />
				</div>

				<div class="wpsc-acb-toolbar-item wpsc-acb-to-date-filter" style="display:none;">
					<label><?php esc_attr_e( 'To', 'wpsc-ps' ); ?></label>
					<input type="text" id="wpsc-message-to-date" placeholder="YYYY-MM-DD" autocomplete="off" />
				</div>

				<div class="wpsc-acb-toolbar-item wpsc-acb-apply-filter">
					<button type="button" class="wpsc-button normal primary" id="wpsc-acb-apply-date-filter">
						<?php esc_attr_e( 'Apply Filter', 'wpsc-ps' ); ?>
					</button>
				</div>

				<div class="wpsc-acb-toolbar-item wpsc-acb-reset-filter">
					<button type="button" class="wpsc-button normal secondary" id="wpsc-acb-reset-filter">
						<?php esc_attr_e( 'Reset', 'wpsc-ps' ); ?>
					</button>
				</div>

				<div class="wpsc-acb-toolbar-item wpsc-acb-refresh-ai-session-logs">
					<button type="button" class="wpsc-button normal secondary" id="wpsc-acb-refresh-ai-session-logs">
						<?php esc_attr_e( 'Refresh', 'wpsc-ps' ); ?>
					</button>
				</div>

			</div>
			<div id="wpsc-session-list">
				<table class="wpsc_session_info_list wp-list-table widefat">
					<thead>
						<tr>
							<th><?php esc_attr_e( 'ID', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Subject', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Name', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Status', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Reaction', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Tokens', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Ticket ID', 'wpsc-ps' ); ?></th>
							<th><?php esc_attr_e( 'Last activity', 'wpsc-ps' ); ?></th>
						</tr>
					</thead>
				</table>
			</div>
			<script>
				function normalizeDateStringForFilter(inputValue) {
					if (!inputValue) {
						return '';
					}

					var value = String(inputValue).trim();
					if (!value) {
						return '';
					}

					var dtRegex = /^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/;
					if (dtRegex.test(value)) {
						return value.split(' ')[0];
					}

					if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
						return value;
					}

					return '';
				}

				function formatDate(dateObj) {
					var year = dateObj.getFullYear();
					var month = String(dateObj.getMonth() + 1).padStart(2, '0');
					var day = String(dateObj.getDate()).padStart(2, '0');
					return year + '-' + month + '-' + day;
				}

				function buildDurationRange(duration) {
					var now = new Date();
					var from = null;
					var to = null;

					switch (duration) {
						case 'today':
							from = new Date(now.getFullYear(), now.getMonth(), now.getDate());
							to = new Date(now.getFullYear(), now.getMonth(), now.getDate());
							break;

						case 'yesterday':
							from = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
							to = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
							break;

						case 'this-week': {
							var weekday = now.getDay();
							var mondayOffset = weekday === 0 ? -6 : (1 - weekday);
							from = new Date(now.getFullYear(), now.getMonth(), now.getDate() + mondayOffset);
							to = new Date(from.getFullYear(), from.getMonth(), from.getDate() + 6);
							break;
						}

						case 'last-week': {
							var todayWeekday = now.getDay();
							var thisMondayOffset = todayWeekday === 0 ? -6 : (1 - todayWeekday);
							var thisMonday = new Date(now.getFullYear(), now.getMonth(), now.getDate() + thisMondayOffset);
							from = new Date(thisMonday.getFullYear(), thisMonday.getMonth(), thisMonday.getDate() - 7);
							to = new Date(thisMonday.getFullYear(), thisMonday.getMonth(), thisMonday.getDate() - 1);
							break;
						}

						case 'last-30-days':
							from = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 29);
							to = new Date(now.getFullYear(), now.getMonth(), now.getDate());
							break;

						case 'this-month':
							from = new Date(now.getFullYear(), now.getMonth(), 1);
							to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
							break;

						case 'last-month':
							from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
							to = new Date(now.getFullYear(), now.getMonth(), 0);
							break;

						default:
							return { fromDate: '', toDate: '' };
					}

					return {
						fromDate: formatDate(from),
						toDate: formatDate(to)
					};
				}

				function toggleCustomDateInputs(duration) {
					var isCustom = duration === 'custom';
					jQuery('.wpsc-acb-from-date-filter, .wpsc-acb-to-date-filter').toggle(isCustom);
					jQuery('.wpsc-acb-apply-filter').toggle(isCustom);
				}

				function load_session_info_list( status, reaction, fromDate, toDate ) {

					jQuery('.wpsc_session_info_list').dataTable({
						processing: true,
						serverSide: true,
						serverMethod: 'post',
						scrollX: true,
						ajax: { 
							url: supportcandy.ajax_url,
							data: {
								'action': 'wpsc_get_ai_chatbot_logs',
								'_ajax_nonce': '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_ai_chatbot_logs' ) ); ?>',
								'status': status,
								'reaction': reaction,
								'from_date': fromDate,
								'to_date': toDate
							}
						},
						'columns': [
							{ data: 'id'},
							{ data: 'subject'},
							{ data: 'name'},
							{ data: 'status'},
							{ data: 'reaction'},
							{ data: 'token_count'},
							{ data: 'ticket'},
							{ data: 'last_activity'},
						],
						'bDestroy': true,
						'searching': true,
						'ordering': true,
						'order': [[7, 'desc']],
						'bLengthChange': false,
						'pageLength': <?php echo esc_attr( $default_rowperpage ); ?>,
						columnDefs: [ 
							{ targets: [0, 3, 5,6,7], orderable: true },
							{ targets: [1, 2, 4], orderable: false },
							{ targets: '_all', className: 'dt-left' },
						],
						language: supportcandy.translations.datatables
					});
				}

				jQuery(document).ready(function() {

					var params = new URLSearchParams(window.location.search);
					var initialStatus = params.get('status') || 'all';
					var initialReaction = params.get('reaction') || 'all';
					var initialDuration = params.get('duration') || 'last-30-days';
					var initialFromDate = normalizeDateStringForFilter(params.get('from_date') || '');
					var initialToDate = normalizeDateStringForFilter(params.get('to_date') || '');

					if (!jQuery('#wpsc-message-duration-filter option[value="' + initialDuration + '"]').length) {
						initialDuration = 'last-30-days';
					}

					if ((initialFromDate || initialToDate) && !params.get('duration')) {
						initialDuration = 'custom';
					}

					jQuery('#wpsc-message-duration-filter').val(initialDuration);
					toggleCustomDateInputs(initialDuration);

					if (jQuery('#wpsc-message-status-filter option[value="' + initialStatus + '"]').length) {
						jQuery('#wpsc-message-status-filter').val(initialStatus);
					}

					if (jQuery('#wpsc-message-reaction-filter option[value="' + initialReaction + '"]').length) {
						jQuery('#wpsc-message-reaction-filter').val(initialReaction);
					}

					jQuery('#wpsc-message-from-date').val(initialFromDate);
					jQuery('#wpsc-message-to-date').val(initialToDate);

					if (initialDuration !== 'custom') {
						var initialRange = buildDurationRange(initialDuration);
						initialFromDate = initialRange.fromDate;
						initialToDate = initialRange.toDate;
						jQuery('#wpsc-message-from-date').val(initialFromDate);
						jQuery('#wpsc-message-to-date').val(initialToDate);
					}

					if (jQuery.fn.flatpickr) {
						jQuery('#wpsc-message-from-date, #wpsc-message-to-date').flatpickr({
							enableTime: false,
							dateFormat: 'Y-m-d',
							allowInput: true
						});
					}

					load_session_info_list(
						jQuery('#wpsc-message-status-filter').val(),
						jQuery('#wpsc-message-reaction-filter').val(),
						normalizeDateStringForFilter(jQuery('#wpsc-message-from-date').val()),
						normalizeDateStringForFilter(jQuery('#wpsc-message-to-date').val())
					);

					jQuery('#wpsc-session-filter-search').on('keyup', function() {
						var searchTerm = jQuery(this).val();
						jQuery('table.wpsc_session_info_list').DataTable().search(searchTerm).draw();
					});

					jQuery('#wpsc-message-status-filter').on('change', function(){
						var status = jQuery(this).val();
						var reaction = jQuery('#wpsc-message-reaction-filter').val();
						var fromDate = normalizeDateStringForFilter(jQuery('#wpsc-message-from-date').val());
						var toDate = normalizeDateStringForFilter(jQuery('#wpsc-message-to-date').val());
						load_session_info_list(status, reaction, fromDate, toDate);
					});

					jQuery('#wpsc-message-reaction-filter').on('change', function(){
						var reaction = jQuery(this).val();
						var status = jQuery('#wpsc-message-status-filter').val();
						var fromDate = normalizeDateStringForFilter(jQuery('#wpsc-message-from-date').val());
						var toDate = normalizeDateStringForFilter(jQuery('#wpsc-message-to-date').val());
						load_session_info_list(status, reaction, fromDate, toDate);
					});

					jQuery('#wpsc-message-duration-filter').on('change', function(){
						var duration = jQuery(this).val();
						toggleCustomDateInputs(duration);

						if (duration === 'custom') {
							return;
						}

						var range = buildDurationRange(duration);
						jQuery('#wpsc-message-from-date').val(range.fromDate);
						jQuery('#wpsc-message-to-date').val(range.toDate);

						var status = jQuery('#wpsc-message-status-filter').val();
						var reaction = jQuery('#wpsc-message-reaction-filter').val();
						load_session_info_list(status, reaction, range.fromDate, range.toDate);
					});

					jQuery('#wpsc-acb-apply-date-filter').on('click', function(){
						var status = jQuery('#wpsc-message-status-filter').val();
						var reaction = jQuery('#wpsc-message-reaction-filter').val();
						var fromDate = normalizeDateStringForFilter(jQuery('#wpsc-message-from-date').val());
						var toDate = normalizeDateStringForFilter(jQuery('#wpsc-message-to-date').val());

						if (jQuery('#wpsc-message-from-date').val() && !fromDate) {
							alert('Invalid From date format. Use YYYY-MM-DD');
							return;
						}

						if (jQuery('#wpsc-message-to-date').val() && !toDate) {
							alert('Invalid To date format. Use YYYY-MM-DD');
							return;
						}

						load_session_info_list(status, reaction, fromDate, toDate);
					});

					jQuery('#wpsc-acb-reset-filter').on('click', function(){
						jQuery('#wpsc-message-duration-filter').val('last-30-days');
						toggleCustomDateInputs('last-30-days');
						jQuery('#wpsc-message-status-filter').val('all');
						jQuery('#wpsc-message-reaction-filter').val('all');

						var defaultRange = buildDurationRange('last-30-days');
						jQuery('#wpsc-message-from-date').val(defaultRange.fromDate);
						jQuery('#wpsc-message-to-date').val(defaultRange.toDate);
						jQuery('#wpsc-session-filter-search').val('');
						load_session_info_list( 'all', 'all', defaultRange.fromDate, defaultRange.toDate );
						jQuery('table.wpsc_session_info_list').DataTable().search('').draw();
						window.history.replaceState({}, null, window.location.pathname);
					});

					jQuery('#wpsc-acb-refresh-ai-session-logs').on('click', function(){
						var status = jQuery(this).val();
						var reaction = jQuery('#wpsc-message-reaction-filter').val();
						load_session_info_list(status, reaction);
					});
				});
			</script>
			<?php
			wp_die();
		}
	}
endif;
WPSC_ACB_Submenu::init();
