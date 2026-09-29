<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Onboarding_Step_Agents' ) ) :

	/**
	 * Onboarding step 6: Support Agents & Roles, in two sections.
	 *
	 * Support Agents: shows the real list of existing agents (via
	 * WPSC_Agent::find(), same as the real Support Agents screen), read-only -
	 * adding an agent is never required here, some businesses manage support
	 * themselves, so agents can be added anytime from Support Agents settings
	 * once setup is complete.
	 *
	 * Agent Roles: shows the real list of existing roles (option
	 * 'wpsc-agent-roles', same as every agent above is assigned one of). No
	 * link out to the Agent Roles screen here: every other SupportCandy
	 * submenu is hidden while setup is pending (see
	 * WPSC_Onboarding::restrict_menu_during_setup()), so that page isn't
	 * reachable until the wizard is completed.
	 */
	final class WPSC_Onboarding_Step_Agents {

		const STEP_ID = 'agents';

		/**
		 * Render step body
		 *
		 * @param string $step_id - step id.
		 * @return void
		 */
		public static function render( $step_id ) {

			$roles  = get_option( 'wpsc-agent-roles', array() );
			$agents = array_filter(
				WPSC_Agent::find( array( 'items_per_page' => 0 ) )['results'],
				function ( $agent ) {
					return $agent->is_active && ! $agent->is_agentgroup;
				}
			);
			?>
			<div class="wpsc-onboarding-step">
				<h2><?php esc_attr_e( 'Support Agents', 'supportcandy' ); ?></h2>
				<p class="wpsc-onboarding-step-desc">
					<?php esc_attr_e( 'Support agents are the members of your team who manage and respond to customer tickets. You can add the people who\'ll be working tickets anytime from Support Agents settings once setup is complete.', 'supportcandy' ); ?>
				</p>

				<?php if ( ! empty( $agents ) ) : ?>
					<ul class="wpsc-onboarding-pill-list">
						<?php foreach ( $agents as $agent ) : ?>
							<li>
								<?php echo esc_html( $agent->name ); ?>
								<?php if ( isset( $roles[ $agent->role ]['label'] ) ) : ?>
									&middot; <?php echo esc_html( $roles[ $agent->role ]['label'] ); ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<div class="wpsc-onboarding-status pending">
						<?php esc_attr_e( "You haven't added any support agents yet. Some businesses manage support themselves - you can add agents anytime.", 'supportcandy' ); ?>
					</div>
				<?php endif; ?>

				<hr class="wpsc-onboarding-divider">

				<h2><?php esc_attr_e( 'Agent Roles', 'supportcandy' ); ?></h2>
				<p class="wpsc-onboarding-step-desc">
					<?php esc_attr_e( 'Roles control what each agent can see and do - for example, whether they can view unassigned tickets, add private notes, or change ticket status. Every agent above is assigned one of the roles below.', 'supportcandy' ); ?>
				</p>

				<?php if ( ! empty( $roles ) ) : ?>
					<ul class="wpsc-onboarding-pill-list">
						<?php foreach ( $roles as $role ) : ?>
							<li><?php echo esc_html( $role['label'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<p class="wpsc-onboarding-step-desc"><?php esc_attr_e( 'You can manage roles and permissions anytime from Support Agents settings once setup is complete.', 'supportcandy' ); ?></p>

				<?php WPSC_Onboarding::render_step_footer( $step_id ); ?>
			</div>
			<?php
			wp_die();
		}
	}
endif;
