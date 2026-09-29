<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Onboarding_Step_Emails' ) ) :

	/**
	 * Onboarding step 7: Review Email Notifications.
	 *
	 * Collects From Name / From Email and merges them into the existing
	 * 'wpsc-en-general' option (same keys the real Email Notifications > General
	 * Settings screen uses) via a dedicated save action - it only ever touches
	 * those two keys, unlike the real screen's save handler which rewrites the
	 * whole option from its own full form (blocked-emails, cron count, etc.),
	 * so this can't silently clear settings this step never asked about.
	 *
	 * The template list below is a read-only summary of just the 4 default
	 * 'wpsc-email-templates' entries (keys '1'-'4', seeded in
	 * WPSC_Installation::initial_setup()) - add-ons such as SLA append their own
	 * templates to the same option under later keys, and this step intentionally
	 * does not list those. No Edit link here: every other SupportCandy submenu
	 * is hidden while setup is pending (see
	 * WPSC_Onboarding::restrict_menu_during_setup()), so the Email Notifications
	 * screen isn't reachable until the wizard is completed - no
	 * template data is duplicated or edited here either way.
	 */
	final class WPSC_Onboarding_Step_Emails {

		const STEP_ID = 'emails';

		/**
		 * Initialize this class
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wp_ajax_wpsc_onboarding_save_email_general', array( __CLASS__, 'ajax_save_email_general' ) );
		}

		/**
		 * Render step body
		 *
		 * @param string $step_id - step id.
		 * @return void
		 */
		public static function render( $step_id ) {

			$en_general     = get_option( 'wpsc-en-general', array() );
			$templates      = get_option( 'wpsc-email-templates', array() );
			$default_ids    = array( '1', '2', '3', '4' );
			$default_emails = array_intersect_key( $templates, array_flip( $default_ids ) );
			?>
			<div class="wpsc-onboarding-step">
				<h2><?php esc_attr_e( 'Email Sender', 'supportcandy' ); ?></h2>
				<p class="wpsc-onboarding-step-desc">
					<?php esc_attr_e( 'This is the name and address customers and agents will see on every notification SupportCandy sends. Set it once here - you can fine-tune branding and other delivery options anytime from Email Notifications settings.', 'supportcandy' ); ?>
				</p>

				<form action="#" onsubmit="return false;" class="wpsc-frm-onboarding-email-general">
					<div class="wpsc-input-group">
						<div class="label-container">
							<label><?php esc_attr_e( 'From Name', 'supportcandy' ); ?></label>
						</div>
						<input type="text" name="from-name" value="<?php echo esc_attr( $en_general['from-name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Your Company Support', 'supportcandy' ); ?>" autocomplete="off" required>
					</div>
					<div class="wpsc-input-group">
						<div class="label-container">
							<label><?php esc_attr_e( 'From Email', 'supportcandy' ); ?></label>
						</div>
						<input type="email" name="from-email" value="<?php echo esc_attr( $en_general['from-email'] ?? '' ); ?>" placeholder="<?php echo esc_attr( 'e.g. support@' . wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>" autocomplete="off" required>
					</div>
					<input type="hidden" name="action" value="wpsc_onboarding_save_email_general">
					<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpsc_onboarding_save_email_general' ) ); ?>">
				</form>
				<div class="wpsc-onboarding-inline-message"></div>

				<hr class="wpsc-onboarding-divider">

				<h2><?php esc_attr_e( 'Default Notification Templates', 'supportcandy' ); ?></h2>
				<p class="wpsc-onboarding-step-desc">
					<?php esc_attr_e( 'SupportCandy automatically sends these four notifications when a ticket is created, replied to, or closed. You can customize the subject line and message for any of them, along with branding and other delivery options, anytime from Email Notifications settings once setup is complete.', 'supportcandy' ); ?>
				</p>
				<ul class="wpsc-onboarding-pill-list">
					<?php foreach ( $default_emails as $template ) : ?>
						<li><?php echo esc_html( $template['title'] ?? '' ); ?></li>
					<?php endforeach; ?>
				</ul>

				<div class="wpsc-onboarding-notice">
					<?php esc_attr_e( 'SupportCandy does not provide its own mail delivery. For more reliable email delivery, we recommend using a WordPress SMTP plugin to send SupportCandy emails through a proper mail server/provider.', 'supportcandy' ); ?>
				</div>

				<div class="wpsc-onboarding-footer-actions">
					<button
						type="button"
						class="wpsc-button normal primary"
						onclick="wpsc_onboarding_save_email_step(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_onboarding_complete_step' ) ); ?>');">
						<?php esc_attr_e( 'Continue', 'supportcandy' ); ?>
					</button>
				</div>
			</div>
			<?php
			wp_die();
		}

		/**
		 * AJAX: merge From Name / From Email into the existing 'wpsc-en-general'
		 * option, leaving every other key (blocked-emails, cron-email-count, etc.)
		 * untouched.
		 *
		 * @return void
		 */
		public static function ajax_save_email_general() {

			if ( check_ajax_referer( 'wpsc_onboarding_save_email_general', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}
			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( __( 'Unauthorized access!', 'supportcandy' ), 401 );
			}

			$from_name      = isset( $_POST['from-name'] ) ? sanitize_text_field( wp_unslash( $_POST['from-name'] ) ) : '';
			$raw_from_email = isset( $_POST['from-email'] ) ? sanitize_text_field( wp_unslash( $_POST['from-email'] ) ) : '';

			if ( '' === trim( $from_name ) ) {
				wp_send_json_error( __( 'From Name is required.', 'supportcandy' ), 400 );
			}
			if ( '' === trim( $raw_from_email ) ) {
				wp_send_json_error( __( 'From Email is required.', 'supportcandy' ), 400 );
			}

			// Validate the raw input, not the sanitized result - sanitize_email()
			// silently reduces malformed input to an empty string rather than
			// leaving something is_email() would still reject, so checking the
			// sanitized value can never actually catch a bad address.
			if ( ! is_email( $raw_from_email ) ) {
				wp_send_json_error( __( 'Please enter a valid email address.', 'supportcandy' ), 400 );
			}
			$from_email = sanitize_email( $raw_from_email );

			$en_general               = get_option( 'wpsc-en-general', array() );
			$en_general['from-name']  = $from_name;
			$en_general['from-email'] = $from_email;
			update_option( 'wpsc-en-general', $en_general );

			wp_send_json_success();
		}
	}
endif;

WPSC_Onboarding_Step_Emails::init();
