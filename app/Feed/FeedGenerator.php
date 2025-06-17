<?php

namespace CroissantMSNFeedPlugin\Feed;

use WP_Query;
use WP_Post;
use CroissantApi\Util\Image;

class FeedGenerator {

    /** @var WP_Query */
    private $wp_query;

    /** @var string */
    private $web_domain;

    /** @var string */
    private $images_host;

    /** @var Image */
    private $image_helper;

    /**
     * @param WP_Query    $wp_query     A WP_Query instance
     * @param string      $web_domain   e.g. https://www.example.com
     * @param string      $images_host  optional override hostname for images
     * @param Image       $image_helper helper to fetch attachment metadata
     */
    public function __construct( WP_Query $wp_query, $web_domain, $images_host, Image $image_helper ) {
        $this->wp_query     = $wp_query;
        $this->web_domain   = rtrim( $web_domain, '/' );
        $this->images_host  = $images_host;
        $this->image_helper = $image_helper;
    }

    /**
     * Hook our custom feed.
     */
    public function register_hooks() {
        add_feed( 'msn_feed', [ $this, 'print_feed' ] );
    }

    /**
     * Output the feed XML.
     */
    public function print_feed() {
        $posts     = $this->get_posts();
        $rss_items = $this->render_post_items( $posts );
        $charset   = get_option( 'blog_charset' );
        $title     = get_bloginfo( 'name' );
        $self_link = $this->web_domain . parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

        // send the correct content‐type
        header( 'Content-Type: application/xml; charset=' . $charset, true );
        echo <<<RSS
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:atom="http://www.w3.org/2005/Atom"
     xmlns:content="http://purl.org/rss/1.0/modules/content/">
  <channel>
    <title><![CDATA[{$title} – MSN News]]></title>
    <link>{$this->web_domain}</link>
    <description><![CDATA[Custom MSN-compatible feed]]></description>
    <language>en-US</language>
    <atom:link href="{$self_link}" rel="self" type="application/rss+xml" />
{$rss_items}
  </channel>
</rss>
RSS;

        // stop WP from appending HTML
        exit;
    }

    /**
     * Fetch only the latest 30 posts where our ACF flag is on.
     *
     * @return WP_Post[]
     */
    private function get_posts() {
        $args = [
            'post_type'           => $this->get_public_post_types(),
            'posts_per_page'      => 30,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'meta_query'          => [
                [
                    'key'     => 'publish_to_msn',
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
            'no_found_rows'       => true,
        ];

        $q = new WP_Query( $args );
        return $q->posts;
    }

    /**
     * Build <item> elements.
     *
     * @param WP_Post[] $posts
     * @return string
     */
    private function render_post_items( array $posts ) {
        $items = '';

        foreach ( $posts as $post ) {
           // setup_postdata( $post );

            $link        = get_permalink( $post );
            $pub_date    = get_post_time( 'D, d M Y H:i:s', true, $post ) . ' GMT';
            $title       = get_the_title( $post );
            $author      = get_the_author_meta( 'display_name', $post->post_author );
            $excerpt     = htmlspecialchars( get_post_meta( $post->ID, 'seo_description', true ) ?: get_the_excerpt( $post ) );
            $hero_encl   = $this->render_hero( $post->ID );
            $widget_html = $this->map_post_widgets_to_content_rss_fields( $post );

            $items .= <<<ITEM

    <item>
      <title><![CDATA[{$title}]]></title>
      <link>{$link}</link>
      <guid>{$link}</guid>
      <pubDate>{$pub_date}</pubDate>
      <dc:creator><![CDATA[{$author}]]></dc:creator>
      <description><![CDATA[{$excerpt}]]></description>
      {$hero_encl}
      <content:encoded><![CDATA[{$widget_html}]]></content:encoded>
    </item>
ITEM;
        }

       // wp_reset_postdata();

        return $items;
    }

    /**
     * Restrict to public post types.
     *
     * @return string[]
     */
    private function get_public_post_types() {
        return array_merge(
            [ 'post' ],
            get_post_types( [
                'public'              => true,
                'exclude_from_search' => false,
                '_builtin'            => false,
            ] )
        );
    }

    /**
     * Render first hero image as an <enclosure>.
     *
     * @param int $post_id
     * @return string
     */
    private function render_hero( $post_id ) {
        $images = get_field( 'hero_images', $post_id );
        if ( ! is_array( $images ) || empty( $images[0]['url'] ) ) {
            return '';
        }

        $url = $images[0]['url'];
        if ( $this->images_host ) {
            $parts         = wp_parse_url( $url );
            $parts['host'] = $this->images_host;
            $url           = "{$parts['scheme']}://{$parts['host']}{$parts['path']}";
        }

        $type   = "{$images[0]['type']}/{$images[0]['subtype']}";
        $head   = wp_remote_head( $url );
        $length = wp_remote_retrieve_header( $head, 'content-length' ) ?: '';

        return "<enclosure url=\"{$url}\" type=\"{$type}\" length=\"{$length}\" />";
    }

    /**
     * Map ACF widgets to an HTML string, per your sample.
     *
     * @param WP_Post $post
     * @return string UTF-8–safe HTML of all widgets
     */
    public function map_post_widgets_to_content_rss_fields( WP_Post $post ) {
        $content = '';
        $widgets = get_field( 'widgets', $post->ID ) ?: [];
        foreach ( $widgets as $widget ) {
            switch ( $widget['acf_fc_layout'] ) {
                case 'paragraph':
                    $content .= $widget['paragraph'];
                    break;
                case 'divider':
                    $content .= $widget['divider'];
                    break;
                case 'heading':
                    $content .= '<h2>' . $widget['text'] . '</h2>';
                    break;
                case 'image':
                    $meta    = $this->image_helper->get_attachment_metadata( $widget['image'] );
                    $url     = $meta['doris_sizes']['square'] ?? '';
                    $alt     = $meta['alt'] ?? '';
                    $caption = $meta['description'] ?? '';
                    $content .= "<figure class='pp-media pp-media--pull-centre'>";
                    $content .= "<img class=\"pp-media__image\" alt=\"{$alt}\" src=\"{$url}\">";
                    if ( $caption ) {
                        $content .= "<figcaption class=\"pp-media__caption\">{$caption}</figcaption>";
                    }
                    $content .= "</figure>";
                    break;
                case 'html':
                    $content .= $widget['html'];
                    break;
                case 'listicle':
                    $content .= "<section class='listicle'>";
                    foreach ( $widget['item'] as $item ) {
                        if ( $item['media_type'] === 'image' ) {
                            $m = $this->image_helper->get_attachment_metadata( $item['image'] );
                            $s = $m['doris_sizes']['square'] ?? '';
                            $a = $m['alt'] ?? '';
                            $c = $m['description'] ?? '';
                            $content .= "<figure class=\"pp-media listicle__image\">";
                            $content .= "<img class=\"pp-media__image\" alt=\"{$a}\" src=\"{$s}\">";
                            if ( $c ) {
                                $content .= "<figcaption class=\"pp-media__caption\">{$c}</figcaption>";
                            }
                            $content .= "</figure>";
                        }
                        if ( $item['media_type'] === 'loop' ) {
                            $content .= "<video src=\"{$item['video']}\" preload=\"auto\" muted autoplay loop playsinline webkit-playsinline x5-playsinline style=\"width:100%;height:100%;\"></video>";
                        }
                        if ( $item['media_type'] === 'embed' ) {
                            $content .= $item['embed'];
                        }
                        $content .= "<h4 class=\"listicle__title\">{$item['title']}</h4>";
                        $content .= "<div class=\"listicle__paragraph\">{$item['paragraph']}</div>";
                        if ( ! empty( $item['label'] ) && ! empty( $item['url'] ) ) {
                            $content .= "<a class=\"listicle__link\" href=\"{$item['url']}\">{$item['label']}</a>";
                        }
                    }
                    $content .= "</section>";
                    break;
                case 'embed':
                    if ( str_contains( $widget['embed_link'], 'youtube' ) ) {
                        $content .= "<p class=\"stylist-youtube\">{$widget['embed']}</p>";
                    } else {
                        $content .= $widget['embed'];
                    }
                    break;
                case 'button':
                    $content .= "<a class=\"button\" href=\"{$widget['url']}\">{$widget['label']}</a>";
                    break;
                case 'pull-quote':
                    static $cnt = 0;
                    $cnt++;
                    $content .= "<section class=\"pp-article__boxout\">";
                    $content .= "<div id=\"boxout_{$cnt}\" class=\"pp-boxout\" style=\"background-color:#606060;\">";
                    $content .= "<div class=\"pp-boxout__body\">";
                    $content .= "<h4>{$widget['text']}</h4>";
                    $content .= "<p>{$widget['quote_author']}</p>";
                    $content .= "</div></div></section>";
                    break;
                case 'product-carousel':
                    $content .= "<section class='product-carousel'>";
                    foreach ( $widget['products'] as $p ) {
                        $pm = $this->image_helper->get_attachment_metadata( $p['thumbnail'] );
                        $ps = $pm['doris_sizes']['square'] ?? '';
                        $pa = $pm['alt'] ?? '';
                        $content .= "<div class='product'>";
                        $content .= "<img class='product__image' alt=\"{$pa}\" src=\"{$ps}\">";
                        $content .= "<h4 class='product__name'>{$p['product_text']}</h4>";
                        if ( ! empty( $p['price'] ) ) {
                            $content .= "<span class='product__price'>{$p['price']}</span>";
                        }
                        $content .= "<div class='product__description'>{$p['product_description']}</div>";
                        if ( ! empty( $p['button_text'] ) && ! empty( $p['button_url'] ) ) {
                            $content .= "<a class='product__button' href=\"{$p['button_url']}\">{$p['button_text']}</a>";
                        }
                        $content .= "</div>";
                    }
                    $content .= "</section>";
                    break;
                case 'looping_video':
                    // render a looping video tag
                    $video = esc_url( $widget['video'] );
                    $content .= "<video src=\"{$video}\" preload=\"auto\" muted autoplay loop playsinline webkit-playsinline x5-playsinline style=\"width:100%;height:auto;\"></video>";
                    break;

                case 'list_widget':
                    // render as a numbered list
                    $items = $widget['ingredients'] ?? [];
                    if ( $items ) {
                        $content .= '<ol>';
                        foreach ( $items as $item ) {
                            $body   = $item['body']   ?? '';
                            $header = $item['header'] ?? '';
                            $content .= '<li>';
                            if ( $header ) {
                                $content .= '<strong>' . esc_html( $header ) . '</strong> ';
                            }
                            $content .= $body;
                            $content .= '</li>';
                        }
                        $content .= '</ol>';
                    }
                    break;

                case 'instructions':
                    // also a numbered list of steps
                    $steps = $widget['steps'] ?? [];
                    if ( $steps ) {
                        $content .= '<ol>';
                        foreach ( $steps as $step ) {
                            $text = $step['text'] ?? '';
                            $content .= '<li>' . $text . '</li>';
                        }
                        $content .= '</ol>';
                    }
                    break;

                case 'interactive_image':
                    // only show the first image
                    $first = $widget['first_image'] ?? [];
                    if ( ! empty( $first['url'] ) ) {
                        $url = esc_url( $first['url'] );
                        $alt = esc_attr( $first['alt'] ?? '' );
                        $content .= "<figure><img src=\"{$url}\" alt=\"{$alt}\" /></figure>";
                    }
                    break;
                default:
                    break;
            }
        }

        // force UTF-8
        return mb_convert_encoding( $content, 'UTF-8', 'auto' );
    }
}