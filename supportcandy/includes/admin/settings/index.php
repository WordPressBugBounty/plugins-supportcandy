<?php
// Load general settings.
foreach ( glob( __DIR__ . '/general-settings/*.php' ) as $filename ) {
	include_once $filename;
}

// Load dashboard settings.
foreach ( glob( __DIR__ . '/dashboard-settings/*.php' ) as $filename ) {
	include_once $filename;
}

// Load text editor settings.
foreach ( glob( __DIR__ . '/text-editor-settings/*.php' ) as $filename ) {
	include_once $filename;
}

// Load miscellaneaous settings.
foreach ( glob( __DIR__ . '/miscellaneous-settings/*.php' ) as $filename ) {
	include_once $filename;
}

// Load working hrs.
foreach ( glob( __DIR__ . '/working-hrs/*.php' ) as $filename ) {
	include_once $filename;
}

// Load appearence.
foreach ( glob( __DIR__ . '/appearence/*.php' ) as $filename ) {
	include_once $filename;
}

// Load appearence.
foreach ( glob( __DIR__ . '/ticket-tags/*.php' ) as $filename ) {
	include_once $filename;
}
