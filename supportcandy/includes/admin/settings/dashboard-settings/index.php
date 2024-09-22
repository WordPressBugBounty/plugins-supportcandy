<?php
// Load cards settings.
foreach ( glob( __DIR__ . '/cards/*.php' ) as $filename ) {
	include_once $filename;
}

// Load widgets settings.
foreach ( glob( __DIR__ . '/widgets/*.php' ) as $filename ) {
	include_once $filename;
}
