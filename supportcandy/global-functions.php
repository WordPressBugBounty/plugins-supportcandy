<?php
/**
 * Includes global functions that can be accessible without class name
 * or objects throught the WordPress
 *
 * @package SupportCandy
 */

/**
 * Perform outside scope translations within plugin or addons
 *
 * @param string $text - string to be translated.
 * @param string $domain - textdomain.
 * @return string
 */
function wpsc__( $text, $domain = 'default' ) {
	return __( $text, $domain ); // phpcs:ignore
}

/**
 * Translate common strings required across addons but not used within the core product
 *
 * @param string $key - unique key for the string.
 * @return string
 */
function wpsc_translate_common_strings( $key ) {

	switch ( $key ) {

		case 'activate-license':
			return __( 'Please activate your license!', 'supportcandy' );

		case 'license-activated':
			return __( 'Your license key activated!', 'supportcandy' );

		case 'license-expires':
			/* translators: %s: date */
			return __( 'Your license key expires on %s', 'supportcandy' );

		case 'license-expired':
			/* translators: %s: date */
			return __( 'Your license key expired on %s', 'supportcandy' );

		case 'view-details':
			return __( 'View Details', 'supportcandy' );
	}
}

/**
 * Returns EDD licence activation errors
 *
 * @param string $error - error code.
 * @return string
 */
function wpsc_licence_errors( $error ) {

	$error_message = '';
	switch ( $error ) {

		case 'missing':
			return esc_attr( 'License does not exist' );

		case 'missing_url':
			return esc_attr( 'URL not provided' );

		case 'license_not_activable':
			return esc_attr( 'Attempting to activate a parent license of bundle' );

		case 'disabled':
			return esc_attr( 'License key revoked' );

		case 'no_activations_left':
			return esc_attr( 'No activations left' );

		case 'expired':
			return esc_attr( 'License has expired' );

		case 'key_mismatch':
			return esc_attr( 'License is not valid for this product' );

		case 'invalid_item_id':
			return esc_attr( 'Invalid Item ID' );

		case 'item_name_mismatch':
			return esc_attr( 'License is not valid for this product' );

		case 'site_inactive':
			return esc_attr( 'Site is not active for this license' );

		case 'invalid':
			return esc_attr( 'License key does not match' );
	}
	return '';
}
