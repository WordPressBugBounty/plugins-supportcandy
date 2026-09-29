<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Onboarding_Step_Pages' ) ) :

	/**
	 * Onboarding step 1: Support Page, Open Ticket Page, guest tickets, OTP login.
	 * Wraps the existing 'wpsc-gs-page-settings' / 'wpsc-gs-general' options.
	 */
	final class WPSC_Onboarding_Step_Pages {

		const STEP_ID = 'support-pages';

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wp_ajax_wpsc_onboarding_create_page', array( __CLASS__, 'ajax_create_page' ) );
			add_action( 'wp_ajax_wpsc_onboarding_save_ticket_access', array( __CLASS__, 'ajax_save_ticket_access' ) );
		}

		/**
		 * Render step body
		 *
		 * @param string $step_id - step id.
		 * @return void
		 */
		public static function render( $step_id ) {

			$page_settings           = get_option( 'wpsc-gs-page-settings', array() );
			$general                 = get_option( 'wpsc-gs-general', array() );
			$allow_create            = isset( $general['allow-create-ticket'] ) ? (array) $general['allow-create-ticket'] : array();
			$guest_enabled           = in_array( 'guest', $allow_create, true );
			$otp_enabled             = ! empty( $page_settings['otp-login'] );
			$support_page_configured = self::is_support_page_configured();
			?>
			<div class="wpsc-onboarding-step">
				<h2><?php esc_attr_e( 'Set Up Support Pages', 'supportcandy' ); ?></h2>
				<p class="wpsc-onboarding-step-desc">
					<?php esc_attr_e( 'The Support Page is where customers view their tickets, create new tickets, and access SupportCandy - the frontend cannot work without it, so it must be created before you can continue.', 'supportcandy' ); ?>
				</p>

				<?php
				self::render_page_field(
					'support-page',
					esc_attr__( 'Support Page', 'supportcandy' ),
					esc_attr__( 'The main page where customers view their tickets, create new tickets, and access SupportCandy. Adds the [supportcandy] shortcode to the page.', 'supportcandy' ),
					$page_settings
				);
				self::render_page_field(
					'open-ticket-page',
					esc_attr__( 'Open Ticket Page (Optional)', 'supportcandy' ),
					esc_attr__( 'Lets customers look up an existing ticket by entering the Ticket ID and their registered email, then verifying with a one-time password (OTP) - no login required. The Support Page above already does all of this; only create this separate page if you want ticket lookup on its own page. Adds the [wpsc_open_ticket] shortcode to the page.', 'supportcandy' ),
					$page_settings,
					esc_attr__( 'Open Ticket Page', 'supportcandy' )
				);
				?>

				<div class="wpsc-onboarding-inline-message error wpsc-onboarding-support-page-notice" style="display:none;">
					<?php esc_attr_e( 'Please create the Support Page before continuing.', 'supportcandy' ); ?>
				</div>

				<form action="#" onsubmit="return false;" class="wpsc-frm-onboarding-ticket-access">
					<div class="wpsc-input-group">
						<div class="label-container">
							<label><?php esc_attr_e( 'Allow guest tickets?', 'supportcandy' ); ?></label>
						</div>
						<select name="guest-tickets" class="wpsc-onboarding-guest-tickets">
							<option value="0" <?php selected( ! $guest_enabled ); ?>><?php esc_attr_e( 'Disable', 'supportcandy' ); ?></option>
							<option value="1" <?php selected( $guest_enabled ); ?>><?php esc_attr_e( 'Enable', 'supportcandy' ); ?></option>
						</select>
					</div>
					<div class="wpsc-input-group wpsc-onboarding-otp-field" style="<?php echo $guest_enabled ? '' : 'display:none'; ?>">
						<div class="label-container">
							<label><?php esc_attr_e( 'Verify guests with an OTP when creating or accessing tickets?', 'supportcandy' ); ?></label>
						</div>
						<select name="otp-login">
							<option value="0" <?php selected( ! $otp_enabled ); ?>><?php esc_attr_e( 'Disable', 'supportcandy' ); ?></option>
							<option value="1" <?php selected( $otp_enabled ); ?>><?php esc_attr_e( 'Enable', 'supportcandy' ); ?></option>
						</select>
					</div>
					<input type="hidden" name="support-page-configured" class="wpsc-onboarding-support-page-configured" value="<?php echo esc_attr( $support_page_configured ? '1' : '0' ); ?>">
					<input type="hidden" name="action" value="wpsc_onboarding_save_ticket_access">
					<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_onboarding_save_ticket_access' ) ); ?>">
				</form>
				<script>
					jQuery('.wpsc-onboarding-guest-tickets').off('change').on('change', function () {
						jQuery('.wpsc-onboarding-otp-field').toggle(jQuery(this).val() === '1');
					});
				</script>

				<div class="wpsc-onboarding-footer-actions">
					<button
						type="button"
						class="wpsc-button normal primary"
						onclick="wpsc_onboarding_save_pages_step(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_onboarding_complete_step' ) ); ?>');">
						<?php esc_attr_e( 'Continue', 'supportcandy' ); ?>
					</button>
				</div>
			</div>
			<?php
			wp_die();
		}

		/**
		 * Whether the Support Page has been created (and isn't trashed). Shared
		 * by the render() gate above and the server-side check in
		 * WPSC_Onboarding::ajax_complete_step(), since Continue must not be able
		 * to bypass the client-side check by calling that endpoint directly.
		 *
		 * @return boolean
		 */
		public static function is_support_page_configured() {

			$page_settings = get_option( 'wpsc-gs-page-settings', array() );
			$page_id       = isset( $page_settings['support-page'] ) ? intval( $page_settings['support-page'] ) : 0;
			return $page_id && get_post_status( $page_id ) && 'trash' !== get_post_status( $page_id );
		}

		/**
		 * Render a single page field (support/open-ticket) with its current state.
		 *
		 * @param string $key - 'support-page' or 'open-ticket-page'.
		 * @param string $label - display label (heading), e.g. may carry a
		 *                       "(Optional)" suffix.
		 * @param string $desc - short explanation.
		 * @param array  $page_settings - current 'wpsc-gs-page-settings' option.
		 * @param string $button_label - label used in the "Create ..." button;
		 *                              defaults to $label. Pass this separately
		 *                              when $label carries a suffix (like
		 *                              "(Optional)") that shouldn't repeat on
		 *                              the button.
		 * @return void
		 */
		private static function render_page_field( $key, $label, $desc, $page_settings, $button_label = null ) {

			$button_label = null !== $button_label ? $button_label : $label;

			$page_id = isset( $page_settings[ $key ] ) ? intval( $page_settings[ $key ] ) : 0;
			$status  = $page_id && get_post_status( $page_id ) && get_post_status( $page_id ) !== 'trash';
			?>
			<div class="wpsc-onboarding-card">
				<div class="wpsc-onboarding-card-info">
					<h3><?php echo esc_html( $label ); ?></h3>
					<p><?php echo esc_html( $desc ); ?></p>
					<?php if ( $status ) : ?>
						<div class="wpsc-onboarding-status success">
							<?php WPSC_Icons::get( 'check' ); ?>
							<?php
							printf(
								/* translators: %s: linked page title, opens the page editor */
								esc_html__( 'Configured: %s', 'supportcandy' ),
								'<a href="' . esc_url( get_edit_post_link( $page_id ) ) . '" target="_blank">' . esc_html( get_the_title( $page_id ) ) . '</a>'
							);
							?>
							(<a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank"><?php esc_attr_e( 'View', 'supportcandy' ); ?></a>)
						</div>
					<?php else : ?>
						<div class="wpsc-onboarding-status pending"><?php esc_attr_e( 'Not set up yet.', 'supportcandy' ); ?></div>
					<?php endif; ?>
				</div>
				<div class="wpsc-onboarding-card-action">
					<?php if ( ! $status ) : ?>
						<button
							type="button"
							class="wpsc-button small primary"
							onclick="wpsc_onboarding_create_page('<?php echo esc_attr( $key ); ?>', this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_onboarding_create_page' ) ); ?>');">
							<?php
							/* translators: %s: page label, e.g. "Support Page" */
							printf( esc_attr__( 'Create %s', 'supportcandy' ), esc_html( $button_label ) );
							?>
						</button>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * AJAX: one-click page creation.
		 *
		 * @return void
		 */
		public static function ajax_create_page() {

			if ( check_ajax_referer( 'wpsc_onboarding_create_page', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}
			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
			if ( ! in_array( $type, array( 'support-page', 'open-ticket-page' ), true ) ) {
				wp_send_json_error( __( 'Invalid page type!', 'supportcandy' ), 400 );
			}

			$page_id = WPSC_GS_Page_Settings::create_default_page( $type );
			if ( ! $page_id ) {
				wp_send_json_error( __( 'Something went wrong!', 'supportcandy' ), 500 );
			}

			wp_send_json_success( array( 'page_id' => $page_id ) );
		}

		/**
		 * AJAX: save guest-ticket / OTP toggles onto the existing settings options.
		 *
		 * @return void
		 */
		public static function ajax_save_ticket_access() {

			if ( check_ajax_referer( 'wpsc_onboarding_save_ticket_access', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}
			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$guest_enabled = ! empty( $_POST['guest-tickets'] );
			$otp_enabled   = ! empty( $_POST['otp-login'] );

			$general      = get_option( 'wpsc-gs-general', array() );
			$allow_create = isset( $general['allow-create-ticket'] ) ? (array) $general['allow-create-ticket'] : array();
			$allow_create = array_diff( $allow_create, array( 'guest' ) );
			if ( $guest_enabled ) {
				$allow_create[] = 'guest';
			}
			$general['allow-create-ticket'] = array_values( $allow_create );
			update_option( 'wpsc-gs-general', $general );

			// OTP is only asked about (and only ever touched here) when guest tickets are enabled.
			if ( $guest_enabled ) {
				$page_settings              = get_option( 'wpsc-gs-page-settings', array() );
				$page_settings['otp-login'] = $otp_enabled ? 1 : 0;
				update_option( 'wpsc-gs-page-settings', $page_settings );
			}

			wp_send_json_success();
		}
	}
endif;

WPSC_Onboarding_Step_Pages::init();
