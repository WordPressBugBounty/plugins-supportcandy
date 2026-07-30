<?php
/**
 * PSM Deactivation Feedback Framework
 *
 * @package PSM_DFR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'PSM_DFR', false ) ) {
	return;
}

final class PSM_DFR {

	/**
	 * Registered plugins.
	 *
	 * @var array
	 */
	private static $plugins = array();

	/**
	 * Framework booted.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register plugin.
	 *
	 * @param array $config Plugin configuration.
	 *
	 * @return void
	 */
	public static function init( $config ) {

		$defaults = array(
			'plugin_file'    => '',
			'plugin_name'    => '',
			'plugin_version' => '',
			'endpoint'       => '',
			'api_key'        => '',
			'reasons'        => array(),
		);

		$config = wp_parse_args( $config, $defaults );

		if ( empty( $config['plugin_file'] ) ) {
			return;
		}

		self::$plugins[ $config['plugin_file'] ] = $config;

		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		require_once __DIR__ . '/class-psm-dfr-admin.php';
		require_once __DIR__ . '/class-psm-dfr-ajax.php';
	}

	/**
	 * Get plugins.
	 *
	 * @return array
	 */
	public static function get_plugins() {
		return self::$plugins;
	}
}
