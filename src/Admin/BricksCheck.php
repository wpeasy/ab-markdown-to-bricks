<?php
/**
 * Bricks Builder dependency check.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks\Admin;

defined('ABSPATH') || exit;

/**
 * Displays an admin notice when Bricks Builder isn't the active theme.
 *
 * The plugin still functions (data isn't lost), but Dynamic Tokens and
 * Query Loops only work when Bricks is active.
 */
final class BricksCheck {

    /**
     * Register hooks.
     */
    public static function init(): void {
        add_action('admin_notices', [self::class, 'maybe_show_notice']);
    }

    /**
     * Whether Bricks Builder is the active theme or a parent of it.
     */
    public static function is_active(): bool {
        if (defined('BRICKS_VERSION')) {
            return true;
        }

        $theme = wp_get_theme();
        return $theme->get('Name') === 'Bricks' || $theme->get('Template') === 'bricks';
    }

    /**
     * Render the admin notice on every admin page when Bricks is missing.
     */
    public static function maybe_show_notice(): void {
        if (self::is_active()) {
            return;
        }

        // Only bother users who could actually switch the theme.
        if (!current_user_can('switch_themes')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('Markdown to Bricks:', 'ab-markdown-to-bricks') . '</strong> ';
        echo esc_html__(
            'Bricks Builder is not the active theme. This plugin requires Bricks to expose Dynamic Tokens and Query Loops. Install and activate the Bricks theme to use the integration.',
            'ab-markdown-to-bricks'
        );
        echo '</p></div>';
    }
}
