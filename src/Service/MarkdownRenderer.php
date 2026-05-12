<?php
/**
 * Markdown to safe HTML renderer.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks\Service;

defined('ABSPATH') || exit;

/**
 * Thin wrapper around vendored Parsedown.
 *
 * Defence-in-depth strategy:
 *   1. Parsedown safe mode disables raw HTML and untrusted protocols.
 *   2. Parsedown markup-escaped mode escapes any inline HTML the user wrote.
 *   3. The final output passes through wp_kses_post() so anything that slips
 *      past Parsedown is still bounded to WordPress's vetted post HTML set.
 */
final class MarkdownRenderer {

    /**
     * Lazy-loaded Parsedown instance.
     */
    private static ?\Parsedown $parsedown = null;

    /**
     * Render a markdown fragment to sanitised HTML.
     */
    public static function render(string $markdown): string {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        $html = self::instance()->text($markdown);

        return wp_kses_post($html);
    }

    /**
     * Lazy-init the underlying Parsedown instance with safe defaults.
     */
    private static function instance(): \Parsedown {
        if (self::$parsedown !== null) {
            return self::$parsedown;
        }

        if (!class_exists(\Parsedown::class, false)) {
            require_once ABMTB_PLUGIN_PATH . 'lib/Parsedown.php';
        }

        $pd = new \Parsedown();
        $pd->setSafeMode(true);
        $pd->setMarkupEscaped(true);
        $pd->setBreaksEnabled(false);

        return self::$parsedown = $pd;
    }
}
