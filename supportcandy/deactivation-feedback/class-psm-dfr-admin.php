<?php
/**
 * Admin functionality for PSM Deactivation Feedback Framework.
 *
 * @package PSM_DFR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSM_DFR_Admin {

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_modal' ) );
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {

		if ( 'plugins.php' !== $hook ) {
			return;
		}

		$plugins = PSM_DFR::get_plugins();

		if ( empty( $plugins ) ) {
			return;
		}

		$first = reset( $plugins );

		$base_url = trailingslashit( plugin_dir_url( $first['plugin_file'] ) ) . 'deactivation-feedback/';

		wp_enqueue_style(
			'psm-dfr',
			$base_url . 'assets/deactivation-feedback.css',
			array(),
			$first['plugin_version']
		);

		wp_enqueue_script(
			'psm-dfr',
			$base_url . 'assets/deactivation-feedback.js',
			array( 'jquery' ),
			$first['plugin_version'],
			true
		);

		wp_add_inline_script(
			'psm-dfr',
			'window.PSM_DFR=' . wp_json_encode(
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'psm_dfr_nonce' ),
					'plugins'  => array_values( $plugins ),
				)
			),
			'before'
		);
	}

	/**
	 * Render modal.
	 *
	 * @return void
	 */
	public static function render_modal() {

		$screen = get_current_screen();

		if ( empty( $screen ) || 'plugins' !== $screen->id ) {
			return;
		}
		?>
		<div id="psm-dfr-modal" style="display:none;">
			<div class="psm-dfr-overlay"></div>

			<div class="psm-dfr-container">

				<h2 id="psm-dfr-title"><?php esc_html_e( 'Quick Feedback', 'supportcandy' ); ?></h2>
				<p><?php esc_html_e( 'If you have a moment, please let us know why you are deactivating:', 'supportcandy' ); ?></p>

				<div id="psm-dfr-reasons"></div>

				<textarea
					id="psm-dfr-note"
					placeholder="<?php esc_attr_e( 'Additional details (optional)', 'supportcandy' ); ?>"
				></textarea>

				<div class="psm-dfr-actions">

					<button
						type="button"
						class="button"
						id="psm-dfr-cancel">
						<?php esc_html_e( 'Cancel', 'supportcandy' ); ?>
					</button>

					<button
						type="button"
						class="button"
						id="psm-dfr-skip">
						<?php esc_html_e( 'Skip & Deactivate', 'supportcandy' ); ?>
					</button>

					<button
						type="button"
						class="button button-primary"
						id="psm-dfr-submit">
						<?php esc_html_e( 'Submit & Deactivate', 'supportcandy' ); ?>
					</button>

				</div>
				<div class="psm-dfr-privacy-note"><?php esc_html_e( "We do not collect any personal data when you submit this form. It's your feedback that we value.", 'supportcandy' ); ?></div>
			</div>
		</div>
		<?php
	}
}
PSM_DFR_Admin::init();
