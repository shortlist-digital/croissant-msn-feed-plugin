<?php

$container = new Pimple\Container();
global $main_container;

$container['wp_query'] = function() {
    return new \WP_Query();
};

$container['image_helper'] = function() {
    return new CroissantApi\Util\Image();
};

$container['post'] = function() {
    return new CroissantMSNFeedPlugin\CustomFields\Post();
};

$container['feed'] = function($c) {
    return new CroissantMSNFeedPlugin\Feed\FeedGenerator(
        $c['wp_query'],
        getenv('WEB_BASE_URL'),
        getenv('IMAGES_HOST') ?: '',
        $c['image_helper'],
    );
};
return $container;
