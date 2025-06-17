<?php
declare(strict_types=1);

// Stub the Image helper in its namespace
namespace CroissantApi\Util {
    class Image {}
}

// Now everything else lives in the global namespace
namespace {

    // Stub WP_Query if it doesn’t already exist
    if ( ! class_exists('WP_Query') ) {
        class WP_Query {}
    }

    // Stub WP_Post (extends stdClass) if it doesn’t already exist
    if ( ! class_exists('WP_Post') ) {
        class WP_Post extends \stdClass {}
    }

    // ACF stub: get_field()
    if ( ! function_exists('get_field') ) {
        function get_field(string $key, int $post_id = null) {
            return \CroissantMSNFeed\Feed\FeedGeneratorTest::$widgets;
        }
    }

    // Escape helper stubs
    if ( ! function_exists('esc_url') ) {
        function esc_url(string $url): string {
            return $url;
        }
    }

    if ( ! function_exists('esc_html') ) {
        function esc_html(string $text): string {
            return $text;
        }
    }

    if ( ! function_exists('esc_attr') ) {
        function esc_attr(string $text): string {
            return $text;
        }
    }
}
