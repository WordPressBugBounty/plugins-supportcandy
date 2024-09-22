<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_DBW_Recent_Activities' ) ) :

	final class WPSC_DBW_Recent_Activities {

		/**
		 * Widget slug
		 *
		 * @var string
		 */
		public static $widget = 'recent-activities';

		/**
		 * Initialize this class
		 */
		public static function init() {

			// Get recent activities.
			add_action( 'wp_ajax_wpsc_dash_get_recent_activities', array( __CLASS__, 'get_recent_activities' ) );
			add_action( 'wp_ajax_nopriv_wpsc_dash_get_recent_activities', array( __CLASS__, 'get_recent_activities' ) );
		}

		/**
		 * Recent activities
		 *
		 * @param $slug   $slug - slug name.
		 * @param $widget $widget - widget array.
		 * @return void
		 */
		public static function print_dashboard_widget( $slug, $widget ) {

			$current_user = WPSC_Current_User::$current_user;
			if ( $current_user->is_guest ||
				! ( $current_user->is_agent && in_array( $current_user->agent->role, $widget['allowed-agent-roles'] ) ) ||
				! WPSC_Functions::is_site_admin()
			) {
				return;
			}
			?>
			<div class="wpsc-dash-widget wpsc-dash-widget-mid wpsc-<?php echo esc_attr( $slug ); ?>">
				<div class="wpsc-dash-widget-header">
					<div class="wpsc-dashboard-widget-icon-header">
						<?php WPSC_Icons::get( 'list' ); ?>
						<span>
							<?php
							$title = $widget['title'] ? WPSC_Translations::get( 'wpsc-dashboard-widget-' . $slug, stripslashes( htmlspecialchars( $widget['title'] ) ) ) : stripslashes( htmlspecialchars( $widget['title'] ) );
							echo esc_attr( $title );
							?>
						</span>
					</div>
					<?php
					if ( $current_user->agent->has_cap( 'backend-access' ) ) {
						?>
						<div class="wpsc-dash-widget-actions">
							<a href="<?php echo esc_url_raw( admin_url( 'admin.php?page=wpsc-recent-activities' ) ); ?>" target="__blank"><?php echo esc_attr__( 'View All', 'supportcandy' ); ?></a>
						</div>
						<?php
					}
					?>
				</div>
				<div class="wpsc-dash-widget-content wpsc-dbw-info" id="wpsc-dash-recent-activities"></div>
			</div>
			<script>
				wpsc_dash_get_recent_activities();
				function wpsc_dash_get_recent_activities() {
					jQuery('#wpsc-dash-recent-activities').html( supportcandy.loader_html );
					var data = { action: 'wpsc_dash_get_recent_activities', view: supportcandy.is_frontend, _ajax_nonce: supportcandy.nonce };
					jQuery.post(
						supportcandy.ajax_url,
						data,
						function (response) {
							jQuery('#wpsc-dash-recent-activities').html(response.html);
						}
					);
				}
			</script>
			<?php
		}

		/**
		 * Get recent activities
		 *
		 * @return void
		 */
		public static function get_recent_activities() {

			if ( check_ajax_referer( 'general', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$view = isset( $_POST['view'] ) ? sanitize_text_field( wp_unslash( $_POST['view'] ) ) : '0';

			$current_user = WPSC_Current_User::$current_user;
			$widgets = get_option( 'wpsc-dashboard-widgets', array() );
			if ( $current_user->is_guest ||
				! ( $current_user->is_agent && in_array( $current_user->agent->role, $widgets[ self::$widget ]['allowed-agent-roles'] ) )
			) {
				wp_send_json_error( 'Unauthorised request!', 401 );
			}

			$logs = WPSC_RA_Logs::get_activity_logs( 10, 1 );
			$now = new DateTime();
			ob_start();
			?>
			<div class="wpsc-widget-list-section">
				<?php
				if ( $logs['total_items'] ) {
					foreach ( $logs['results'] as $log ) {

						$url = WPSC_Functions::get_ticket_url( $log->ticket->id, $view );
						$time_ago = WPSC_Functions::date_interval_highest_unit_ago( $log->date_created->diff( $now ) );

						if ( $log->type == 'report' ) {
							?>
							<div class="wpsc-widget-list"><?php echo esc_attr( $log->customer->name ) . ' ' . esc_attr__( 'created a ticket', 'supportcandy' ); ?>
								<a href="<?php echo esc_attr( $url ); ?>" target="_blank">#<?php echo esc_attr( $log->ticket->id ) . ' ' . esc_attr( $log->ticket->subject ); ?></a><?php echo esc_attr( ' (' . $time_ago . ')' ); ?>
							</div>
							<?php
						} elseif ( $log->type == 'reply' ) {
							?>
							<div class="wpsc-widget-list"><?php echo esc_attr( $log->customer->name ) . ' ' . esc_attr__( 'replied to ticket', 'supportcandy' ); ?>
								<a href="<?php echo esc_attr( $url ); ?>" target="_blank">#<?php echo esc_attr( $log->ticket->id ); ?></a><?php echo esc_attr( ' (' . $time_ago . ')' ); ?>
							</div>
							<?php
						} elseif ( $log->type == 'note' ) {
							?>
							<div class="wpsc-widget-list"><?php echo esc_attr( $log->customer->name ) . ' ' . esc_attr__( 'added a note to ticket', 'supportcandy' ); ?>
								<a href="<?php echo esc_attr( $url ); ?>" target="_blank">#<?php echo esc_attr( $log->ticket->id ); ?></a><?php echo esc_attr( ' (' . $time_ago . ')' ); ?>
							</div>
							<?php
						} elseif ( $log->type == 'log' ) {
							if ( ! $log->customer ) {
								continue;
							}
							$body = json_decode( $log->body );
							$is_json = ( json_last_error() == JSON_ERROR_NONE ) ? true : false;
							if ( $is_json ) {
								$cf = WPSC_Custom_Field::get_cf_by_slug( $body->slug );
								if ( ! $cf ) {
									continue;
								}
								?>
								<div class="wpsc-widget-list">
									<?php echo wp_kses_post( $cf->type::print_activity( $cf, $log, $body, $view ) ); ?><?php echo esc_attr( ' (' . $time_ago . ')' ); ?>
								</div>
								<?php
							}
						}
					}
				} else {
					echo esc_attr__( 'Record not found!', 'supportcandy' );
				}
				?>
			</div>
			<?php
			$table = ob_get_clean();
			wp_send_json( array( 'html' => $table ) );
		}
	}
endif;
WPSC_DBW_Recent_Activities::init();
