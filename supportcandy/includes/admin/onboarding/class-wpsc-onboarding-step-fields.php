<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Onboarding_Step_Fields' ) ) :

	/**
	 * Onboarding step 5: Ticket Fields & Ticket Form.
	 *
	 * Purely educational - data-driven throughout, but with no links out to
	 * Settings: every other SupportCandy submenu is hidden while setup is
	 * pending (see WPSC_Onboarding::restrict_menu_during_setup()), so those
	 * pages aren't reachable until the wizard is completed. Each
	 * field-type card previews the real field names on this site (via
	 * WPSC_Custom_Field::$custom_fields, already loaded for every request), and
	 * the Ticket Form section lists the real fields currently on the
	 * customer-facing new-ticket form (option 'wpsc-tff', keyed by field slug).
	 * No field data is edited here - this step is a map, not a form.
	 */
	final class WPSC_Onboarding_Step_Fields {

		const STEP_ID = 'fields';

		/**
		 * Number of example field names to preview per field-type card.
		 */
		const PREVIEW_LIMIT = 4;

		/**
		 * Render step body
		 *
		 * @param string $step_id - step id.
		 * @return void
		 */
		public static function render( $step_id ) {

			$names = array(
				'ticket'    => array(),
				'agentonly' => array(),
				'customer'  => array(),
			);
			foreach ( WPSC_Custom_Field::$custom_fields as $cf ) {
				if ( isset( $names[ $cf->field ] ) ) {
					$names[ $cf->field ][] = $cf->name;
				}
			}

			$field_types = array(
				'ticket-fields'     => array(
					'label' => esc_attr__( 'Ticket Fields', 'supportcandy' ),
					'desc'  => esc_attr__( 'Information specific to a ticket, such as subject, status or priority.', 'supportcandy' ),
					'names' => $names['ticket'],
				),
				'agent-only-fields' => array(
					'label' => esc_attr__( 'Agent-only Fields', 'supportcandy' ),
					'desc'  => esc_attr__( 'Used internally by your support agents and never shown to customers.', 'supportcandy' ),
					'names' => $names['agentonly'],
				),
				'customer-fields'   => array(
					'label' => esc_attr__( 'Customer Fields', 'supportcandy' ),
					'desc'  => esc_attr__( 'Information about the customer raising the ticket.', 'supportcandy' ),
					'names' => $names['customer'],
				),
			);

			$tff_names = array();
			foreach ( array_keys( get_option( 'wpsc-tff', array() ) ) as $slug ) {
				$cf = WPSC_Custom_Field::get_cf_by_slug( $slug );
				if ( $cf ) {
					$tff_names[] = $cf->name;
				}
			}
			?>
			<div class="wpsc-onboarding-step">
				<h2><?php esc_attr_e( 'Ticket Fields & Ticket Form', 'supportcandy' ); ?></h2>
				<p class="wpsc-onboarding-step-desc"><?php esc_attr_e( 'SupportCandy organizes information into three kinds of fields, all already set up with sensible defaults on your site:', 'supportcandy' ); ?></p>

				<div class="wpsc-onboarding-stat-grid">
					<?php foreach ( $field_types as $field_type ) : ?>
						<div class="wpsc-onboarding-stat-card">
							<span class="wpsc-onboarding-stat-count"><?php echo esc_html( count( $field_type['names'] ) ); ?></span>
							<h3><?php echo esc_html( $field_type['label'] ); ?></h3>
							<p><?php echo esc_html( $field_type['desc'] ); ?></p>
							<p class="wpsc-onboarding-field-preview"><?php echo esc_html( self::preview_names( $field_type['names'] ) ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>

				<hr class="wpsc-onboarding-divider">

				<h2><?php esc_attr_e( 'Ticket Form', 'supportcandy' ); ?></h2>
				<p class="wpsc-onboarding-step-desc"><?php esc_attr_e( 'These are the fields customers currently fill in when they create a ticket - a mix of the field types above.', 'supportcandy' ); ?></p>

				<?php if ( ! empty( $tff_names ) ) : ?>
					<ul class="wpsc-onboarding-pill-list">
						<?php foreach ( $tff_names as $name ) : ?>
							<li><?php echo esc_html( $name ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<p class="wpsc-onboarding-step-desc"><?php esc_attr_e( 'You can customize and add new fields to all of these anytime from the Ticket Form settings once setup is complete.', 'supportcandy' ); ?></p>

				<?php WPSC_Onboarding::render_step_footer( $step_id ); ?>
			</div>
			<?php
			wp_die();
		}

		/**
		 * Short "A, B, C +N more" preview string for a list of field names.
		 *
		 * @param array $names - field names.
		 * @return string
		 */
		private static function preview_names( $names ) {

			if ( empty( $names ) ) {
				return '';
			}

			$shown     = array_slice( $names, 0, self::PREVIEW_LIMIT );
			$remaining = count( $names ) - count( $shown );
			$preview   = implode( ', ', $shown );

			if ( $remaining > 0 ) {
				$preview .= sprintf(
					/* translators: %d: number of additional fields not shown in the preview */
					esc_html__( ' +%d more', 'supportcandy' ),
					$remaining
				);
			}

			return $preview;
		}
	}
endif;
