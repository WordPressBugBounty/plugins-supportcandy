<?php

foreach ( glob( __DIR__ . '/admin/*.php' ) as $filename ) {
	include_once $filename;
}

// Load models.
foreach ( glob( __DIR__ . '/models/*.php' ) as $filename ) {
	include_once $filename;
}
