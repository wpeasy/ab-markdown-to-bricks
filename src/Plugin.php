<?php
/**
 * Plugin bootstrap.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks;

use AB\MarkdownToBricks\Admin\EditScreen;
use AB\MarkdownToBricks\Bricks\Integration as BricksIntegration;
use AB\MarkdownToBricks\CPT\Markdown;
use AB\MarkdownToBricks\REST\MarkdownController;
use AB\MarkdownToBricks\Service\HeadingCache;
use AB\MarkdownToBricks\Shortcodes\ShortcodesController;

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
        HeadingCache::init();
        MarkdownController::init();
        ShortcodesController::init();
        BricksIntegration::init();

        if (is_admin()) {
            EditScreen::init();
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
