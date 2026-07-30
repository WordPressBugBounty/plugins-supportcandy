<?php

// Load provider interface first so implementation classes can be parsed safely.
$provider_interface_file = __DIR__ . '/interfaces/class-wpsc-ps-ait-provider-interfaces.php';
if ( file_exists( $provider_interface_file ) ) {
	include_once $provider_interface_file;
}

// Load remaining interface-related classes.
foreach ( glob( __DIR__ . '/interfaces/*.php' ) as $filename ) {
	if ( $filename === $provider_interface_file ) {
		continue;
	}
	include_once $filename;
}

// Load AI training.
foreach ( glob( __DIR__ . '/ai-training/*.php' ) as $filename ) {
	include_once $filename;
}

$ai_settings = get_option( 'wpsc-ps-ai-assistant-settings', array() );

// Load auto draft, polish reply & ticket summary only when the AI assistant is enabled.
if ( ! empty( $ai_settings['status'] ) && '1' === $ai_settings['status'] ) {

	// Load auto draft.
	foreach ( glob( __DIR__ . '/auto-draft/*.php' ) as $filename ) {
		include_once $filename;
	}

	// Load polish reply.
	foreach ( glob( __DIR__ . '/polish-reply/*.php' ) as $filename ) {
		include_once $filename;
	}
}

// Load AI assistant settings.
foreach ( glob( __DIR__ . '/settings/*.php' ) as $filename ) {
	include_once $filename;
}

// Load common classes.
foreach ( glob( __DIR__ . '/common/*.php' ) as $filename ) {
	include_once $filename;
}
