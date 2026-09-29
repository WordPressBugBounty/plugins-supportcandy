<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Onboarding_Step_Categories' ) ) :

	/**
	 * Onboarding step 3: Review Ticket Categories.
	 *
	 * Renders its own simple list + "Add Category" form (not the existing Settings
	 * screen's table/modal), but posts to the existing 'wpsc_set_add_category' AJAX
	 * action so categories are still created through the one existing code path -
	 * no category storage/logic is duplicated here.
	 */
	final class WPSC_Onboarding_Step_Categories {

		const STEP_ID = 'categories';

		/**
		 * Render step body
		 *
		 * @param string $step_id - step id.
		 * @return void
		 */
		public static function render( $step_id ) {

			$categories = WPSC_Category::find( array( 'items_per_page' => 0 ) )['results'];
			?>
			<div class="wpsc-onboarding-step">
				<h2><?php esc_attr_e( 'Review Ticket Categories', 'supportcandy' ); ?></h2>
				<p class="wpsc-onboarding-step-desc">
					<?php esc_attr_e( 'Categories help you organize tickets by topic, such as Billing, Technical Support, Sales, or General Questions. A default "General" category is already set up - add more if it fits your business, or continue as-is.', 'supportcandy' ); ?>
				</p>

				<ul class="wpsc-onboarding-pill-list">
					<?php foreach ( $categories as $category ) : ?>
						<li><?php echo esc_html( $category->name ); ?></li>
					<?php endforeach; ?>
				</ul>

				<form action="#" onsubmit="return false;" class="wpsc-frm-onboarding-add-category">
					<div class="wpsc-onboarding-inline-form">
						<input type="text" name="label" placeholder="<?php esc_attr_e( 'e.g. Billing', 'supportcandy' ); ?>" autocomplete="off">
						<button
							type="button"
							class="wpsc-button normal primary"
							onclick="wpsc_onboarding_add_category(this, '<?php echo esc_attr( wp_create_nonce( 'wpsc_set_add_category' ) ); ?>');">
							<?php esc_attr_e( 'Add Category', 'supportcandy' ); ?>
						</button>
					</div>
				</form>

				<?php WPSC_Onboarding::render_step_footer( $step_id ); ?>
			</div>
			<?php
			wp_die();
		}
	}
endif;
