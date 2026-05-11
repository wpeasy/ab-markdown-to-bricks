<?php
/**
 * Plugin bootstrap.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks;

use AB\MarkdownToBricks\Admin\BricksCheck;
use AB\MarkdownToBricks\CPT\Markdown;

defined('ABSPATH') || exit;

/**
 * Registers all hook integrations for the plugin.
 */
final class Plugin {

    /**
     * Wire up hook-integration classes.
     */
    public static function init(): void {
        Markdown::init();

        if (is_admin()) {
            BricksCheck::init();
        }

        add_action('init', [self::class, 'load_textdomain']);
    }

    /**
     * Load the plugin's translation files.
     */
    public static function load_textdomain(): void {
        load_plugin_textdomain(
            'ab-markdown-to-bricks',
            false,
            dirname(plugin_basename(ABMTB_PLUGIN_FILE)) . '/languages'
        );
    }
}
