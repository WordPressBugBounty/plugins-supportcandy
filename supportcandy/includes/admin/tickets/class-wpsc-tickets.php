<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Tickets' ) ) :

	final class WPSC_Tickets {

		/**
		 * Set if current screen is tickets page
		 *
		 * @var boolean
		 */
		public static $is_current_page;

		/**
		 * Sections for this view
		 *
		 * @var [type]
		 */
		private static $sections = array();

		/**
		 * Current section to load
		 *
		 * @var [type]
		 */
		public static $current_section;

		/**
		 * Initialize this class
		 */
		public static function init() {

			// Load sections for this screen.
			add_action( 'admin_init', array( __CLASS__, 'load_sections' ) );

			// Add current section to admin localization data.
			add_filter( 'wpsc_admin_localizations', array( __CLASS__, 'localizations' ) );

			// Humbargar modal.
			add_action( 'admin_footer', array( __CLASS__, 'humbargar_menu' ) );

			// JS dynamic fucntions.
			add_action( 'wpsc_js_ready', array( __CLASS__, 'register_js_ready_function' ) );
			add_action( 'wpsc_js_after_ticket_reply', array( __CLASS__, 'js_after_ticket_reply' ) );
			add_action( 'wpsc_js_after_close_ticket', array( __CLASS__, 'js_after_close_ticket' ) );

			// agent dashboard.
			add_action( 'wp_ajax_wpsc_get_agent_dashboard', array( __CLASS__, 'get_agent_dashboard' ) );

			// show sales banner.
			add_action( 'wpsc_before_tickets_page', array( __CLASS__, 'show_sales_banner' ) );
			add_action( 'wp_ajax_wpsc_dismiss_sale_banner', array( __CLASS__, 'dismiss_sale_banner' ) );
		}

		/**
		 * Load section (nav elements) for this screen
		 *
		 * @return void
		 */
		public static function load_sections() {

			self::$is_current_page = isset( $_REQUEST['page'] ) && $_REQUEST['page'] === 'wpsc-tickets' ? true : false; // phpcs:ignore

			$current_user = WPSC_Current_User::$current_user;
			if ( ! ( $current_user->is_agent && self::$is_current_page ) ) {
				return;
			}

			// get default tab.
			$tab = $current_user->agent->get_default_tab();

			$gs = get_option( 'wpsc-gs-general' );
			$ms = get_option( 'wpsc-ms-advanced-settings' );

			// allow create ticket.
			$allow_create_ticket = in_array( $current_user->agent->role, $gs['allow-create-ticket'] ) ? true : false;

			$sections = array();
			// Supportcandy dashboard.
			if ( $current_user->is_agent && $current_user->agent->has_cap( 'dash-access' ) ) {
				$sections['dashboard'] = array(
					'slug'     => 'dashboard',
					'icon'     => 'dashboard',
					'label'    => esc_attr__( 'Dashboard', 'supportcandy' ),
					'callback' => 'wpsc_get_agent_dashboard',
				);
			}

			// ticket list.
			$sections['ticket-list'] = array(
				'slug'     => 'ticket_list',
				'icon'     => 'list-alt',
				'label'    => esc_attr__( 'Ticket List', 'supportcandy' ),
				'callback' => 'wpsc_get_ticket_list',
			);

			// new ticket.
			if ( $allow_create_ticket ) {
				$sections['new-ticket'] = array(
					'slug'     => 'new_ticket',
					'icon'     => 'plus-square',
					'label'    => esc_attr__( 'New Ticket', 'supportcandy' ),
					'callback' => 'wpsc_get_ticket_form',
				);
			}

			// my profile.
			if ( $ms['allow-my-profile'] ) {
				$sections['my-profile'] = array(
					'slug'     => 'my_profile',
					'icon'     => 'id-card',
					'label'    => esc_attr__( 'My Profile', 'supportcandy' ),
					'callback' => 'wpsc_get_user_profile',
				);
			}

			// agent profile.
			if ( $current_user->is_agent && $ms['allow-agent-profile'] ) {
				$sections['agent-profile'] = array(
					'slug'     => 'agent_profile',
					'icon'     => 'headset',
					'label'    => esc_attr__( 'Agent Profile', 'supportcandy' ),
					'callback' => 'wpsc_get_agent_profile',
				);
			}

			self::$sections        = apply_filters( 'wpsc_tickets_page_sections', $sections );
			self::$current_section = isset( $_REQUEST['section'] ) ? sanitize_text_field( $_REQUEST['section'] ) : $tab; // phpcs:ignore
		}

		/**
		 * Add localizations to local JS
		 *
		 * @param array $localizations - localization list.
		 * @return array
		 */
		public static function localizations( $localizations ) {

			if ( ! self::$is_current_page ) {
				return $localizations;
			}

			// Humbargar Titles.
			$localizations['humbargar_titles'] = self::get_humbargar_titles();

			// Current section.
			$localizations['current_section'] = self::$current_section;

			// Current ticket id.
			if ( self::$current_section === 'ticket-list' && isset( $_REQUEST['id'] ) ) { // phpcs:ignore
				$localizations['current_ticket_id'] = intval( $_REQUEST['id'] ); // phpcs:ignore
			}

			return $localizations;
		}

		/**
		 * UI foundation for this screen
		 *
		 * @return void
		 */
		public static function layout() {

			?>
			<div class="wrap">
				<hr class="wp-header-end">
				<div id="wpsc-container" style="display:none;">
					<?php do_action( 'wpsc_before_tickets_page' ); ?>
					<div class="wpsc-shortcode-container">
						<div class="wpsc-header wpsc-hidden-xs">
							<?php
							foreach ( self::$sections as $key => $section ) :
								$active = self::$current_section === $key ? 'active' : '';
								?>
								<div class="wpsc-menu-list wpsc-tickets-nav <?php echo esc_attr( $key ) . ' ' . esc_attr( $active ); ?>" onclick="<?php echo esc_attr( $section['callback'] ) . '();'; ?>">
									<?php WPSC_Icons::get( $section['icon'] ); ?>
									<label><?php echo esc_attr( $section['label'] ); ?></label>
								</div>
								<?php
							endforeach;
							?>
							<div class="wpsc-menu-list wpsc-tickets-nav log-out" onclick="wpsc_user_logout(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_user_logout' ) ); ?>');">
								<?php WPSC_Icons::get( 'log-out' ); ?>
								<label><?php echo esc_attr__( 'Logout', 'supportcandy' ); ?></label>
							</div>
						</div>
						<div class="wpsc-header wpsc-visible-xs">
							<div class="wpsc-humbargar-title">
								<?php WPSC_Icons::get( self::$sections[ self::$current_section ]['icon'] ); ?>
								<label><?php echo esc_attr( self::$sections[ self::$current_section ]['label'] ); ?></label>
							</div>
							<div class="wpsc-humbargar" onclick="wpsc_toggle_humbargar();">
								<?php WPSC_Icons::get( 'bars' ); ?>
							</div>
						</div>
						<div class="wpsc-body"></div>
						<?php
						self::load_html_snippets();
						?>
					</div>
					<?php
					if ( ! WPSC_Functions::is_paid_customer() ) {
						?>
						<div class="feature-wrapper">

							<div class="feature-card">
								<h3>Accelerate ticket resolutions using AI Assistant</h3>
								<ul>
									<li>Context-Aware Auto-Drafts: Instantly generates complete reply suggestions using your past ticket history and uploaded training data (PDFs, text files, and URLs).</li>
									<li>Instant Ticket Summaries: Automatically condenses long conversation threads and detects customer sentiment (Happy, Neutral, Unhappy) for faster agent handovers.</li>
									<li>Polished & Consistent Replies: Refines response grammar, tone, and clarity using custom, admin-defined prompts to enforce brand guidelines.</li>
									<li>Secure AI Training (RAG): Uses Retrieval-Augmented Generation to fetch accurate data from your system while automatically removing sensitive personal information (PII).</li>
								</ul>
								<a target="_blank" href="https://supportcandy.net/ai-assistant/">Learn More about AI Assistant...</a>
							</div>

							<div class="feature-card">
								<h3>Enable customers and agents to communicate directly via email</h3>
								<ul>
									<li>Bi-Directional Email Support: Allows both customers and agents to create, track, and reply to tickets directly from their personal email inboxes.</li>
									<li>Secure API Connections: Supports standard IMAP authentication alongside quick-setup OAuth APIs for Google (Gmail) and Microsoft Exchange.</li>
									<li>Smart Piping Rules: Automatically filters and assigns ticket properties (like setting priority to "High") based on keywords, sender addresses, or subject lines.</li>
									<li>Advanced Inbound Control: Block unwanted spam, import CC'd users into the ticket loop, and choose between HTML or plain-text email preferences.</li>
								</ul>
								<a target="_blank" href="https://supportcandy.net/downloads/email-piping/">Learn More about Email Piping...</a>
							</div>
							<?php
							if ( class_exists( 'WooCommerce' ) ) {
								?>
								<div class="feature-card">
									<h3>Link WooCommerce orders and products directly to support tickets</h3>
									<ul>
										<li>Contextual Ticket Creation: Adds direct "Help" buttons to WooCommerce product pages and order lists, automatically pre-selecting the relevant item when a customer opens a ticket.</li>
										<li>Instant Customer Order History: Empowers agents to view a customer’s full order history, subscription status, and lifetime spend directly inside the ticket sidebar.</li>
										<li>Unified Customer Dashboard: Enables a dedicated support tab right inside the WooCommerce customer dashboard so buyers can track their tickets alongside their orders.</li>
										<li>Backend Ticket Creation: Allows store managers and administrators to instantly create a support ticket from the backend of any specific WooCommerce order.</li>
									</ul>
									<a target="_blank" href="https://supportcandy.net/downloads/woocommerce-integration/">Learn More about WooCommerce Integration...</a>
								</div>
								<?php
							}
							?>
							<div class="feature-card">
								<h3>Automate your workflow</h3>
								<ul>
									<li>Event-Driven Automation: Create "Automatic Workflows" triggered instantly by system events like a new ticket being created or a status change.</li>
									<li>On-Demand Execution: Set up "Manual Workflows" that agents can trigger with a single click from a dedicated widget when specific conditions are met.</li>
									<li>Advanced Conditional Logic: Establish precise rules to automatically assign agents, update custom fields, change ticket statuses, or add private notes.</li>
									<li>Customizable Operations: Tailor rules to match your team’s exact internal processes, reducing manual overhead and speeding up resolution times.</li>
								</ul>
								<a target="_blank" href="https://supportcandy.net/downloads/workflows/">Learn More about Workflows...</a>
							</div>

							<div class="feature-card">
								<h3>Measure and improve your support quality with customer feedback</h3>
								<ul>
									<li>Automated Survey Triggers: Automatically email survey links to customers a set number of days after a ticket closes, or trigger an instant feedback window the moment they close it themselves.</li>
									<li>One-Click Email Ratings: Allow customers to rate their experience directly from their inbox using intuitive rating links (e.g., Excellent, Good, Bad).</li>
									<li>Customizable Rating Scales: Easily add, modify, or delete rating tiers and set unique confirmation messages tailored to each rating type.</li>
									<li>Centralized Feedback Reports: Track, filter, and analyze submissions through a dedicated "Customer Feedback" admin dashboard or directly within individual ticket widgets.</li>
								</ul>
								<a target="_blank" href="https://supportcandy.net/downloads/satisfaction-survey/">Learn More about Satisfaction Survey...</a>
							</div>
						</div>
						<style>
							.feature-wrapper {
								display: flex;
								flex-wrap: wrap;
								gap: 20px;
								margin-top: 20px;
							}

							.feature-card {
								flex: 1 1 350px;
								min-width: 350px;
								background: #fff;
								padding: 20px;
								border-radius: 6px;
								box-sizing: border-box;
							}

							.feature-card h3 {
								font-size: 15px;
								margin-bottom: 10px;
								margin-top: 0;
							}

							.feature-card ul {
								padding-left: 18px;
								margin-bottom: 10px;
								list-style: disc !important;
							}

							.feature-card ul li {
								font-size: 13px;
							}

							.feature-card a {
								font-size: 13px;
								color: #4a5bdc;
								text-decoration: none;
							}

							.feature-card a:hover {
								text-decoration: underline;
							}
						</style>
						<?php
					}
					?>
				</div>
			</div>
			<?php
		}

		/**
		 * Print humbargar menu in footer
		 *
		 * @return void
		 */
		public static function humbargar_menu() {

			if ( ! self::$is_current_page ) {
				return;
			}

			?>
			<div class="wpsc-humbargar-overlay" onclick="wpsc_toggle_humbargar();" style="display:none"></div>
			<div class="wpsc-humbargar-menu" style="display:none">
				<div class="box-inner">
					<div class="wpsc-humbargar-close" onclick="wpsc_toggle_humbargar();">
						<?php WPSC_Icons::get( 'times' ); ?>
					</div>
					<?php
					foreach ( self::$sections as $key => $section ) :

						$active = self::$current_section === $key ? 'active' : '';
						?>
						<div 
							class="wpsc-humbargar-menu-item <?php echo esc_attr( $key ) . ' ' . esc_attr( $active ); ?>"
							onclick="<?php echo esc_attr( $section['callback'] ) . '(true);'; ?>">
							<?php WPSC_Icons::get( $section['icon'] ); ?>
							<label><?php echo esc_attr( $section['label'] ); ?></label>
						</div>
					<?php endforeach; ?>
					<div class="wpsc-humbargar-menu-item log-out" onclick="wpsc_user_logout(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_user_logout' ) ); ?>');">
						<?php WPSC_Icons::get( 'log-out' ); ?>
						<label><?php echo esc_attr__( 'Logout', 'supportcandy' ); ?></label>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Humbargar mobile titles to be used in localizations
		 *
		 * @return array
		 */
		private static function get_humbargar_titles() {

			$titles = array();
			foreach ( self::$sections as $section ) {

				ob_start();
				WPSC_Icons::get( $section['icon'] );
				echo '<label>' . esc_attr( $section['label'] ) . '</label>';
				$titles[ $section['slug'] ] = ob_get_clean();
			}
			return $titles;
		}

		/**
		 * Register JS functions to call on document ready
		 *
		 * @return void
		 */
		public static function register_js_ready_function() {

			if ( ! self::$is_current_page ) {
				return;
			}

			echo esc_attr( self::$sections[ self::$current_section ]['callback'] ) . '();' . PHP_EOL;
		}

		/**
		 * After ticket reply
		 *
		 * @return void
		 */
		public static function js_after_ticket_reply() {

			if ( ! self::$is_current_page ) {
				return;
			}

			$current_user     = WPSC_Current_User::$current_user;
			$agent_settings   = get_option( 'wpsc-tl-ms-agent-view' );
			$call_to_function = $agent_settings['ticket-reply-redirect'] == 'ticket-list' ? 'wpsc_get_ticket_list();' : 'wpsc_get_individual_ticket(ticket_id)';
			echo esc_attr( $call_to_function ) . PHP_EOL;
		}

		/**
		 * JS after close ticket
		 *
		 * @return void
		 */
		public static function js_after_close_ticket() {

			if ( ! self::$is_current_page ) {
				return;
			}
			echo 'wpsc_get_ticket_list();' . PHP_EOL;
		}

		/**
		 * Load HTML snippets that can be used by js to load dynamically
		 *
		 * @return void
		 */
		public static function load_html_snippets() {
			?>

			<div class="wpsc-page-snippets" style="display: none;">
				<div class="wpsc-editor-attachment upload-waiting">
					<div class="attachment-label"></div>
					<div class="attachment-remove" onclick="wpsc_remove_attachment(this)">
						<?php WPSC_Icons::get( 'times' ); ?>
					</div>
					<div class="attachment-waiting"></div>
				</div>
			</div>
			<?php
		}

		/**
		 * Get current agent dashboard layout
		 *
		 * @return void
		 */
		public static function get_agent_dashboard() {

			$settings = get_option( 'wpsc-ap-dashboard' );
			$db_gs = get_option( 'wpsc-db-gs-settings', array() );
			?>
			<div class="wpsc-dashboard-view">
				<div class="wpsc-dash-widgets-row">
					<?php
					$cards = get_option( 'wpsc-dashboard-cards', array() );
					foreach ( $cards as $slug => $card ) {
						if ( ! $card['is_enable'] ) {
							continue;
						}
						if ( ! class_exists( $card['class'] ) ) {
							continue;
						}
						$card['class']::print_dashboard_card( $slug, $card );
					}
					?>
				</div>
				<div class="wpsc-dash-widgets-row wpsc-dashboard-widget-view">
					<?php
					$widgets = get_option( 'wpsc-dashboard-widgets', array() );
					foreach ( $widgets as $slug => $widget ) {
						if ( ! $widget['is_enable'] ) {
							continue;
						}
						if ( ! class_exists( $widget['class'] ) ) {
							continue;
						}
						$widget['class']::print_dashboard_widget( $slug, $widget );
					}
					?>
				</div>
			</div>
			<?php
			if ( $db_gs['dash-auto-refresh'] ) {
				?>
				<script>
					jQuery(document).ready(function() {
						refresh_dashboard();
					});
					var wpsc_db_refresh_timeout;
					function refresh_dashboard() {
						
						if( supportcandy.current_section !== "dashboard" ) {
							supportcandy.db_auto_refresh_schedule = false;
							return;
						}

						// Clear the previous timeout, if any.
						clearTimeout( wpsc_db_refresh_timeout );

						wpsc_db_refresh_timeout = setTimeout(function () {
							supportcandy.db_auto_refresh_schedule = true;
							refresh_dashboard();
						}, 300000);

						if (
							typeof supportcandy.db_auto_refresh_schedule === "undefined" ||
							supportcandy.db_auto_refresh_schedule === false
						) {
							return;
						}
						supportcandy.db_auto_refresh_schedule = false;
						wpsc_get_agent_dashboard();
					}
				</script>
				<?php
			}
			?>
			<style>
				.wpsc-dash-widget-small svg{
					color: <?php echo esc_attr( $settings['card-body-svg-color'] ); ?>;
				}
				.wpsc-dash-widget-small{
					background-color:<?php echo esc_attr( $settings['card-body-bg-color'] ); ?> !important;
				}
				.wpsc-dash-widget-small, .wpsc-dash-widget-small h2{
					color:<?php echo esc_attr( $settings['card-body-text-color'] ); ?> !important;
				}
				.wpsc-dash-widget-mid, .wpsc-dash-widget-large{
					background-color:<?php echo esc_attr( $settings['widget-body-bg-color'] ); ?> !important;
					color:<?php echo esc_attr( $settings['widget-body-text-color'] ); ?> !important;
				}
				.wpsc-dash-widget-header svg {
					color:<?php echo esc_attr( $settings['widget-body-svg-color'] ); ?>;
				}
			</style>
			<?php
			wp_die();
		}

		/**
		 * Show sales banner
		 *
		 * @return void
		 */
		public static function show_sales_banner() {
			if ( current_user_can( 'manage_options' ) &&
					! ( class_exists( 'WPSC_EP' ) || class_exists( 'WPSC_Workflows' ) || class_exists( 'WPSC_SLA' ) || class_exists( 'WPSC_SF' ) || class_exists( 'WPSC_WOO' ) ) &&
						current_time( 'Y-m-d' ) < '2025-12-31' &&
						! get_transient( 'wpsc_sale_banner_timeout' ) ) {
				?>
				<div class="wpsc-sale-banner" style="display: flex; justify-content: space-between; flex-wrap: wrap;background-color: #000;color: #fff;padding: 10px 15px; margin-bottom: 10px; border-radius: 5px;">
					<p style="margin:0; font-size: 12px; font-weight: 500;">
						Get 50% off on all SupportCandy plans, starting from $39.50 (USD).
					</p>
					<div style="display: flex; align-items: center; gap: 15px;">
						<a href="https://supportcandy.net/pricing?utm_source=plugin&utm_medium=banner&utm_campaign=plugin_flash_sale" target="_blank" style="color: #fff; text-decoration: underline;">View Plans</a>
						<a href="#" id="wpsc-sale-banner-dismiss" style="color: #fff; text-decoration: underline;">Dismiss</a>
					</div>
				</div>
				<?php
			}
			?>
			<script>
				jQuery(document).ready(function() {
					jQuery('#wpsc-sale-banner-dismiss').on('click', function(e){
						e.preventDefault();
						jQuery.post(ajaxurl, { action: 'wpsc_dismiss_sale_banner' }, function(){
							window.location.reload();
						});
					});
				});
			</script>
			<?php
		}

		/**
		 * Dismiss the sales banner
		 *
		 * @return void
		 */
		public static function dismiss_sale_banner() {
			set_transient( 'wpsc_sale_banner_timeout', 1, DAY_IN_SECONDS );
			wp_die();
		}
	}
endif;

WPSC_Tickets::init();

