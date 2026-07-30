<?php
/**
 * AJAX handler.
 *
 * @package PSM_DFR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSM_DFR_Ajax {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_psm_dfr_submit', array( __CLASS__, 'submit' ) );
	}

	/**
	 * Submit feedback.
	 *
	 * @return void
	 */
	public static function submit() {

		check_ajax_referer( 'psm_dfr_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error();
		}

		$plugin_file = isset( $_POST['plugin_file'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_file'] ) ) : '';
		$reason      = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$note        = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$plugins = PSM_DFR::get_plugins();

		if ( empty( $plugins[ $plugin_file ] ) ) {
			wp_send_json_error();
		}

		$config = $plugins[ $plugin_file ];

		$payload = array(
			'plugin_file'    => $config['plugin_file'],
			'plugin_name'    => $config['plugin_name'],
			'plugin_version' => $config['plugin_version'],
			'reason'         => $reason,
			'note'           => $note,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'website'        => '',
		);

		$signature = hash_hmac(
			'sha256',
			wp_json_encode( $payload ),
			$config['api_key']
		);

		$response = wp_remote_post(
			esc_url_raw( $config['endpoint'] ),
			array(
				'timeout'  => 3,
				'blocking' => true,
				'headers'  => array(
					'Content-Type'      => 'application/json',
					'X-PSMDF-API-KEY'   => $config['api_key'],
					'X-PSMDF-SIGNATURE' => $signature,
				),
				'body'     => wp_json_encode( $payload ),
			)
		);

		wp_send_json_success();
	}
}
PSM_DFR_Ajax::init();
