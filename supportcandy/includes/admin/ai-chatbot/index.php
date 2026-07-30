<?php
$acb_settings = get_option( 'wpsc-ps-acb-chatbot-settings', array() );

// Load includes (settings, admin & cron hooks) so the settings UI stays reachable regardless of status.
foreach ( glob( __DIR__ . '/includes/*.php' ) as $filename ) {
	include_once $filename;
}

// Load the rest of the chatbot's functionality only when it's enabled.
if ( ! empty( $acb_settings['status'] ) && '1' === $acb_settings['status'] ) {

	// Load controller.
	foreach ( glob( __DIR__ . '/controller/*.php' ) as $filename ) {
		include_once $filename;
	}

	// Load tools.
	foreach ( glob( __DIR__ . '/tools/*.php' ) as $filename ) {
		include_once $filename;
	}

	// Load models.
	foreach ( glob( __DIR__ . '/models/*.php' ) as $filename ) {
		include_once $filename;
	}

	// Load providers.
	require_once __DIR__ . '/providers/class-wpsc-aibot-provider-interface.php';
	foreach ( glob( __DIR__ . '/providers/*.php' ) as $filename ) {
		if ( $filename === __DIR__ . '/providers/class-wpsc-aibot-provider-interface.php' ) {
			continue;
		}
		include_once $filename;
	}

	// Load ui templates.
	foreach ( glob( __DIR__ . '/templates/*.php' ) as $filename ) {
		include_once $filename;
	}
}
