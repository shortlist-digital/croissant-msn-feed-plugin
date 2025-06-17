<?php
declare(strict_types=1);

namespace CroissantMSNFeedPlugin\Feed;

use PHPUnit\Framework\TestCase;
use CroissantApi\Util\Image;
use WP_Post;
use WP_Query;

/**
 * @covers \CroissantMSNFeed\Feed\FeedGenerator::map_post_widgets_to_content_rss_fields
 */
class FeedGeneratorTest extends TestCase {
    /** @var array<array> */
    public static $widgets = [];

    private FeedGenerator $generator;
    private Image         $imageHelper;

    public static function setUpBeforeClass(): void {
        self::$widgets = [];
    }

    protected function setUp(): void {
        // Mock the global WP_Query
        $wp_query = $this->createMock(WP_Query::class);
        // Image helper mock
        $this->imageHelper = $this->createMock(Image::class);
        // Instantiate FeedGenerator
        $this->generator = new FeedGenerator(
            $wp_query,
            'https://example.com',
            '',
            $this->imageHelper
        );
    }

    private function makePost(int $id): WP_Post {
        $post = new WP_Post();
        $post->ID          = $id;
        $post->post_author = 1;
        return $post;
    }

    public function testLoopingVideoWidget(): void {
        self::$widgets = [[
            'acf_fc_layout' => 'looping_video',
            'video'         => 'https://media.example.com/video.mp4',
        ]];

        $output = $this->generator->map_post_widgets_to_content_rss_fields(
            $this->makePost(123)
        );

        $this->assertStringContainsString(
            'src="https://media.example.com/video.mp4"',
            $output
        );
    }

    public function testListWidgetRendersNumberedList(): void {
        self::$widgets = [[
            'acf_fc_layout' => 'list_widget',
            'ingredients'   => [
                ['header' => 'First',  'body' => '<p>One</p>'],
                ['header' => 'Second', 'body' => '<p>Two</p>'],
            ],
        ]];

        $output = $this->generator->map_post_widgets_to_content_rss_fields(
            $this->makePost(456)
        );

        $this->assertStringContainsString('<ol>', $output);
        $this->assertStringContainsString(
            '<li><strong>First</strong> <p>One</p></li>',
            $output
        );
        $this->assertStringContainsString(
            '<li><strong>Second</strong> <p>Two</p></li>',
            $output
        );
        $this->assertStringContainsString('</ol>', $output);
    }

    public function testInstructionsWidgetRendersSteps(): void {
        self::$widgets = [[
            'acf_fc_layout' => 'instructions',
            'steps'         => [
                ['text' => '<p>Step A</p>'],
                ['text' => '<p>Step B</p>'],
            ],
        ]];

        $output = $this->generator->map_post_widgets_to_content_rss_fields(
            $this->makePost(789)
        );

        $this->assertStringContainsString('<ol>', $output);
        $this->assertStringContainsString('<li><p>Step A</p></li>', $output);
        $this->assertStringContainsString('<li><p>Step B</p></li>', $output);
        $this->assertStringContainsString('</ol>', $output);
    }

    public function testInteractiveImageWidgetShowsFirstImage(): void {
        self::$widgets = [[
            'acf_fc_layout' => 'interactive_image',
            'first_image'   => [
                'url' => 'https://img.example.com/pic.jpg',
                'alt' => 'Alt text'
            ],
        ]];

        $output = $this->generator->map_post_widgets_to_content_rss_fields(
            $this->makePost(101)
        );

        $this->assertStringContainsString(
            '<figure><img src="https://img.example.com/pic.jpg" alt="Alt text"',
            $output
        );
        $this->assertStringContainsString('</figure>', $output);
    }
}