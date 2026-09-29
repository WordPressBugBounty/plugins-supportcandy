<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Onboarding_Step_Statuses' ) ) :

	/**
	 * Onboarding step 4: Statuses & Priorities (informational).
	 *
	 * Read-only summary of the existing defaults via WPSC_Status/WPSC_Priority -
	 * no changes are forced. No link out to Settings here: every other
	 * SupportCandy submenu is hidden while setup is pending (see
	 * WPSC_Onboarding::restrict_menu_during_setup()), so that page isn't
	 * reachable until the wizard is completed.
	 */
	final class WPSC_Onboarding_Step_Statuses {

		const STEP_ID = 'statuses';

		/**
		 * Render step body
		 *
		 * @param string $step_id - step id.
		 * @return void
		 */
		public static function render( $step_id ) {

			$statuses   = WPSC_Status::find( array( 'items_per_page' => 0 ) )['results'];
			$priorities = WPSC_Priority::find( array( 'items_per_page' => 0 ) )['results'];
			?>
			<div class="wpsc-onboarding-step">
				<h2><?php esc_attr_e( 'Statuses & Priorities', 'supportcandy' ); ?></h2>
				<p class="wpsc-onboarding-step-desc">
					<?php esc_attr_e( 'Statuses show where a ticket is in your support process, while priorities help your team identify which tickets need attention first. The defaults below work well for most teams - you can continue as-is or customize them anytime.', 'supportcandy' ); ?>
				</p>

				<div class="wpsc-onboarding-card stacked">
					<div class="wpsc-onboarding-card-info">
						<h3><?php WPSC_Icons::get( 'gps-navigation' ); ?> <?php esc_attr_e( 'Statuses', 'supportcandy' ); ?></h3>
						<ul class="wpsc-onboarding-tag-list">
							<?php foreach ( $statuses as $status ) : ?>
								<li style="background-color:<?php echo esc_attr( $status->bg_color ); ?>;color:<?php echo esc_attr( $status->color ); ?>;"><?php echo esc_html( $status->name ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>

				<div class="wpsc-onboarding-card stacked">
					<div class="wpsc-onboarding-card-info">
						<h3><?php WPSC_Icons::get( 'prioritize' ); ?> <?php esc_attr_e( 'Priorities', 'supportcandy' ); ?></h3>
						<ul class="wpsc-onboarding-tag-list">
							<?php foreach ( $priorities as $priority ) : ?>
								<li style="background-color:<?php echo esc_attr( $priority->bg_color ); ?>;color:<?php echo esc_attr( $priority->color ); ?>;"><?php echo esc_html( $priority->name ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>

				<p class="wpsc-onboarding-step-desc"><?php esc_attr_e( 'You can review and customize these anytime from Settings once setup is complete.', 'supportcandy' ); ?></p>

				<?php WPSC_Onboarding::render_step_footer( $step_id ); ?>
			</div>
			<?php
			wp_die();
		}
	}
endif;
