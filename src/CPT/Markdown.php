<?php
/**
 * Markdown custom post type.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks\CPT;

defined('ABSPATH') || exit;

/**
 * Registers the abmtb_markdown CPT as its own top-level admin menu.
 */
final class Markdown {

    public const POST_TYPE = 'abmtb_markdown';

    /**
     * Register hooks.
     */
    public static function init(): void {
        add_action('init', [self::class, 'register']);
        add_action('pre_get_posts', [self::class, 'block_public_queries']);
    }

    /**
     * Register the custom post type.
     */
    public static function register(): void {
        $labels = [
            'name'                  => _x('Markdown Parser', 'post type general name', 'ab-markdown-to-bricks'),
            'singular_name'         => _x('Markdown', 'post type singular name', 'ab-markdown-to-bricks'),
            'menu_name'             => _x('Markdown Parser', 'admin menu', 'ab-markdown-to-bricks'),
            'name_admin_bar'        => _x('Markdown', 'add new on admin bar', 'ab-markdown-to-bricks'),
            'add_new'               => __('Add New', 'ab-markdown-to-bricks'),
            'add_new_item'          => __('Add New Markdown', 'ab-markdown-to-bricks'),
            'new_item'              => __('New Markdown', 'ab-markdown-to-bricks'),
            'edit_item'             => __('Edit Markdown', 'ab-markdown-to-bricks'),
            'view_item'             => __('View Markdown', 'ab-markdown-to-bricks'),
            'all_items'             => __('Markdown', 'ab-markdown-to-bricks'),
            'search_items'          => __('Search Markdown', 'ab-markdown-to-bricks'),
            'not_found'             => __('No markdown found.', 'ab-markdown-to-bricks'),
            'not_found_in_trash'    => __('No markdown found in Trash.', 'ab-markdown-to-bricks'),
        ];

        // Privacy stance: this CPT is admin-only. It stores raw source
        // content that should only be exposed through our own parsers
        // (Bricks tokens/queries, WP shortcodes). It must not appear in
        // public WP_Query results, search, REST, sitemaps, or have its
        // own front-end URL.
        register_post_type(self::POST_TYPE, [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 25,
            'menu_icon'           => 'dashicons-media-text',
            'show_in_admin_bar'   => false,
            'show_in_nav_menus'   => false,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'exclude_from_search' => true,
            'can_export'          => true,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'supports'            => ['title'],
        ]);
    }

    /**
     * Belt-and-braces guard: drop this CPT from any unsuppressed
     * front-end main query that has slipped through, in case a theme
     * or third-party plugin explicitly asks for it.
     *
     * Our own parsers should set `suppress_filters => true` (or use
     * `get_posts()` with that arg) when reading this data internally.
     */
    public static function block_public_queries(\WP_Query $query): void {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }
        $requested = (array) $query->get('post_type');
        if (in_array(self::POST_TYPE, $requested, true)) {
            $query->set(
                'post_type',
                array_values(array_diff($requested, [self::POST_TYPE]))
            );
        }
    }
}
