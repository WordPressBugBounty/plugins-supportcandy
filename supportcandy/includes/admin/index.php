<?php
// Load tickets.
foreach ( glob( __DIR__ . '/tickets/*.php' ) as $filename ) {
	include_once $filename;
}

// Load agent settings.
foreach ( glob( __DIR__ . '/agent-settings/*.php' ) as $filename ) {
	include_once $filename;
}

// Load ticket form settings.
foreach ( glob( __DIR__ . '/custom-fields/*.php' ) as $filename ) {
	include_once $filename;
}

// Load ticket list settings.
foreach ( glob( __DIR__ . '/ticket-list/*.php' ) as $filename ) {
	include_once $filename;
}

// Load email notification settings.
foreach ( glob( __DIR__ . '/email-notifications/*.php' ) as $filename ) {
	include_once $filename;
}

// Load settings.
foreach ( glob( __DIR__ . '/settings/*.php' ) as $filename ) {
	include_once $filename;
}

// Miscellaneous classes.
foreach ( glob( __DIR__ . '/misc/*.php' ) as $filename ) {
	include_once $filename;
}

// Recent activites classes.
foreach ( glob( __DIR__ . '/recent-activities/*.php' ) as $filename ) {
	include_once $filename;
}

// Customer classes.
foreach ( glob( __DIR__ . '/customers/*.php' ) as $filename ) {
	include_once $filename;
}
