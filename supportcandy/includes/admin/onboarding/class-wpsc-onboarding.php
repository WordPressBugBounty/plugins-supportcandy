<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Onboarding' ) ) :

	/**
	 * New-customer setup wizard shell.
	 *
	 * Every step is a small independent class under this folder (each one wrapping
	 * existing SupportCandy settings/APIs - no new settings mechanism is introduced).
	 * Setup must be completed - there is no way to skip a step or the wizard.
	 * Progress is tracked in a single option, 'wpsc-onboarding-wizard':
	 *   array(
	 *       'status' => 'pending' | 'completed', // whole-wizard state.
	 *       'steps'  => array( '<step-id>' => 'completed' ), // absent = pending.
	 *   )
	 */
	final class WPSC_Onboarding {

		/**
		 * Whether the current admin screen is this wizard.
		 *
		 * @var boolean
		 */
		public static $is_current_page = false;

		/**
		 * Step to render first on this page load.
		 *
		 * @var string
		 */
		public static $current_step = '';

		/**
		 * Steps registry, keyed by step id.
		 *
		 * @var array
		 */
		private static $steps = array();

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {

			// Plug into the existing admin-menu extension points (same as any add-on).
			add_filter( 'wpsc_admin_menu_slugs', array( __CLASS__, 'add_menu_slug' ) );
			add_filter( 'wpsc_admin_submenus_data', array( __CLASS__, 'add_submenu' ) );

			// While setup is pending, restrict the admin's own menu down to just the
			// wizard - run last (PHP_INT_MAX) so this wins over every other slug any
			// add-on registered on this same filter.
			add_filter( 'wpsc_admin_menu_slugs', array( __CLASS__, 'restrict_menu_during_setup' ), PHP_INT_MAX );

			add_action( 'admin_init', array( __CLASS__, 'detect_current_page' ), 1 );
			add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ), 20 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
			add_action( 'wpsc_js_ready', array( __CLASS__, 'register_js_ready_function' ) );
			add_filter( 'wpsc_admin_localizations', array( __CLASS__, 'localizations' ) );

			add_action( 'wp_ajax_wpsc_get_onboarding_step', array( __CLASS__, 'ajax_get_step' ) );
			add_action( 'wp_ajax_wpsc_onboarding_complete_step', array( __CLASS__, 'ajax_complete_step' ) );
		}

		/**
		 * Steps registry
		 *
		 * @return array
		 */
		public static function get_steps() {

			if ( ! empty( self::$steps ) ) {
				return self::$steps;
			}

			self::$steps = apply_filters(
				'wpsc_onboarding_steps',
				array(
					'support-pages' => array(
						'label'    => esc_attr__( 'Setup', 'supportcandy' ),
						'icon'     => 'system',
						'callback' => 'WPSC_Onboarding_Step_Pages',
					),
					'ai-assistant'  => array(
						'label'    => esc_attr__( 'AI Assistant', 'supportcandy' ),
						'icon'     => 'headset',
						'callback' => 'WPSC_Onboarding_Step_AI',
					),
					'categories'    => array(
						'label'    => esc_attr__( 'Categories', 'supportcandy' ),
						'icon'     => 'subfolder',
						'callback' => 'WPSC_Onboarding_Step_Categories',
					),
					'statuses'      => array(
						'label'    => esc_attr__( 'Statuses', 'supportcandy' ),
						'icon'     => 'gps-navigation',
						'callback' => 'WPSC_Onboarding_Step_Statuses',
					),
					'fields'        => array(
						'label'    => esc_attr__( 'Fields', 'supportcandy' ),
						'icon'     => 'list-alt',
						'callback' => 'WPSC_Onboarding_Step_Fields',
					),
					'agents'        => array(
						'label'    => esc_attr__( 'Agents', 'supportcandy' ),
						'icon'     => 'users',
						'callback' => 'WPSC_Onboarding_Step_Agents',
					),
					'emails'        => array(
						'label'    => esc_attr__( 'Emails', 'supportcandy' ),
						'icon'     => 'envelope',
						'callback' => 'WPSC_Onboarding_Step_Emails',
					),
					'whats-next'    => array(
						'label'    => esc_attr__( "What's Next", 'supportcandy' ),
						'icon'     => 'widget',
						'callback' => 'WPSC_Onboarding_Step_Whats_Next',
					),
				)
			);

			return self::$steps;
		}

		/**
		 * Add our menu slug to the allow-list consumed by WPSC_Admin::load_admin_menus().
		 *
		 * @param array $slugs - existing slugs.
		 * @return array
		 */
		public static function add_menu_slug( $slugs ) {
			$slugs[] = 'wpsc-onboarding';
			return $slugs;
		}

		/**
		 * While setup is pending, only the wizard's own menu slug should be
		 * registered for a site admin - every other SupportCandy submenu (Tickets,
		 * Support Agents, Settings, add-on screens, etc.) stays hidden until they
		 * complete it. Scoped to admins only so agents (who
		 * cannot reach the wizard anyway) keep their normal Tickets/Archived menu.
		 *
		 * @param array $slugs - slugs registered so far.
		 * @return array
		 */
		public static function restrict_menu_during_setup( $slugs ) {

			if ( self::is_pending() && WPSC_Functions::is_site_admin() ) {
				return array( 'wpsc-onboarding' );
			}
			return $slugs;
		}

		/**
		 * Register our submenu data the same way add-ons do - but only while
		 * setup is still pending. Once the admin has completed it,
		 * the menu item (and with it, the page itself, since nothing else
		 * registers this route) disappears.
		 *
		 * @param array $submenus - existing submenus.
		 * @return array
		 */
		public static function add_submenu( $submenus ) {

			if ( ! self::is_pending() ) {
				return $submenus;
			}

			$submenus[] = array(
				'parent_slug' => 'wpsc-tickets',
				'page_title'  => esc_attr__( 'Setup Wizard', 'supportcandy' ),
				'menu_title'  => esc_attr__( 'Setup Wizard', 'supportcandy' ),
				'capability'  => 'manage_options',
				'menu_slug'   => 'wpsc-onboarding',
				'callback'    => array( __CLASS__, 'layout' ),
			);
			return $submenus;
		}

		/**
		 * Detect whether we're on the wizard screen and which step to open.
		 *
		 * @return void
		 */
		public static function detect_current_page() {

			self::$is_current_page = isset( $_REQUEST['page'] ) && 'wpsc-onboarding' === $_REQUEST['page']; // phpcs:ignore

			if ( ! self::$is_current_page ) {
				return;
			}

			$steps     = array_keys( self::get_steps() );
			$requested = isset( $_REQUEST['step'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['step'] ) ) : ''; // phpcs:ignore

			self::$current_step = in_array( $requested, $steps, true ) ? $requested : self::first_incomplete_step();
		}

		/**
		 * Redirect to the wizard once, right after a fresh install.
		 *
		 * @return void
		 */
		public static function maybe_redirect() {

			if ( self::$is_current_page || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
				return;
			}

			// Persistent guard: hiding the other submenus (restrict_menu_during_setup())
			// doesn't stop the top-level "Support" menu link itself, which always
			// points at the Tickets page regardless of which submenus are registered -
			// so any other SupportCandy admin page is bounced back here while pending.
			if ( self::is_pending() && WPSC_Functions::is_site_admin() && WPSC_Functions::is_wpsc_page() ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wpsc-onboarding' ) );
				exit;
			}

			// One-time nudge right after a fresh install, even from a non-wpsc screen
			// (e.g. the post-activation Plugins page).
			if ( ! get_transient( 'wpsc_onboarding_redirect' ) ) {
				return;
			}

			delete_transient( 'wpsc_onboarding_redirect' );

			if ( ! WPSC_Functions::is_site_admin() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore
				return;
			}

			wp_safe_redirect( admin_url( 'admin.php?page=wpsc-onboarding' ) );
			exit;
		}

		/**
		 * Load JS/CSS for this screen only.
		 *
		 * @return void
		 */
		public static function enqueue_scripts() {

			if ( ! self::$is_current_page ) {
				return;
			}

			wp_enqueue_script( 'wpsc-onboarding', WPSC_PLUGIN_URL . 'includes/admin/onboarding/onboarding.js', array( 'jquery', 'wpsc-admin', 'wpsc-framework' ), WPSC_VERSION, true );
			wp_enqueue_style( 'wpsc-onboarding', WPSC_PLUGIN_URL . 'includes/admin/onboarding/onboarding.css', array( 'wpsc-framework' ), WPSC_VERSION );
		}

		/**
		 * Add localizations to local JS
		 *
		 * @param array $localizations - localization.
		 * @return array
		 */
		public static function localizations( $localizations ) {

			if ( ! self::$is_current_page ) {
				return $localizations;
			}

			$localizations['onboarding_steps'] = array_keys( self::get_steps() );

			return $localizations;
		}

		/**
		 * Register JS function to call on document ready so a direct/refreshed URL
		 * opens the right step.
		 *
		 * @return void
		 */
		public static function register_js_ready_function() {

			if ( ! self::$is_current_page ) {
				return;
			}
			echo 'wpsc_onboarding_load_step(' . wp_json_encode( self::$current_step ) . ');' . PHP_EOL;
		}

		/**
		 * Current wizard state, merged with sane defaults.
		 *
		 * @return array
		 */
		public static function get_state() {

			$default = array(
				'status' => 'pending',
				'steps'  => array(),
			);
			$state = get_option( 'wpsc-onboarding-wizard', $default );
			return wp_parse_args( is_array( $state ) ? $state : array(), $default );
		}

		/**
		 * Whether the whole wizard is still pending (not yet completed).
		 * Drives both the menu restriction and the persistent redirect guard.
		 *
		 * @return boolean
		 */
		public static function is_pending() {
			return 'pending' === self::get_state()['status'];
		}

		/**
		 * Status of a single step: 'completed' or 'pending'.
		 *
		 * @param string $step_id - step id.
		 * @return string
		 */
		public static function get_step_status( $step_id ) {
			$state = self::get_state();
			return isset( $state['steps'][ $step_id ] ) ? $state['steps'][ $step_id ] : 'pending';
		}

		/**
		 * Mark a single step complete.
		 *
		 * @param string $step_id - step id.
		 * @return void
		 */
		public static function set_step_status( $step_id ) {

			$state                       = self::get_state();
			$state['steps'][ $step_id ] = 'completed';
			update_option( 'wpsc-onboarding-wizard', $state );

			// Reaching the last step marks the whole wizard done.
			$ids = array_keys( self::get_steps() );
			if ( end( $ids ) === $step_id ) {
				$state['status'] = 'completed';
				update_option( 'wpsc-onboarding-wizard', $state );
			}
		}

		/**
		 * First step that isn't completed yet, or the last step if all are.
		 *
		 * @return string
		 */
		public static function first_incomplete_step() {

			$state = self::get_state();
			foreach ( array_keys( self::get_steps() ) as $step_id ) {
				if ( empty( $state['steps'][ $step_id ] ) ) {
					return $step_id;
				}
			}
			$ids = array_keys( self::get_steps() );
			return end( $ids );
		}

		/**
		 * Shared continue footer used by most steps. Setup must be completed, so
		 * no step offers a way to skip past it.
		 *
		 * @param string  $step_id - step id.
		 * @param boolean $is_last - whether this is the final step.
		 * @return void
		 */
		public static function render_step_footer( $step_id, $is_last = false ) {
			?>
			<div class="wpsc-onboarding-footer-actions">
				<button
					type="button"
					class="wpsc-button normal primary"
					onclick="wpsc_onboarding_complete_step('<?php echo esc_attr( $step_id ); ?>', '<?php echo esc_attr( wp_create_nonce( 'wpsc_onboarding_complete_step' ) ); ?>');">
					<?php echo $is_last ? esc_attr__( 'Finish', 'supportcandy' ) : esc_attr__( 'Continue', 'supportcandy' ); ?>
				</button>
			</div>
			<?php
		}

		/**
		 * Screen layout: header, step progress nav, and an empty body filled via AJAX.
		 *
		 * @return void
		 */
		public static function layout() {

			$steps   = self::get_steps();
			$current = self::$current_step ? self::$current_step : array_key_first( $steps );
			?>
			<div class="wrap">
				<hr class="wp-header-end">
				<div id="wpsc-container" style="display:none;">
					<div class="wpsc-onboarding-header">
						<div class="wpsc-onboarding-title">
							<h1><?php esc_attr_e( 'Setup Wizard', 'supportcandy' ); ?></h1>
							<p><?php esc_attr_e( 'Complete these steps to get SupportCandy ready for your customers.', 'supportcandy' ); ?></p>
						</div>
					</div>
					<div class="wpsc-onboarding-progress">
						<?php
						$i = 1;
						foreach ( $steps as $step_id => $step ) :
							$status  = self::get_step_status( $step_id );
							$classes = array( 'wpsc-onboarding-step-nav', $step_id, $status );
							if ( $step_id === $current ) {
								$classes[] = 'active';
							}
							?>
							<div
								class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
								onclick="wpsc_onboarding_load_step('<?php echo esc_attr( $step_id ); ?>');">
								<span class="step-index">
									<?php if ( 'completed' === $status ) : ?>
										<?php WPSC_Icons::get( 'check' ); ?>
									<?php else : ?>
										<?php echo esc_html( $i ); ?>
									<?php endif; ?>
								</span>
								<label><?php echo esc_attr( $step['label'] ); ?></label>
							</div>
							<?php
							++$i;
						endforeach;
						?>
					</div>
					<div class="wpsc-onboarding-body"></div>
				</div>
			</div>
			<?php
		}

		/**
		 * AJAX: render a step's body.
		 *
		 * @return void
		 */
		public static function ajax_get_step() {

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$steps   = self::get_steps();
			$step_id = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : ''; // phpcs:ignore

			if ( ! isset( $steps[ $step_id ] ) ) {
				wp_send_json_error( __( 'Invalid step!', 'supportcandy' ), 400 );
			}

			call_user_func( array( $steps[ $step_id ]['callback'], 'render' ), $step_id );
		}

		/**
		 * AJAX: mark a step complete.
		 *
		 * @return void
		 */
		public static function ajax_complete_step() {

			if ( check_ajax_referer( 'wpsc_onboarding_complete_step', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}
			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$steps   = self::get_steps();
			$step_id = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : ''; // phpcs:ignore

			if ( ! isset( $steps[ $step_id ] ) ) {
				wp_send_json_error( __( 'Invalid step!', 'supportcandy' ), 400 );
			}

			// Steps must be completed in order. The progress nav lets an admin
			// jump straight to any step (e.g. to review one already done), so
			// without this check, clicking "Finish" on the last step could mark
			// the whole wizard complete while an earlier required step - like
			// the Support Page below - was never actually done.
			$ids          = array_keys( $steps );
			$target_index = array_search( $step_id, $ids, true );
			for ( $i = 0; $i < $target_index; $i++ ) {
				if ( 'completed' !== self::get_step_status( $ids[ $i ] ) ) {
					wp_send_json_error(
						array(
							'message'       => __( 'Please complete the previous steps first.', 'supportcandy' ),
							'redirect_step' => $ids[ $i ],
						),
						400
					);
				}
			}

			// Setup must be completed, so the Support Page is a hard requirement
			// before this (first) step can be marked done - mirrors the same
			// check the step's own render() uses to gate its Continue button.
			if ( 'support-pages' === $step_id && ! WPSC_Onboarding_Step_Pages::is_support_page_configured() ) {
				wp_send_json_error( __( 'Please create the Support Page before continuing.', 'supportcandy' ), 400 );
			}

			self::set_step_status( $step_id );
			wp_send_json_success();
		}
	}
endif;

WPSC_Onboarding::init();
