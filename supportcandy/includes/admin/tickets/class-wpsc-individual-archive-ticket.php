<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Individual_Archive_Ticket' ) ) :

	final class WPSC_Individual_Archive_Ticket {

		/**
		 * Current ticket object (ticket model object)
		 *
		 * @var WPSC_Archive_Ticket
		 */
		public static $ticket;

		/**
		 * Viewing profile of current user of this ticket
		 *
		 * @var string
		 */
		public static $view_profile;

		/**
		 * Reply profile of current user of this ticket
		 *
		 * @var string
		 */
		public static $reply_profile;

		/**
		 * Actions for the individual ticket
		 *
		 * @var array
		 */
		private static $actions;

		/**
		 * Submit actions
		 *
		 * @var array
		 */
		private static $submit_actions;

		/**
		 * Thread actions
		 *
		 * @var array
		 */
		private static $thread_actions = array();

		/**
		 * Widget HTML displayed in two places, sidebar and body to maintain responsive behaviour.
		 * By loading their HTML only once, we have optmized load time here.
		 *
		 * @var string
		 */
		private static $widget_html;

		/**
		 * Set whether ticket url is authenticated or not.
		 *
		 * @var boolean
		 */
		public static $url_auth = false;

		/**
		 * Archive ticket view
		 *
		 * @var array
		 */
		public static $readonly = true;

		/**
		 * Initialize the class
		 *
		 * @return void
		 */
		public static function init() {

			add_action( 'wp_ajax_wpsc_get_individual_archive_ticket', array( __CLASS__, 'layout' ) );
			add_action( 'wp_ajax_nopriv_wpsc_get_individual_archive_ticket', array( __CLASS__, 'layout' ) );

			// Load older threads.
			add_action( 'wp_ajax_wpsc_load_older_archive_threads', array( __CLASS__, 'load_older_threads' ) );
			add_action( 'wp_ajax_nopriv_wpsc_load_older_archive_threads', array( __CLASS__, 'load_older_threads' ) );

			// Restore archive ticket.
			add_action( 'wp_ajax_wpsc_it_restore_archived', array( __CLASS__, 'set_restore_archived' ) );

			// Thread actions.
			add_action( 'wp_ajax_wpsc_it_archive_thread_info', array( __CLASS__, 'it_archive_thread_info' ) );
			add_action( 'wp_ajax_nopriv_wpsc_it_archive_thread_info', array( __CLASS__, 'it_archive_thread_info' ) );

			// Permanently delete archive ticket.
			add_action( 'wp_ajax_wpsc_iat_delete_permanently', array( __CLASS__, 'iat_delete_permanently' ) );
			add_action( 'wp_ajax_nopriv_wpsc_iat_delete_permanently', array( __CLASS__, 'iat_delete_permanently' ) );
		}

		/**
		 * Ajax callback function for individual ticket
		 *
		 * @return void
		 */
		public static function layout() {

			$current_user = WPSC_Current_User::$current_user;

			$gs = get_option( 'wpsc-gs-general' );
			self::load_current_ticket();
			self::load_actions();
			self::load_widget_html();?>
			<div class="wpsc-it-container">
				<div class="wpsc-it-body">
				<?php
					self::get_actions();
					self::get_subject();
					self::get_mobile_widgets();
					self::get_thread_section();
				?>
				</div>
				<div class="wpsc-it-sidebar-widget-container wpsc-hidden-xs wpsc-hidden-sm">
					<?php echo self::$widget_html; // phpcs:ignore?>
				</div>
			</div>
			<div style="display:none" id="wpsc-ticket-url"><?php echo esc_url( self::$ticket->get_url() ); ?></div>
			<input type="hidden" id="wpsc-current-ticket" value="<?php echo intval( self::$ticket->id ); ?>">
			<input type="hidden" id="wpsc-current-agent" value="<?php echo $current_user->is_agent ? intval( $current_user->agent->id ) : 0; ?>">
			<script>
				
				var arrow_up = '<?php WPSC_Icons::get( 'chevron-up' ); ?>';
				var arrow_down = '<?php WPSC_Icons::get( 'chevron-down' ); ?>';

				jQuery(document).ready(function() {
					
					var widgets_hidden = JSON.parse(localStorage.getItem('wpsc_itw_hidden')) || [];
					widgets_hidden.forEach(function(widget){
						
						jQuery('.' + widget).find(".wpsc-widget-body").hide();
						jQuery('.' + widget).find(".wpsc-widget-header .wpsc-itw-toggle").html(arrow_down);
					});
					
					jQuery('.wpsc-itw-toggle').on('click',function() {
					
						var widgets_hidden = JSON.parse(localStorage.getItem('wpsc_itw_hidden')) || [];
						var widget = jQuery(this).data('widget');

						if(widgets_hidden.includes(widget)){
							
							jQuery('.' + widget).find(".wpsc-widget-body").slideDown();
							jQuery('.' + widget).find(".wpsc-widget-header .wpsc-itw-toggle").html(arrow_up);
							
							widgets_hidden = widgets_hidden.filter(function(element) {
								return element !== widget;
							});
						} else {

							jQuery('.' + widget).find(".wpsc-widget-body").slideUp();
							jQuery('.' + widget).find(".wpsc-widget-header .wpsc-itw-toggle").html(arrow_down);
							widgets_hidden.push(widget);
						}

						localStorage.setItem('wpsc_itw_hidden', JSON.stringify(widgets_hidden));
					});
				});
			</script>
			<?php
			do_action( 'wpsc_it_layout_section', self::$ticket );
			wp_die();
		}

		/**
		 * Load current ticket using id we got from ajax request
		 * Ignore phpcs nonce issue as we already checked where it is called from.
		 *
		 * @return void
		 */
		public static function load_current_ticket() {

			$current_user = WPSC_Current_User::$current_user;

			$id = isset( $_POST['ticket_id'] ) ? intval( $_POST['ticket_id'] ) : 0; // phpcs:ignore
			if ( ! $id ) {
				wp_send_json_error( new WP_Error( '001', 'Unauthorized!' ), 401 );
			}

			$ticket = new WPSC_Archive_Ticket( $id );
			if ( ! $ticket->id ) {
				wp_send_json_error( new WP_Error( '002', 'Something went wrong!' ), 400 );
			}
			self::$ticket = $ticket;

			// url authentication.
			$auth_code = isset( $_REQUEST['auth-code'] ) ? sanitize_text_field( $_REQUEST['auth-code'] ) : ''; // phpcs:ignore
			if ( ! $auth_code ) {
				$auth_code = isset( $_REQUEST['auth_code'] ) ? sanitize_text_field( $_REQUEST['auth_code'] ) : ''; // phpcs:ignore
			}
			if ( $auth_code && $ticket->auth_code == $auth_code ) {
				self::$url_auth = true;
			}

			// Set view profile as 'customer' if the user is either a valid logged-in customer or a guest with a valid auth code.
			if ( self::is_customer() || ( $current_user->is_guest && ! $current_user->is_customer && self::$url_auth ) ) {
				self::$view_profile = 'customer';
			}

			// Check whether view profile is an agent.
			if ( $current_user->is_agent && self::has_ticket_cap( 'at' ) ) {
				self::$view_profile = 'agent';
			}

			if ( ! self::$view_profile ) :
				?>
				<div style="align-item:center;" ><h6><?php esc_attr_e( 'Unauthorized access!', 'supportcandy' ); ?></h6></div>
				<?php
				wp_die();
			endif;

			// Check if ticket is deleted and whether current user has access to deleted tickets.
			if ( ! ( self::$view_profile == 'agent' ) ) {
				wp_send_json_error( new WP_Error( '003', 'Unauthorized!' ), 401 );
			}
		}

		/**
		 * Load actions of ticket which will be used in action bar
		 *
		 * @return void
		 */
		public static function load_actions() {

			$current_user = WPSC_Current_User::$current_user;

			// Refresh ticket.
			$actions = array(
				'refresh' => array(
					'label'    => esc_attr__( 'Refresh', 'supportcandy' ),
					'callback' => 'wpsc_it_archive_ab_refresh(' . self::$ticket->id . ');',
				),
			);

			// Restore-archived.
			if ( $current_user->is_agent && self::has_ticket_cap( 'at' ) ) {
				$actions['restore-archived'] = array(
					'label'    => esc_attr__( 'Restore', 'supportcandy' ),
					'callback' => 'wpsc_it_restore_archived(' . self::$ticket->id . ', \'' . esc_attr( wp_create_nonce( 'wpsc_it_restore_archived' ) ) . '\');',
				);
			}

			// Permanently delete.
			if ( $current_user->is_agent && $current_user->agent->has_cap( 'at-delete-access' ) ) {
				$actions['delete-permanently'] = array(
					'label'    => esc_attr__( 'Delete Permanently', 'supportcandy' ),
					'callback' => 'wpsc_iat_delete_permanently(' . self::$ticket->id . ', \'' . esc_attr( wp_create_nonce( 'wpsc_iat_delete_permanently' ) ) . '\');',
				);
			}
			self::$actions = apply_filters( 'wpsc_individual_archive_ticket_actions', $actions, self::$ticket );
			if ( self::$view_profile == 'agent' ) {

				// Thread actions.
				$thread_actions = array(
					'info' => array(
						'icon'     => 'info-circle',
						'label'    => esc_attr__( 'Info', 'supportcandy' ),
						'callback' => 'wpsc_it_thread_info',
					),
				);
				self::$thread_actions = apply_filters( 'wpsc_it_archive_thread_actions', $thread_actions );
			}
		}

		/**
		 * Load widget at once and save html to $widget_html
		 *
		 * @return void
		 */
		private static function load_widget_html() {

			$current_user = WPSC_Current_User::$current_user;
			$widgets = get_option( 'wpsc-ticket-widget' );
			ob_start();

			do_action( 'wpsc_before_ticket_widget', self::$ticket );

			foreach ( $widgets as $slug => $widget ) :
				if ( ! $widget['is_enable'] ) {
					continue;
				}
				if ( ! class_exists( $widget['class'] ) || ! method_exists( $widget['class'], 'print_archive_widget' ) ) {
					continue;
				}
				$widget['class']::print_archive_widget( self::$ticket, $widget );
			endforeach;

			do_action( 'wpsc_after_ticket_widget', self::$ticket );

			self::$widget_html = ob_get_clean();
		}

		/**
		 * Action bar where verious actions like refresh, close, duplicate, etc. are available
		 *
		 * @return void
		 */
		public static function get_actions() {

			$actions_arr = array();
			foreach ( self::$actions as $key => $action ) :
				ob_start();
				?>
				<span 
					class="wpsc-link wpsc-it-<?php echo esc_attr( $key ); ?>"
					onclick="<?php echo esc_attr( $action['callback'] ); ?>">
					<?php echo esc_attr( $action['label'] ); ?>
				</span>
				<?php
				$actions_arr[] = ob_get_clean();
			endforeach;
			?>

			<div class="wpsc-it-action-container">
				<div class="wpsc-filter-actions">
					<?php echo implode( '<div class="action-devider"></div>', $actions_arr ); // phpcs:ignore?>
				</div>
			</div>
			<?php
		}

		/**
		 * Subject bar of the ticket
		 *
		 * @return void
		 */
		public static function get_subject() {
			$gs = get_option( 'wpsc-gs-general' );
			?>
			<div class="wpsc-it-body-item wpsc-it-subject-container">
				<h2><?php echo '[' . esc_attr( $gs['ticket-alice'] ) . esc_attr( self::$ticket->id ) . '] ' . esc_attr( self::$ticket->subject ); ?></h2>
			</div>
			<?php
		}

		/**
		 * Displayed only on sm and xs screens
		 *
		 * @return void
		 */
		public static function get_mobile_widgets() {
			?>
			<div class="wpsc-it-body-item wpsc-it-mobile-widget-container wpsc-visible-xs wpsc-visible-sm">
				<div class="wpsc-it-mob-widget-trigger-btn" onclick="wpsc_toggle_mob_it_widgets();">
					<h2><?php esc_attr_e( 'Ticket Details', 'supportcandy' ); ?></h2>
					<span class="down"><?php WPSC_Icons::get( 'chevron-down' ); ?></span>
					<span class="up" style="display:none;"><?php WPSC_Icons::get( 'chevron-up' ); ?></span>
				</div>
				<div class="wpsc-it-mob-widgets-inner-container" data-status="0" style="display: none;">
					<?php echo self::$widget_html; // phpcs:ignore?>
				</div>
			</div>
			<?php
		}

		/**
		 * Get threads section of individual ticket
		 *
		 * @return void
		 */
		public static function get_thread_section() {

			$gs      = get_option( 'wpsc-gs-general' );
			$filters = array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'slug'    => 'ticket',
						'compare' => '=',
						'val'     => self::$ticket->id,
					),
				),
				'orderby'    => 'id',
				'order'      => 'DESC',
			);

			$thread_types = array(
				'slug'    => 'type',
				'compare' => 'IN',
				'val'     => array( 'report', 'reply' ),
			);

			if ( self::$view_profile == 'agent' && self::has_ticket_cap( 'pn' ) ) {
				$thread_types['val'][] = 'note';
			}
			if ( self::$view_profile == 'agent' && self::has_ticket_cap( 'vl' ) ) {
				$thread_types['val'][] = 'log';
			}

			$filters['meta_query'][] = $thread_types;

			$filters = apply_filters( 'wpsc_filter_ticket_threads', $filters );

			$response = WPSC_Archive_Thread::find( $filters );
			$last_id  = $response['results'][ count( $response['results'] ) - 1 ]->id;

			if ( $response['has_next_page'] && $gs['reply-form-position'] == 'bottom' ) :
				?>
				<div class="wpsc-it-body-item" style="display: flex; justify-content:center; margin-bottom: 50px;">
					<button class="wpsc-button small secondary" onclick="wpsc_load_older_archive_threads(this, <?php echo esc_attr( self::$ticket->id ); ?>);"><?php esc_attr_e( 'Load older communications', 'supportcandy' ); ?></button>
				</div>
				<?php
			endif;
			?>

			<div class="wpsc-it-body-item wpsc-it-thread-section-container">
			<?php
			if ( $gs['reply-form-position'] == 'top' ) {
				foreach ( $response['results'] as $thread ) {
					if ( $thread->type == 'log' ) {
						self::print_log( $thread );
					} else {
						self::print_thread( $thread );
					}
				}
			} else {
				for ( $i = count( $response['results'] ) - 1; $i >= 0; $i-- ) {
					$thread = $response['results'][ $i ];
					if ( $thread->type == 'log' ) {
						self::print_log( $thread );
					} else {
						self::print_thread( $thread );
					}
				}
			}
			?>
			</div>
			<script>
				jQuery(document).find('.thread-text').each(function(){
					var height = parseInt(jQuery(this).height());
					<?php
					$advanced = get_option( 'wpsc-ms-advanced-settings', array() );
					if ( $advanced['view-more'] ) {
						?>
						if( height > 100){
							jQuery(this).height(100);
							jQuery(this).parent().find('.wpsc-ticket-thread-expander').text(supportcandy.translations.view_more);
							jQuery(this).parent().find('.wpsc-ticket-thread-expander').show();
						}
						<?php
					} else {
						?>
						jQuery(this).parent().find('.thread-text').height('auto');
						<?php
					}
					?>
				});
				supportcandy.threads = {last_thread: <?php echo esc_attr( $last_id ); ?>}
			</script>
			<?php
			if ( $response['has_next_page'] && $gs['reply-form-position'] == 'top' ) :
				?>
				<div class="wpsc-it-body-item" style="display: flex; justify-content:center; margin-bottom: 50px;">
					<button class="wpsc-button small secondary" onclick="wpsc_load_older_archive_threads(this, <?php echo esc_attr( self::$ticket->id ); ?>);"><?php esc_attr_e( 'Load older communications', 'supportcandy' ); ?></button>
				</div>
				<?php
			endif;
		}

		/**
		 * Print thread
		 *
		 * @param WPSC_Archive_Thread $thread - thread object.
		 * @return void
		 */
		public static function print_thread( $thread ) {

			// If thread is of type "Private Note", return if current user does not have permission to view logs.
			if ( $thread->type == 'note' && ! ( self::$view_profile == 'agent' && self::has_ticket_cap( 'pn' ) ) ) {
				return;
			}

			// If thread is deleted, show only if current user has view log permission. It will show deleteted log with view content link.
			if ( ! $thread->is_active && ! ( self::$view_profile == 'agent' && self::has_ticket_cap( 'vl' ) ) ) {
				return;
			}

			$settings     = get_option( 'wpsc-gs-general' );
			$current_user = WPSC_Current_User::$current_user;
			$advanced     = get_option( 'wpsc-ms-advanced-settings', array() );

			$now           = new DateTime();
			$date          = $thread->date_created->setTimezone( wp_timezone() );
			$time_diff_str = WPSC_Functions::date_interval_highest_unit_ago( $date->diff( $now ) );
			$time_title    = wp_date( $advanced['thread-date-format'], $thread->date_created->setTimezone( wp_timezone() )->getTimestamp() );
			$time_date_str = wp_date( $advanced['thread-date-format'], $thread->date_created->setTimezone( wp_timezone() )->getTimestamp() );
			$time_str      = $advanced['thread-date-display-as'] == 'date' ? $time_date_str : $time_diff_str;

			if (
				! is_object( $thread->seen ) &&
				$current_user->customer == $thread->ticket->customer &&
				in_array( $thread->type, array( 'report', 'reply' ) )
			) {

				$today        = new DateTime();
				$thread->seen = $today->format( 'Y-m-d H:i:s' );
				$thread->save();
			}

			$classes = $thread->type == 'note' ? 'note' : 'reply';
			if ( in_array( $thread->type, array( 'report', 'reply' ) ) ) {
				$thread_user = get_user_by( 'email', $thread->customer->email );
				$user_class = $thread_user && $thread_user->has_cap( 'wpsc_agent' ) ? 'agent' : 'customer';
				$classes = $classes . ' ' . $user_class;
			}
			$classes = apply_filters( 'wpsc_it_thread_add_classes', $classes, $thread );
			self::$thread_actions = apply_filters( 'wpsc_it_filter_thread_actions', self::$thread_actions, $thread );
			?>

			<div class="wpsc-thread <?php echo esc_attr( $classes ); ?> <?php echo esc_attr( $thread->id ); ?>">

				<div class="thread-avatar">
					<?php echo get_avatar( $thread->customer->email, 32 ); ?>
				</div>

				<div class="thread-body">

					<div class="thread-header">

						<div class="user-info">
							<div style="display: flex;">
								<h2 class="user-name"><?php echo esc_attr( $thread->customer->name ) . ' '; ?></h2>
								<h2>
									<small class="thread-type">
										<i>
											<?php
											switch ( $thread->type ) {

												case 'report':
													esc_attr_e( 'reported', 'supportcandy' );
													break;

												case 'reply':
													esc_attr_e( 'replied', 'supportcandy' );
													break;

												case 'note':
													esc_attr_e( 'added a note', 'supportcandy' );
													break;
											}
											?>
										</i>
									</small>
								</h2>
							</div>
							<span class="thread-time" title="<?php echo esc_attr( $time_title ); ?>"><?php echo esc_attr( $time_str ); ?></span>
						</div>
						<?php
						do_action( 'wpsc_it_thread_header', $thread );
						if ( $thread->is_active ) {
							?>
							<div class="actions">
								<?php
								foreach ( self::$thread_actions as $action ) :
									?>
									<span title="<?php echo esc_attr( $action['label'] ); ?>" onclick="<?php echo esc_attr( $action['callback'] ) . '(this, ' . esc_attr( self::$ticket->id ) . ',' . esc_attr( $thread->id ) . ', \'' . esc_attr( wp_create_nonce( $action['callback'] ) ) . '\', \'archive_ticket\')'; ?>">
										<?php WPSC_Icons::get( $action['icon'] ); ?>
									</span>
									<?php
								endforeach;
								?>
							</div>
							<?php
						}
						?>

					</div>

					<div class="thread-text">
						<?php
						if ( $thread->is_active ) {
							echo wp_kses_post( $thread->body );
						} else {
							$logs = $thread->get_logs();
							?>
							<i>
								<?php
								printf(
									/* translators: %1$s: customer name, %2$s: datetime */
									esc_attr__( 'This thread was deleted by %1$s on %2$s.', 'supportcandy' ),
									'<strong>' . esc_attr( $logs[0]->modified_by->name ) . '</strong>',
									esc_attr( $logs[0]->date_created->setTimezone( wp_timezone() )->format( $advanced['thread-date-format'] ) )
								);
								?>
								<span class="wpsc-link" onclick="wpsc_it_view_deleted_thread(<?php echo intval( self::$ticket->id ); ?>, <?php echo intval( $thread->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_it_view_deleted_thread' ) ); ?>')">
									<?php esc_attr_e( 'View thread!', 'supportcandy' ); ?>
								</span>
							</i>
							<?php
						}
						?>
					</div>

					<?php
					if ( $advanced['view-more'] ) {
						?>
						<div class="wpsc-ticket-thread-expander" onclick="wpsc_ticket_thread_expander_toggle(this);" style="display: none;">
							<?php esc_attr_e( 'View More ...', 'supportcandy' ); ?>
						</div>
						<?php
					}

					// Thread attachments.
					if ( $thread->is_active && $thread->attachments ) {
						?>
						<div class="wpsc-thread-attachments">
							<div class="wpsc-attachment-header"><?php esc_attr_e( 'Attachments:', 'supportcandy' ); ?></div>
							<?php
							foreach ( $thread->attachments as $attachment ) {
								?>
								<div class="wpsc-attachment-item">
									<?php
									$download_url = site_url( '/' ) . '?wpsc_attachment=' . $attachment->id . '&auth_code=' . $thread->ticket->auth_code;
									?>
									<a class="wpsc-link" href="<?php echo esc_attr( $download_url ); ?>" target="_blank">
									<span class="wpsc-attachment-name"><?php echo esc_attr( $attachment->name ); ?></span></a>
								</div>
								<?php
							}
							?>
						</div>
						<?php
					}

					// Thread logs.
					$logs = $thread->get_logs();
					if ( $thread->is_active && self::$view_profile == 'agent' && self::has_ticket_cap( 'vl' ) && $logs ) {
						?>
						<div class="wpsc-thread-logs">
							<?php
							foreach ( $logs as $log ) {

								$log_body = json_decode( $log->body );
								?>
								<div class="wpsc-thread-log-item">
									<?php
									switch ( $log_body->type ) {

										case 'modify':
											printf(
												/* translators: %1$s: customer name, %2$s: date time */
												esc_attr__( 'Modified by %1$s on %2$s.', 'supportcandy' ),
												'<strong>' . esc_attr( $log->modified_by->name ) . '</strong>',
												esc_attr( $log->date_created->setTimezone( wp_timezone() )->format( $advanced['thread-date-format'] ) )
											);
											?>
											<span class="wpsc-link" onclick="wpsc_it_view_thread_log(<?php echo intval( self::$ticket->id ); ?>, <?php echo intval( $thread->id ); ?>, <?php echo intval( $log->id ); ?>, '<?php echo esc_attr( wp_create_nonce( 'wpsc_it_view_thread_log' ) ); ?>');">
												<?php esc_attr_e( 'View change', 'supportcandy' ); ?>
											</span>
											<?php
											break;

										case 'delete':
											printf(
												/* translators: %1$s: customer name, %2$s: date time */
												esc_attr__( 'Deleted by %1$s on %2$s', 'supportcandy' ),
												'<strong>' . esc_attr( $log->modified_by->name ) . '</strong>',
												esc_attr( $log->date_created->setTimezone( wp_timezone() )->format( $advanced['thread-date-format'] ) )
											);
											break;

										case 'restore':
											printf(
												/* translators: %1$s: customer name, %2$s: date time */
												esc_attr__( 'Restored by %1$s on %2$s', 'supportcandy' ),
												'<strong>' . esc_attr( $log->modified_by->name ) . '</strong>',
												esc_attr( $log->date_created->setTimezone( wp_timezone() )->format( $advanced['thread-date-format'] ) )
											);
											break;
									}
									?>
								</div>
								<?php
							}
							?>
						</div>
						<?php
					}
					?>

				</div>

			</div>
			<?php
		}

		/**
		 * Print thread log
		 *
		 * @param WPSC_Archive_Thread $thread - thread object.
		 * @return void
		 */
		public static function print_log( $thread ) {

			if ( ! ( self::$view_profile == 'agent' && self::has_ticket_cap( 'vl' ) ) ) {
				return;
			}

			$advanced      = get_option( 'wpsc-ms-advanced-settings', array() );
			$now           = new DateTime();
			$date          = $thread->date_created->setTimezone( wp_timezone() );
			$time_diff_str = WPSC_Functions::date_interval_highest_unit_ago( $date->diff( $now ) );
			$title         = wp_date( $advanced['thread-date-format'], $thread->date_created->setTimezone( wp_timezone() )->getTimestamp() );
			$time_date_str = wp_date( $advanced['thread-date-format'], $thread->date_created->setTimezone( wp_timezone() )->getTimestamp() );
			$time_str      = $advanced['thread-date-display-as'] == 'date' ? $time_date_str : $time_diff_str;

			$body    = json_decode( $thread->body );
			$is_json = ( json_last_error() == JSON_ERROR_NONE ) ? true : false;

			if ( $is_json ) {

				$cf = WPSC_Custom_Field::get_cf_by_slug( $body->slug );
				if ( ! $cf ) {
					return;
				}
				?>
				<div class="wpsc-thread log">
					<div class="thread-avatar">
						<?php
						if ( $thread->customer ) {
							echo get_avatar( $thread->customer->email, 32 );
						} else {
							WPSC_Icons::get( 'system' );
						}
						?>
					</div>
					<div class="thread-body">
						<div class="thread-header">
							<div class="user-info">
								<div>
									<?php
									if ( $thread->customer ) {

										printf(
											/* translators: %1$s: User Name, %2$s: Field Name */
											esc_attr__( '%1$s changed the %2$s', 'supportcandy' ),
											'<strong>' . esc_attr( $thread->customer->name ) . '</strong>',
											'<strong>' . esc_attr( $cf->name ) . '</strong>'
										);

									} else {

										printf(
											/* translators: %1$s: Field Name */
											esc_attr__( 'The %1$s has been changed', 'supportcandy' ),
											'<strong>' . esc_attr( $cf->name ) . '</strong>'
										);
									}
									?>
								</div>
								<span class="thread-time" title="<?php echo esc_attr( $title ); ?>"><?php echo esc_attr( $time_str ); ?></span>
							</div>
						</div>
						<div class="wpsc-log-diff">
							<div class="lhs"><?php $cf->type::print_val( $cf, $body->prev ); ?></div>
							<div class="transform-icon">
								<?php
								if ( is_rtl() ) {
									WPSC_Icons::get( 'arrow-left' );
								} else {
									WPSC_Icons::get( 'arrow-right' );
								}
								?>
							</div>
							<div class="rhs"><?php $cf->type::print_val( $cf, $body->new ); ?></div>
						</div>
					</div>
				</div>
				<?php

			} else {

				?>
				<div class="wpsc-thread log">
					<div class="thread-avatar">
						<?php
							WPSC_Icons::get( 'system' );
						?>
					</div>
					<div class="thread-body">
						<div><?php echo wp_kses_post( $thread->body ); ?></div>
						<span class="thread-time" title="<?php echo esc_attr( $title ); ?>"><?php echo esc_attr( $time_str ); ?></span>
					</div>
				</div>
				<?php
			}
		}

		/**
		 * Check whether current user is customer or not
		 *
		 * @return boolean
		 */
		public static function is_customer() {

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_customer ) {
				return false;
			}

			$adv_setting = get_option( 'wpsc-ms-advanced-settings' );
			if ( $adv_setting['public-mode'] && ! $current_user->is_agent ) {
				return true;
			}

			$allowed_customers = apply_filters( 'wpsc_non_agent_ticket_customers_allowed', array( self::$ticket->customer->id ), self::$ticket );
			if ( in_array( $current_user->customer->id, $allowed_customers ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Agent ticket access for cap
		 *
		 * @param string $cap - capability name.
		 * @return boolean
		 */
		public static function has_ticket_cap( $cap ) {

			$current_user = WPSC_Current_User::$current_user;

			$assigned_agents = array_map(
				fn ( $agent ) => $agent->id,
				self::$ticket->assigned_agent
			);

			$flag = false;
			if (
				(
					! self::$ticket->assigned_agent &&
					$current_user->agent->has_cap( $cap . '-unassigned' )
				) ||
				(
					in_array( $current_user->agent->id, $assigned_agents ) &&
					$current_user->agent->has_cap( $cap . '-assigned-me' )
				) ||
				(
					self::$ticket->assigned_agent &&
					! in_array( $current_user->agent->id, $assigned_agents ) &&
					$current_user->agent->has_cap( $cap . '-assigned-others' )
				)
			) {
				$flag = true;
			}

			return apply_filters( 'wpsc_it_has_ticket_cap', $flag, self::$ticket, $cap );
		}

		/**
		 * Load older threads
		 *
		 * @return void
		 */
		public static function load_older_threads() {

			self::load_current_ticket();
			self::load_actions();

			$last_thread = isset( $_POST['last_thread'] ) ? intval( $_POST['last_thread'] ) : 0; // phpcs:ignore
			if ( ! $last_thread ) {
				wp_send_json_error( new WP_Error( '004', 'Bad request!' ), 401 );
			}

			$gs      = get_option( 'wpsc-gs-general' );
			$filters = array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'slug'    => 'ticket',
						'compare' => '=',
						'val'     => self::$ticket->id,
					),
					array(
						'slug'    => 'id',
						'compare' => '<',
						'val'     => $last_thread,
					),
				),
				'orderby'    => 'id',
				'order'      => 'DESC',
			);

			$thread_types = array(
				'slug'    => 'type',
				'compare' => 'IN',
				'val'     => array( 'report', 'reply' ),
			);

			if ( self::$view_profile == 'agent' && self::has_ticket_cap( 'pn' ) ) {
				$thread_types['val'][] = 'note';
			}
			if ( self::$view_profile == 'agent' && self::has_ticket_cap( 'vl' ) ) {
				$thread_types['val'][] = 'log';
			}

			$filters['meta_query'][] = $thread_types;

			$response = WPSC_Archive_Thread::find( $filters );
			$last_id  = $response['results'][ count( $response['results'] ) - 1 ]->id;

			ob_start();
			if ( $gs['reply-form-position'] == 'top' ) {
				foreach ( $response['results'] as $thread ) {
					if ( $thread->type == 'log' ) {
						self::print_log( $thread );
					} else {
						self::print_thread( $thread );
					}
				}
			} else {
				for ( $i = count( $response['results'] ) - 1; $i >= 0; $i-- ) {
					$thread = $response['results'][ $i ];
					if ( $thread->type == 'log' ) {
						self::print_log( $thread );
					} else {
						self::print_thread( $thread );
					}
				}
			}
			$threads = ob_get_clean();

			$html_response = array(
				'last_thread'   => $last_id,
				'has_next_page' => $response['has_next_page'],
				'threads'       => $threads,
			);

			wp_send_json( $html_response );
			wp_die();
		}

		/**
		 * Restore the given ticket by moving it and its threads to the ticket/thread tables.
		 *
		 * @return void
		 */
		public static function set_restore_archived() {

			if ( check_ajax_referer( 'wpsc_it_restore_archived', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$ticket_id = isset( $_POST['ticket_id'] ) ? intval( $_POST['ticket_id'] ) : 0;
			if ( ! $ticket_id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$ticket = new WPSC_Archive_Ticket( $ticket_id );
			if ( ! $ticket->id ) {
				wp_send_json_error( new WP_Error( '002', 'Something went wrong!' ), 400 );
			}

			$success = WPSC_Archive_Ticket::restore_archive_ticket( $ticket );
			if ( $success ) {
				$ticket = new WPSC_Ticket( $ticket->id );
				do_action( 'wpsc_ticket_restore', $ticket );
			}
		}

		/**
		 * Get thread info
		 *
		 * @return void
		 */
		public static function it_archive_thread_info() {

			if ( check_ajax_referer( 'wpsc_it_thread_info', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			self::load_current_ticket();

			$current_user = WPSC_Current_User::$current_user;

			if ( ! ( self::$view_profile == 'agent' ) ) {
				wp_send_json_error( new WP_Error( '004', 'Unauthorized!' ), 401 );
			}

			$title = esc_attr__( 'Thread info', 'supportcandy' );

			$thread_id = isset( $_POST['thread_id'] ) ? intval( $_POST['thread_id'] ) : 0;
			if ( ! $thread_id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$thread = new WPSC_Archive_Thread( $thread_id );
			if ( ! $thread->id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			if ( self::$ticket != $thread->ticket ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$settings   = get_option( 'wpsc-gs-general', array() );
			$advanced   = get_option( 'wpsc-ms-advanced-settings', array() );
			$ip_address = $thread->ip_address ? $thread->ip_address : esc_attr__( 'Not Applicable', 'supportcandy' );
			$os         = $thread->os ? $thread->os : esc_attr__( 'Not Applicable', 'supportcandy' );
			$browser    = $thread->browser ? $thread->browser : esc_attr__( 'Not Applicable', 'supportcandy' );
			$source     = $thread->source && isset( WPSC_DF_Source::$sources[ $thread->source ] ) ? WPSC_DF_Source::$sources[ $thread->source ] : esc_attr__( 'Not Applicable', 'supportcandy' );

			ob_start();
			?>
			<div class="wpsc-thread-info">

				<div class="info-list-item">
					<div class="info-label"><?php esc_attr_e( 'Name', 'supportcandy' ); ?>:</div>
					<div class="info-val"><?php echo esc_attr( $thread->customer->name ); ?></div>
				</div>
				<?php

				if ( $current_user->is_agent && in_array( $current_user->agent->role, $settings['allow-ar-thread-email'] ) ) {
					?>
					<div class="info-list-item">
						<div class="info-label"><?php esc_attr_e( 'Email Address', 'supportcandy' ); ?>:</div>
						<div class="info-val"><?php echo esc_attr( $thread->customer->email ); ?></div>
					</div>
					<?php
				}
				?>

				<div class="info-list-item">
					<div class="info-label"><?php esc_attr_e( 'Source', 'supportcandy' ); ?>:</div>
					<div class="info-val"><?php echo esc_attr( $source ); ?></div>
				</div>

				<div class="info-list-item">
					<div class="info-label"><?php esc_attr_e( 'IP Address', 'supportcandy' ); ?>:</div>
					<div class="info-val"><?php echo esc_attr( $ip_address ); ?></div>
				</div>

				<div class="info-list-item">
					<div class="info-label"><?php esc_attr_e( 'Browser', 'supportcandy' ); ?>:</div>
					<div class="info-val"><?php echo esc_attr( $browser ); ?></div>
				</div>

				<div class="info-list-item">
					<div class="info-label"><?php esc_attr_e( 'Operating System', 'supportcandy' ); ?>:</div>
					<div class="info-val"><?php echo esc_attr( $os ); ?></div>
				</div>

				<?php
				if ( $thread->type == 'reply' && $thread->customer != $thread->ticket->customer ) {
					?>
					<div class="info-list-item">
						<div class="info-label"><?php esc_attr_e( 'Seen', 'supportcandy' ); ?>:</div>
						<div class="info-val">
							<?php echo is_object( $thread->seen ) ? esc_attr( $thread->seen->setTimezone( wp_timezone() )->format( $advanced['thread-date-format'] ) ) : esc_attr_e( 'No', 'supportcandy' ); ?>
						</div>
					</div>
					<?php
				}
				do_action( 'wpsc_thread_info_body', $thread );
				?>
			</div>
			<?php
			$body = ob_get_clean();

			ob_start();
			?>
			<button class="wpsc-button small secondary" onclick="wpsc_close_modal();">
				<?php esc_attr_e( 'Close', 'supportcandy' ); ?>
			</button>
			<?php
			$footer = ob_get_clean();

			$response = array(
				'title'  => $title,
				'body'   => $body,
				'footer' => $footer,
			);
			wp_send_json( $response );
		}

		/**
		 * Permanently delete ticket ajax request
		 *
		 * @return void
		 */
		public static function iat_delete_permanently() {

			if ( check_ajax_referer( 'wpsc_iat_delete_permanently', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$current_user = WPSC_Current_User::$current_user;
			if ( ! $current_user->is_agent ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$ticket_id = isset( $_POST['ticket_id'] ) ? intval( $_POST['ticket_id'] ) : 0;
			if ( ! $ticket_id ) {
				wp_send_json_error( __( 'Bad request!', 'supportcandy' ), 400 );
			}

			$ticket = new WPSC_Archive_Ticket( $ticket_id );
			if ( ! $ticket->id ) {
				wp_send_json_error( new WP_Error( '002', 'Something went wrong!' ), 400 );
			}

			if ( ! $current_user->agent->has_cap( 'at-delete-access' ) ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			self::delete_archive_ticket( $ticket );
		}

		/**
		 * Delete ticket permanently.
		 *
		 * @param WPSC_Ticket $ticket - ticket object.
		 * @return void
		 */
		public static function delete_archive_ticket( $ticket ) {

			WPSC_Archive_Ticket::destroy( $ticket );
			do_action( 'wpsc_ticket_delete_permanently', $ticket );
		}
	}
endif;
WPSC_Individual_Archive_Ticket::init();
