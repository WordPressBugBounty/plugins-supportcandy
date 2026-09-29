<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Onboarding_Step_Whats_Next' ) ) :

	/**
	 * Onboarding step 8: What's Next - optional add-ons showcase.
	 *
	 * Visually similar to (and reuses the same images/links as) the existing
	 * WPSC_Addons showcase page, curated down to a handful of add-ons most
	 * relevant to a new install. Purely informational - nothing is activated
	 * or purchased from here.
	 *
	 * The commerce and "SLA slot" cards adapt to what's already running on the
	 * site, using the exact same base-plugin detection each of SupportCandy's
	 * own integration add-ons already uses internally (see wpsc-woocommerce,
	 * wpsc-edd, wpsc-gravity-forms, wpsc-lms), so it isn't duplicated here:
	 *  - WooCommerce is replaced by Easy Digital Downloads when EDD is present.
	 *  - SLA is replaced by LMS Integration when a supported LMS is present,
	 *    else by Gravity Forms Integration when Gravity Forms is present.
	 *
	 * Each card's "Learn More" goes to an external supportcandy.net URL, so it's
	 * unaffected by every other SupportCandy submenu being hidden while setup is
	 * pending - there's no "View all Pro Features" link into wpsc-add-ons here
	 * for the same reason that link would use (see
	 * WPSC_Onboarding::restrict_menu_during_setup()).
	 */
	final class WPSC_Onboarding_Step_Whats_Next {

		const STEP_ID = 'whats-next';

		/**
		 * Render step body
		 *
		 * @param string $step_id - step id.
		 * @return void
		 */
		public static function render( $step_id ) {

			$addons = self::get_addons();
			?>
			<div class="wpsc-onboarding-step">
				<h2><?php esc_attr_e( "What's Next?", 'supportcandy' ); ?></h2>
				<p class="wpsc-onboarding-step-desc"><?php esc_attr_e( "You're all set! Here are some pro features you can explore whenever you're ready - nothing here is required.", 'supportcandy' ); ?></p>

				<div class="wpsc-onboarding-addon-grid">
					<?php foreach ( $addons as $addon ) : ?>
						<div class="wpsc-onboarding-addon-card">
							<img src="<?php echo esc_url( WPSC_PLUGIN_URL . 'asset/images/' . $addon['image'] ); ?>" alt="<?php echo esc_attr( $addon['title'] ); ?>">
							<p><?php echo esc_html( $addon['desc'] ); ?></p>
							<a class="wpsc-button small secondary" href="<?php echo esc_url( $addon['url'] ); ?>" target="_blank"><?php esc_attr_e( 'Learn More', 'supportcandy' ); ?></a>
						</div>
					<?php endforeach; ?>
				</div>

				<?php WPSC_Onboarding::render_step_footer( $step_id, true ); ?>
			</div>
			<?php
			wp_die();
		}

		/**
		 * Curated add-on list, adapted to the site's existing plugins.
		 *
		 * @return array
		 */
		private static function get_addons() {

			$addons = array(
				'email-piping' => array(
					'title' => esc_attr__( 'Email Piping', 'supportcandy' ),
					'desc'  => esc_attr__( 'Let customers and agents create and reply to tickets directly from email.', 'supportcandy' ),
					'image' => 'email-piping.png',
					'url'   => 'https://supportcandy.net/downloads/email-piping',
				),
				'survey'       => array(
					'title' => esc_attr__( 'Satisfaction Survey', 'supportcandy' ),
					'desc'  => esc_attr__( 'Collect customer feedback and ratings for each ticket to see how your team is doing.', 'supportcandy' ),
					'image' => 'satisfaction-survey.png',
					'url'   => 'https://supportcandy.net/downloads/satisfaction-survey',
				),
				'workflow'     => array(
					'title' => esc_attr__( 'Workflows', 'supportcandy' ),
					'desc'  => esc_attr__( 'Automate repetitive ticket actions with rule-based workflows.', 'supportcandy' ),
					'image' => 'workflows.png',
					'url'   => 'https://supportcandy.net/downloads/workflows',
				),
				'commerce'     => array(
					'title' => esc_attr__( 'WooCommerce', 'supportcandy' ),
					'desc'  => esc_attr__( 'Let customers pick their WooCommerce orders/products in the ticket form, and let agents view order details from the ticket.', 'supportcandy' ),
					'image' => 'woocommerce.png',
					'url'   => 'https://supportcandy.net/downloads/woocommerce-integration',
				),
				'sla'          => array(
					'title' => esc_attr__( 'SLA', 'supportcandy' ),
					'desc'  => esc_attr__( 'Set and track response/resolution time targets for different ticket types.', 'supportcandy' ),
					'image' => 'sla.png',
					'url'   => 'https://supportcandy.net/downloads/sla',
				),
				'reports'      => array(
					'title' => esc_attr__( 'Reports', 'supportcandy' ),
					'desc'  => esc_attr__( 'Measure and improve the efficiency of your support with advanced reporting.', 'supportcandy' ),
					'image' => 'reports.png',
					'url'   => 'https://supportcandy.net/downloads/reports',
				),
			);

			// Easy Digital Downloads and WooCommerce are alternatives for selling
			// digital products - show whichever one the site actually runs.
			if ( class_exists( 'Easy_Digital_Downloads' ) ) {
				$addons['commerce'] = array(
					'title' => esc_attr__( 'Easy Digital Downloads', 'supportcandy' ),
					'desc'  => esc_attr__( 'Let customers pick their EDD orders/products in the ticket form, and let agents view order details from the ticket.', 'supportcandy' ),
					'image' => 'edd.png',
					'url'   => 'https://supportcandy.net/downloads/edd-integration',
				);
			}

			// If the site already runs a supported LMS or Gravity Forms, that
			// integration is more immediately useful than SLA tracking.
			$has_lms = class_exists( 'LifterLMS' ) || function_exists( 'Tutor' ) || class_exists( 'LearnPress' );
			if ( $has_lms ) {
				$addons['sla'] = array(
					'title' => esc_attr__( 'LMS Integration', 'supportcandy' ),
					'desc'  => esc_attr__( 'Let students raise tickets tied to their courses, and let agents see enrollment details from the ticket.', 'supportcandy' ),
					'image' => 'lms-integration.png',
					'url'   => 'https://supportcandy.net/downloads/lms-integration',
				);
			} elseif ( class_exists( 'GFForms' ) ) {
				$addons['sla'] = array(
					'title' => esc_attr__( 'Gravity Forms Integration', 'supportcandy' ),
					'desc'  => esc_attr__( 'Turn Gravity Forms submissions into SupportCandy tickets automatically.', 'supportcandy' ),
					'image' => 'gravity-forms.png',
					'url'   => 'https://supportcandy.net/downloads/gravity-forms-integration',
				);
			}

			return apply_filters( 'wpsc_onboarding_whats_next_addons', array_values( $addons ) );
		}
	}
endif;
