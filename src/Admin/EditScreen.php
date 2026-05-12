<?php
/**
 * Custom post edit screen for the Markdown CPT.
 *
 * Removes the standard editor area and renders the upload + parser UI in its place.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks\Admin;

use AB\MarkdownToBricks\CPT\Markdown;
use AB\MarkdownToBricks\REST\MarkdownController;
use AB\MarkdownToBricks\Service\HeadingCache;

defined('ABSPATH') || exit;

/**
 * Renders the parser UI on the Markdown CPT edit screen.
 */
final class EditScreen {

    /**
     * Register hooks.
     */
    public static function init(): void {
        add_action('edit_form_after_title', [self::class, 'render']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_head', [self::class, 'inline_data']);
    }

    /**
     * Whether we're on the Markdown CPT add/edit screen.
     */
    private static function is_our_screen(): bool {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        return $screen && $screen->post_type === Markdown::POST_TYPE
            && in_array($screen->base, ['post', 'post-new'], true);
    }

    /**
     * Render the UI just below the post title.
     */
    public static function render(\WP_Post $post): void {
        if ($post->post_type !== Markdown::POST_TYPE) {
            return;
        }
        $template = ABMTB_PLUGIN_PATH . 'templates/admin/edit-screen.php';
        if (is_file($template)) {
            include $template;
        }
    }

    /**
     * Enqueue Alpine + plugin admin assets on the CPT edit screen only.
     */
    public static function enqueue_assets(): void {
        if (!self::is_our_screen()) {
            return;
        }

        wp_enqueue_style(
            'abmtb-admin',
            ABMTB_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ABMTB_VERSION
        );

        wp_enqueue_script(
            'abmtb-admin',
            ABMTB_PLUGIN_URL . 'assets/js/admin.js',
            [],
            ABMTB_VERSION,
            true
        );

        // Alpine is loaded as a non-blocking script. The defer attribute is
        // required so its `alpine:init` event fires after our admin.js has
        // registered the Alpine.data() component.
        wp_enqueue_script(
            'abmtb-alpine',
            ABMTB_PLUGIN_URL . 'assets/js/alpine.min.js',
            ['abmtb-admin'],
            ABMTB_VERSION,
            ['strategy' => 'defer', 'in_footer' => true]
        );
    }

    /**
     * Print the per-post bootstrap data as window.ABMTB.
     */
    public static function inline_data(): void {
        if (!self::is_our_screen()) {
            return;
        }
        global $post;
        if (!($post instanceof \WP_Post)) {
            return;
        }

        $content  = (string) $post->post_content;
        $headings = HeadingCache::get((int) $post->ID);
        $settings = MarkdownController::get_level_settings((int) $post->ID);

        $data = [
            'apiUrl'      => esc_url_raw(rest_url(MarkdownController::NAMESPACE)),
            'nonce'       => wp_create_nonce('wp_rest'),
            'postId'      => (int) $post->ID,
            'postSlug'    => $post->post_name !== '' ? $post->post_name : 'post-slug',
            'dateFormats' => self::date_format_options(),
            'data'        => [
                'markdown'      => $content,
                'headings'      => $headings,
                'levelSettings' => $settings,
            ],
        ];

        echo '<script id="abmtb-bootstrap">window.ABMTB = '
            . wp_json_encode($data)
            . ';</script>' . "\n";
    }

    /**
     * Ordered list of dropdown options for the per-level date format.
     *
     * Returned as ordered pairs (not an associative array) so the
     * sequence is preserved when wp_json_encode emits it as JSON. Also
     * consumed by the template to render the <option> tags server-side
     * (avoids an Alpine x-for race condition with x-model on the select).
     *
     * @return array<int, array{value:string,label:string}>
     */
    public static function date_format_options(): array {
        $wp_example = wp_date(get_option('date_format', 'F j, Y'));
        return [
            ['value' => '',          'label' => __('— None —', 'ab-markdown-to-bricks')],
            ['value' => 'timestamp', 'label' => __('Unix timestamp', 'ab-markdown-to-bricks')],
            ['value' => 'wp',        'label' => sprintf(
                /* translators: %s: an example date rendered using the site's date_format option. */
                __('WordPress date format (%s)', 'ab-markdown-to-bricks'),
                $wp_example
            )],
            ['value' => 'Y-m-d',  'label' => __('ISO 8601 (2024-01-15)', 'ab-markdown-to-bricks')],
            ['value' => 'F j, Y', 'label' => __('Long (January 15, 2024)', 'ab-markdown-to-bricks')],
            ['value' => 'M j, Y', 'label' => __('Short (Jan 15, 2024)', 'ab-markdown-to-bricks')],
            ['value' => 'd/m/Y',  'label' => __('EU (15/01/2024)', 'ab-markdown-to-bricks')],
            ['value' => 'm/d/Y',  'label' => __('US (01/15/2024)', 'ab-markdown-to-bricks')],
        ];
    }
}
