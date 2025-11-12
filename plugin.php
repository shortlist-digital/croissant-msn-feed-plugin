<?php
/**
 * @wordpress-plugin
 * Plugin Name:       Croissant MSN Feed Plugin
 * Description:       A WordPress plugin to generate an RSS Feed which is consumable by MSN
 * Version:           1.0.0
 * Author:            Stylist
 * Author URI:        http://shortlistmedia.co.uk/
 * License:           MIT
 */

defined( 'ABSPATH' ) or die( ':)' );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

add_action( 'init', function() {
    $container = require __DIR__ . '/container.php';

    $container['post']->register_publish_to_msn_field();
    $container['feed']->register_hooks();

    // Samsung Feed
    add_feed( 'samsung_feed', [ $container['feed'], 'print_feed' ] );
} );

