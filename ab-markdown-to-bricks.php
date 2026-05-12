<?php
/**
 * Plugin Name:       Markdown to Bricks
 * Plugin URI:        https://wpeasy.au/
 * Description:       Upload a Markdown file, parse its headings, and expose the parsed data to Bricks Builder via Dynamic Tokens and Query Loops.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Alan Blair
 * Author URI:        https://wpeasy.au/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ab-markdown-to-bricks
 * Domain Path:       /languages
 *
 * @package AB\MarkdownToBricks
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('ABMTB_VERSION', '0.1.1');
define('ABMTB_PLUGIN_FILE', __FILE__);
define('ABMTB_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('ABMTB_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Minimal PSR-4 autoloader for the AB\MarkdownToBricks namespace.
 *
 * Maps AB\MarkdownToBricks\Foo\Bar → src/Foo/Bar.php.
 */
spl_autoload_register(static function (string $class): void {
    $prefix   = 'AB\\MarkdownToBricks\\';
    $base_dir = ABMTB_PLUGIN_PATH . 'src/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $base_dir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

\AB\MarkdownToBricks\Plugin::init();
