<?php
// Preview stand-in for GeneratePress: theme supports the child theme expects, nothing else.
add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo' );
} );
