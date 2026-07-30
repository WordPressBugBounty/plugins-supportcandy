<?php

// Load settings.
foreach ( glob( __DIR__ . '/settings/*.php' ) as $filename ) {
	include_once $filename;
}
