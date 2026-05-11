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
 * Registers the abmtb_markdown CPT under the Bricks admin menu.
 */
final class Markdown {

    public const POST_TYPE = 'abmtb_markdown';

    /**
     * Register hooks.
     */
    public static function init(): void {
        // Priority 11 — runs after Bricks registers its top-level 'bricks' menu
        // on the default priority, so show_in_menu => 'bricks' resolves.
        add_action('init', [self::class, 'register'], 11);
    }

    /**
     * Register the custom post type.
     */
    public static function register(): void {
        $labels = [
            'name'                  => _x('Markdown', 'post type general name', 'ab-markdown-to-bricks'),
            'singular_name'         => _x('Markdown', 'post type singular name', 'ab-markdown-to-bricks'),
            'menu_name'             => _x('Markdown', 'admin menu', 'ab-markdown-to-bricks'),
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

        register_post_type(self::POST_TYPE, [
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'bricks',
            'show_in_admin_bar'   => false,
            'show_in_nav_menus'   => false,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'exclude_from_search' => true,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'supports'            => ['title'],
        ]);
    }
}
