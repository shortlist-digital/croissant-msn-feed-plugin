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

    public function __construct( WP_Query $wp_query, $web_domain, $images_host, Image $image_helper ) {
        $this->wp_query     = $wp_query;
        $this->web_domain   = rtrim( $web_domain, '/' );
        $this->images_host  = $images_host;
        $this->image_helper = $image_helper;
    }

    public function register_hooks() {
        add_feed( 'msn_feed', [ $this, 'print_feed' ] );
        // samsung_feed is registered in plugin bootstrap (as you already do)
    }

    public function print_feed() {
        $posts = $this->get_posts();

        $feed_name  = get_query_var( 'feed' );
        $is_samsung = ( $feed_name === 'samsung_feed' );

        $rss_items = $this->render_post_items( $posts, $is_samsung );
        $charset   = get_option( 'blog_charset' );
        $blog_name = get_bloginfo( 'name' );
        $self_link = $this->web_domain . parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

        $channel_title       = $is_samsung ? "{$blog_name} – Samsung News" : "{$blog_name} – MSN News";
        $channel_description = $is_samsung ? 'Custom Samsung-compatible feed' : 'Custom MSN-compatible feed';

        // Samsung: MRSS namespace for <media:content>
        $media_ns = $is_samsung ? "\n     xmlns:media=\"http://search.yahoo.com/mrss/\"" : '';

        header( 'Content-Type: application/xml; charset=' . $charset, true );

        echo <<<RSS
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:atom="http://www.w3.org/2005/Atom"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"{$media_ns}>
  <channel>
    <title><![CDATA[{$channel_title}]]></title>
    <link>{$this->web_domain}</link>
    <description><![CDATA[{$channel_description}]]></description>
    <language>en-US</language>
    <atom:link href="{$self_link}" rel="self" type="application/rss+xml" />
{$rss_items}
  </channel>
</rss>
RSS;

        exit;
    }

    /**
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

    private function render_post_items( array $posts, $is_samsung = false ) {
        $items = '';

        foreach ( $posts as $post ) {
            $link     = get_permalink( $post );
            $pub_date = get_post_time( 'D, d M Y H:i:s', true, $post ) . ' GMT';
            $title    = get_the_title( $post );
            $author   = get_the_author_meta( 'display_name', $post->post_author );

            $excerpt_raw = get_post_meta( $post->ID, 'seo_description', true ) ?: get_the_excerpt( $post );
            $excerpt     = htmlspecialchars( (string) $excerpt_raw );

            $widget_html = $this->map_post_widgets_to_content_rss_fields( $post, $is_samsung );

            $hero_block = $is_samsung
                ? $this->render_hero_media_content( $post->ID )
                : $this->render_hero_enclosure( $post->ID );

            $items .= <<<ITEM

    <item>
      <title><![CDATA[{$title}]]></title>
      <link>{$link}</link>
      <guid isPermaLink="true">{$link}</guid>
      <pubDate>{$pub_date}</pubDate>
      <dc:creator><![CDATA[{$author}]]></dc:creator>
      <description><![CDATA[{$excerpt}]]></description>
{$hero_block}      <content:encoded><![CDATA[{$widget_html}]]></content:encoded>
    </item>
ITEM;
        }

        return $items;
    }

    /**
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
     * MSN: hero image as <enclosure>
     */
    private function render_hero_enclosure( $post_id ) {
        $images = get_field( 'hero_images', $post_id );
        if ( ! is_array( $images ) || empty( $images[0]['url'] ) ) {
            return '';
        }

        $image = $images[0];

        if ( isset( $image['type'] ) && $image['type'] !== 'image' ) {
            return '';
        }

        $url = $this->resolve_image_url( $image['url'] );
        if ( ! $url ) {
            return '';
        }

        $type = '';
        if ( ! empty( $image['mime_type'] ) ) {
            $type = $image['mime_type'];
        } elseif ( ! empty( $image['type'] ) && ! empty( $image['subtype'] ) ) {
            $type = "{$image['type']}/{$image['subtype']}";
        } else {
            $type = 'image/jpeg';
        }

        $head   = wp_remote_head( $url );
        $length = wp_remote_retrieve_header( $head, 'content-length' ) ?: '';

        $url_esc  = esc_url( $url );
        $type_esc = esc_attr( $type );
        $len_esc  = esc_attr( $length );

        return "      <enclosure url=\"{$url_esc}\" type=\"{$type_esc}\" length=\"{$len_esc}\" />\n";
    }

    /**
     * Samsung: hero image as <media:content> with optional <media:credit>
     *
     * Output format:
     * <media:content url="..." type="...">
     *   <media:credit><![CDATA[...]]></media:credit>
     * </media:content>
     */
    private function render_hero_media_content( $post_id ) {
        $images = get_field( 'hero_images', $post_id );
        if ( ! is_array( $images ) || empty( $images[0]['url'] ) ) {
            return '';
        }

        $image = $images[0];

        if ( isset( $image['type'] ) && $image['type'] !== 'image' ) {
            return '';
        }

        $url = $this->resolve_image_url( $image['url'] );
        if ( ! $url ) {
            return '';
        }

        if ( ! empty( $image['mime_type'] ) ) {
            $mime_type = $image['mime_type'];
        } elseif ( ! empty( $image['type'] ) && ! empty( $image['subtype'] ) ) {
            $mime_type = "{$image['type']}/{$image['subtype']}";
        } else {
            $mime_type = 'image/jpeg';
        }

        // HERO CREDIT: try common ACF keys; adjust if your field uses a specific key.
        $credit_raw = '';
        foreach ( ['credit'] as $k ) {
            if ( ! empty( $image[ $k ] ) ) {
                $credit_raw = $image[ $k ];
                break;
            }
        }

        // Fallback: if hero_images is a WP attachment array, it often has 'id'
        if ( ! $credit_raw && ! empty( $image['id'] ) ) {
            $att_id = (int) $image['id'];
            if ( $att_id > 0 ) {
                $credit_raw = wp_get_attachment_caption( $att_id );
            }
        }

        $credit_raw = is_string( $credit_raw ) ? trim( $credit_raw ) : '';
        $credit_xml = $credit_raw !== ''
            ? "        <media:credit><![CDATA[{$credit_raw}]]></media:credit>\n"
            : '';

        $url_esc  = esc_url( $url );
        $mime_esc = esc_attr( $mime_type ?: 'image/jpeg' );

        return
            "      <media:content url=\"{$url_esc}\" type=\"{$mime_esc}\">\n" .
            $credit_xml .
            "      </media:content>\n";
    }

    /**
     * Convert ACF widgets -> HTML for <content:encoded>
     *
     * Samsung mode:
     * - Keep inline images (<img>) but ensure URLs are valid
     * - Remove iframes/scripts (and for embed widgets output original link)
     *
     * @param WP_Post $post
     * @param bool    $is_samsung
     * @return string
     */
    public function map_post_widgets_to_content_rss_fields( WP_Post $post, $is_samsung = false ) {
        $content = '';
        $widgets = get_field( 'widgets', $post->ID ) ?: [];

        foreach ( $widgets as $widget ) {
            $layout = $widget['acf_fc_layout'] ?? '';

            switch ( $layout ) {
                case 'paragraph':
                    $content .= $widget['paragraph'] ?? '';
                    break;

                case 'divider':
                    $content .= $widget['divider'] ?? '';
                    break;

                case 'heading':
                    $content .= '<h2>' . esc_html( $widget['text'] ?? '' ) . '</h2>';
                    break;

                case 'image':
                    $content .= $this->render_image_figure_html( $widget['image'] ?? null, 'pp-media pp-media--pull-centre', 'square' );
                    break;

                case 'interactive_image':
                    $first = $widget['first_image'] ?? null;
                    $content .= $this->render_image_figure_html( $first, '', 'square' );
                    break;

                case 'listicle':
                    $content .= "<section class='listicle'>";

                    foreach ( $widget['item'] ?? [] as $item ) {
                        $media_type = $item['media_type'] ?? '';

                        if ( $media_type === 'image' ) {
                            $content .= $this->render_image_figure_html( $item['image'] ?? null, 'pp-media listicle__image', 'square' );
                        }

                        if ( $media_type === 'loop' ) {
                            if ( ! $is_samsung ) {
                                $v = esc_url( $item['video'] ?? '' );
                                if ( $v ) {
                                    $content .= "<video src=\"{$v}\" preload=\"auto\" muted autoplay loop playsinline webkit-playsinline x5-playsinline style=\"width:100%;height:100%;\"></video>";
                                }
                            }
                        }

                        if ( $media_type === 'embed' ) {
                            if ( $is_samsung ) {
                                $orig = $this->extract_original_embed_url_from_item( $item );
                                if ( $orig ) {
                                    $o = esc_url( $orig );
                                    $content .= "<p><a href=\"{$o}\">{$o}</a></p>";
                                }
                            } else {
                                $content .= $item['embed'] ?? '';
                            }
                        }

                        $t = $item['title'] ?? '';
                        $p = $item['paragraph'] ?? '';

                        if ( $t ) {
                            $content .= "<h4 class=\"listicle__title\">" . esc_html( $t ) . "</h4>";
                        }
                        if ( $p ) {
                            $content .= "<div class=\"listicle__paragraph\">{$p}</div>";
                        }

                        if ( ! empty( $item['label'] ) && ! empty( $item['url'] ) ) {
                            $u = esc_url( $item['url'] );
                            $l = esc_html( $item['label'] );
                            $content .= "<a class=\"listicle__link\" href=\"{$u}\">{$l}</a>";
                        }
                    }

                    $content .= "</section>";
                    break;

                case 'embed':
                    if ( $is_samsung ) {
                        $orig = $this->get_original_embed_url( (array) $widget );
                        if ( $orig ) {
                            $o = esc_url( $orig );
                            $content .= "<p><a href=\"{$o}\">{$o}</a></p>";
                        }
                        break;
                    }

                    if ( ! empty( $widget['embed_link'] ) && str_contains( $widget['embed_link'], 'youtube' ) ) {
                        $content .= "<p class=\"stylist-youtube\">" . ( $widget['embed'] ?? '' ) . "</p>";
                    } else {
                        $content .= $widget['embed'] ?? '';
                    }
                    break;

                case 'html':
                    if ( $is_samsung ) {
                        $content .= wp_kses( (string) ( $widget['html'] ?? '' ), $this->get_samsung_allowed_html() );
                        break;
                    }
                    $content .= $widget['html'] ?? '';
                    break;

                case 'button':
                    $url   = esc_url( $widget['url'] ?? '' );
                    $label = esc_html( $widget['label'] ?? '' );
                    if ( $url && $label ) {
                        $content .= "<a class=\"button\" href=\"{$url}\">{$label}</a>";
                    }
                    break;

                case 'pull-quote':
                    static $cnt = 0;
                    $cnt++;
                    $text   = esc_html( $widget['text'] ?? '' );
                    $author = esc_html( $widget['quote_author'] ?? '' );

                    $content .= "<section class=\"pp-article__boxout\">";
                    $content .= "<div id=\"boxout_{$cnt}\" class=\"pp-boxout\" style=\"background-color:#606060;\">";
                    $content .= "<div class=\"pp-boxout__body\">";
                    $content .= "<h4>{$text}</h4>";
                    $content .= "<p>{$author}</p>";
                    $content .= "</div></div></section>";
                    break;

                case 'product-carousel':
                    $content .= "<section class='product-carousel'>";
                    foreach ( $widget['products'] ?? [] as $p ) {
                        $content .= "<div class='product'>";

                        $thumb_html = $this->render_image_img_only_html( $p['thumbnail'] ?? null, 'product__image', 'square' );
                        if ( $thumb_html ) {
                            $content .= $thumb_html;
                        }

                        $content .= "<h4 class='product__name'>" . esc_html( $p['product_text'] ?? '' ) . "</h4>";
                        if ( ! empty( $p['price'] ) ) {
                            $content .= "<span class='product__price'>" . esc_html( $p['price'] ) . "</span>";
                        }
                        $content .= "<div class='product__description'>" . ( $p['product_description'] ?? '' ) . "</div>";

                        if ( ! empty( $p['button_text'] ) && ! empty( $p['button_url'] ) ) {
                            $bu = esc_url( $p['button_url'] );
                            $bt = esc_html( $p['button_text'] );
                            $content .= "<a class='product__button' href=\"{$bu}\">{$bt}</a>";
                        }

                        $content .= "</div>";
                    }
                    $content .= "</section>";
                    break;

                case 'looping_video':
                    if ( ! $is_samsung ) {
                        $video = esc_url( $widget['video'] ?? '' );
                        if ( $video ) {
                            $content .= "<video src=\"{$video}\" preload=\"auto\" muted autoplay loop playsinline webkit-playsinline x5-playsinline style=\"width:100%;height:auto;\"></video>";
                        }
                    }
                    break;

                case 'list_widget':
                    $items = $widget['ingredients'] ?? [];
                    if ( $items ) {
                        $content .= '<ol>';
                        foreach ( $items as $item ) {
                            $body   = $item['body'] ?? '';
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
                    $steps = $widget['steps'] ?? [];
                    if ( $steps ) {
                        $content .= '<ol>';
                        foreach ( $steps as $step ) {
                            $content .= '<li>' . ( $step['text'] ?? '' ) . '</li>';
                        }
                        $content .= '</ol>';
                    }
                    break;

                default:
                    break;
            }
        }

        if ( $is_samsung ) {
            $content = wp_kses( $content, $this->get_samsung_allowed_html() );
        }

        return mb_convert_encoding( $content, 'UTF-8', 'auto' );
    }

    private function render_image_figure_html( $img, $figure_class = '', $prefer_size = 'square' ) {
        [ $raw_url, $alt, $caption ] = $this->pick_image_fields( $img, $prefer_size );

        $url = $this->resolve_image_url( $raw_url );
        if ( ! $url ) {
            return '';
        }

        $alt_esc = esc_attr( $alt );
        $cls     = $figure_class ? " class='" . esc_attr( $figure_class ) . "'" : '';

        $html  = "<figure{$cls}>";
        $html .= "<img class=\"pp-media__image\" alt=\"{$alt_esc}\" src=\"" . esc_url( $url ) . "\">";
        if ( $caption ) {
            $html .= "<figcaption class=\"pp-media__caption\">" . esc_html( $caption ) . "</figcaption>";
        }
        $html .= "</figure>";

        return $html;
    }

    private function render_image_img_only_html( $img, $img_class = '', $prefer_size = 'square' ) {
        [ $raw_url, $alt ] = $this->pick_image_fields( $img, $prefer_size, false );
        $url = $this->resolve_image_url( $raw_url );
        if ( ! $url ) {
            return '';
        }

        $class_attr = $img_class ? ' class="' . esc_attr( $img_class ) . '"' : '';
        return "<img{$class_attr} alt=\"" . esc_attr( $alt ) . "\" src=\"" . esc_url( $url ) . "\">";
    }

    private function pick_image_fields( $img, $prefer_size = 'square', $include_caption = true ) {
        $raw_url = '';
        $alt     = '';
        $caption = '';

        if ( is_array( $img ) ) {
            $raw_url = $img['sizes'][ $prefer_size ] ?? ( $img['url'] ?? '' );
            $alt     = (string) ( $img['alt'] ?? '' );
            if ( $include_caption ) {
                $caption = (string) ( $img['description'] ?? ( $img['caption'] ?? '' ) );
            }
        } else {
            $id = (int) $img;
            if ( $id > 0 ) {
                $meta = $this->image_helper->get_attachment_metadata( $id );

                $raw_url = $meta['doris_sizes'][ $prefer_size ] ?? ( $meta['url'] ?? ( $meta['source_url'] ?? '' ) );
                $alt     = (string) ( $meta['alt'] ?? '' );
                if ( $include_caption ) {
                    $caption = (string) ( $meta['description'] ?? '' );
                }
            }
        }

        return $include_caption ? [ $raw_url, $alt, $caption ] : [ $raw_url, $alt ];
    }

    private function get_samsung_allowed_html() {
        return [
            'p'      => [ 'class' => true ],
            'br'     => [],
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'u'      => [],
            'h2'     => [],
            'h3'     => [],
            'h4'     => [],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
            'blockquote' => [],
            'section' => [ 'class' => true ],
            'div'     => [ 'class' => true ],
            'span'    => [ 'class' => true ],
            'figure'  => [ 'class' => true ],
            'figcaption' => [ 'class' => true ],
            'img'     => [
                'class' => true,
                'alt'   => true,
                'src'   => true,
            ],
            'a'       => [
                'href'   => true,
                'title'  => true,
                'rel'    => true,
                'target' => true,
            ],
        ];
    }

    private function get_original_embed_url( array $widget ) {
        $original = trim( (string) ( $widget['embed_link'] ?? '' ) );
        if ( $original && ! str_contains( $original, 'embeds.stylist.co.uk' ) ) {
            return $original;
        }

        $html = (string) ( $widget['embed'] ?? '' );

        if ( preg_match_all( '#https?://[^\s"\']+#i', $html, $m ) ) {
            foreach ( $m[0] as $u ) {
                if ( ! str_contains( $u, 'embeds.stylist.co.uk' ) ) {
                    return $u;
                }
            }
        }

        return '';
    }

    private function extract_original_embed_url_from_item( array $item ) {
        $maybe = trim( (string) ( $item['url'] ?? '' ) );
        if ( $maybe && ! str_contains( $maybe, 'embeds.stylist.co.uk' ) ) {
            return $maybe;
        }

        $embed_html = (string) ( $item['embed'] ?? '' );
        if ( preg_match_all( '#https?://[^\s"\']+#i', $embed_html, $m ) ) {
            foreach ( $m[0] as $u ) {
                if ( ! str_contains( $u, 'embeds.stylist.co.uk' ) ) {
                    return $u;
                }
            }
        }

        return '';
    }

    private function resolve_image_url( $raw_url ) {
        $url = $this->resolve_asset_url( $raw_url );
        if ( ! $url ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        $path  = $parts['path'] ?? '';

        if ( $path === '' || $path === '/' ) {
            return '';
        }

        if ( ! preg_match( '#\.(jpe?g|png|gif|webp|avif)(\?.*)?$#i', $path ) ) {
            return '';
        }

        return $url;
    }

    private function resolve_asset_url( $raw_url ) {
        $raw_url = trim( (string) $raw_url );
        if ( $raw_url === '' ) {
            return '';
        }

        if ( str_starts_with( $raw_url, '/' ) ) {
            $raw_url = $this->web_domain . $raw_url;
        }

        if ( ! preg_match( '#^https?://#i', $raw_url ) ) {
            $raw_url = $this->web_domain . '/' . ltrim( $raw_url, '/' );
        }

        if ( $this->images_host ) {
            $parts = wp_parse_url( $raw_url );
            if ( ! empty( $parts['scheme'] ) && ! empty( $parts['path'] ) ) {
                $raw_url = "{$parts['scheme']}://{$this->images_host}{$parts['path']}";
            }
        }

        return $raw_url;
    }
}