<?php
/**
 * WordPress shortcode integration for Markdown posts.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks\Shortcodes;

use AB\MarkdownToBricks\CPT\Markdown;
use AB\MarkdownToBricks\REST\MarkdownController;
use AB\MarkdownToBricks\Service\HeadingCache;

defined('ABSPATH') || exit;

/**
 * Registers and renders shortcodes for Markdown CPT loops.
 *
 * Two layers of shortcodes:
 *
 * 1. **Outer loop tags** — one per unique label across all Markdown posts.
 *    `[markdown_<label> source="post"]...[/markdown_<label>]` runs the
 *    content once per heading at that label's level. Supports `sort`,
 *    `filter`, and `date_format` attributes for inline control.
 *
 * 2. **Inner accessors** — `[md_title]`, `[md_description]`, `[md_date]`.
 *    Registered globally at `init` so they're available anywhere, but
 *    their handlers return an empty string when there is no surrounding
 *    Markdown loop context. The `md_` prefix keeps them out of the
 *    crowded global shortcode namespace.
 */
final class ShortcodesController {

    private const LABEL_CACHE_KEY = 'abmtb_registered_labels';
    private const LABEL_CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Wire hooks.
     */
    public static function init(): void {
        add_action('init', [self::class, 'register_shortcodes'], 20);
        add_action('updated_post_meta', [self::class, 'maybe_invalidate_label_cache'], 10, 3);
        add_action('added_post_meta', [self::class, 'maybe_invalidate_label_cache'], 10, 3);
        add_action('deleted_post', [self::class, 'invalidate_label_cache_for_post']);
    }

    /**
     * Allowed sort values for the loop's `sort` attribute.
     */
    private const ALLOWED_SORTS = ['', 'asc', 'desc', 'date', 'date_desc'];

    /**
     * Register one outer loop shortcode per unique label found across all
     * Markdown CPT posts, plus the three global inner accessors.
     */
    public static function register_shortcodes(): void {
        // Inner accessors — namespaced with md_ so they don't collide with
        // common short shortcode names other plugins might register.
        add_shortcode('md_title', [self::class, 'render_inner_title']);
        add_shortcode('md_description', [self::class, 'render_inner_description']);
        add_shortcode('md_date', [self::class, 'render_inner_date']);

        // Outer loop tags — one per unique label across all Markdown posts.
        $labels = self::collect_all_labels();
        foreach ($labels as $label) {
            add_shortcode('markdown_' . $label, [self::class, 'render_loop']);
        }
    }

    /**
     * Outer loop handler. Self-closing usage is a no-op (the loop body is
     * what makes the shortcode meaningful).
     *
     * @param array<string, string>|string $atts
     */
    public static function render_loop($atts, ?string $content = null, string $tag = ''): string {
        if ($content === null || $content === '') {
            return '';
        }
        $label = self::extract_label($tag);
        if ($label === '') {
            return '';
        }
        return self::run_loop(is_array($atts) ? $atts : [], $content, $label);
    }

    /**
     * Inner accessor: current iteration's title. When a dateFormat is set
     * on the level, returns `text_clean` so the date isn't repeated when
     * the template uses both [title] and [date].
     */
    public static function render_inner_title($atts, $content = null, string $tag = ''): string {
        $frame = LoopContext::top();
        if (!$frame) {
            return '';
        }
        $heading = $frame['heading'];
        $format  = self::date_format_for((int) $frame['post_id'], (int) $frame['level']);
        $text    = ($format !== '' && isset($heading['text_clean']))
            ? (string) $heading['text_clean']
            : (string) $heading['text'];
        return esc_html($text);
    }

    /**
     * Inner accessor: current iteration's parsed-HTML description.
     */
    public static function render_inner_description($atts, $content = null, string $tag = ''): string {
        $frame = LoopContext::top();
        return $frame ? (string) $frame['heading']['html'] : '';
    }

    /**
     * Inner accessor: current iteration's formatted date.
     *
     * Honours the loop's `date_format` attribute when set, otherwise falls
     * back to the saved per-level preference on the source post.
     */
    public static function render_inner_date($atts, $content = null, string $tag = ''): string {
        $frame = LoopContext::top();
        if (!$frame) {
            return '';
        }
        $timestamp = $frame['heading']['date'] ?? null;
        if (!is_int($timestamp)) {
            return '';
        }
        $override = (string) ($frame['date_format'] ?? '');
        $format   = $override !== ''
            ? $override
            : self::date_format_for((int) $frame['post_id'], (int) $frame['level']);
        return self::format_date($timestamp, $format);
    }

    /**
     * Run the loop body once per heading at the label's level.
     *
     * Recognised attributes:
     *   - source       — post slug or numeric ID (resolved against outer
     *                    loop / current post as a fallback)
     *   - sort         — '' (document order, default), 'asc' / 'desc'
     *                    (alphabetical by title), 'date' / 'date_desc'
     *   - filter       — case-insensitive substring match on heading text
     *   - date_format  — overrides the saved per-level dateFormat for this
     *                    loop's [md_date] output (same allowlist)
     *
     * @param array<string, string> $atts
     */
    private static function run_loop(array $atts, string $content, string $label): string {
        $atts = shortcode_atts(
            [
                'source'      => '',
                'sort'        => '',
                'filter'      => '',
                'date_format' => '',
            ],
            $atts,
            'markdown_' . $label
        );

        $post_id = self::resolve_source((string) $atts['source']);
        if ($post_id <= 0) {
            return '';
        }

        $level = self::level_for_label($post_id, $label);
        if ($level === 0) {
            return '';
        }

        $headings = HeadingCache::get($post_id);

        // Keep only headings at this label's level.
        $items = [];
        foreach ($headings as $h) {
            if ((int) $h['level'] === $level) {
                $items[] = $h;
            }
        }

        // Optional substring filter on heading text.
        $filter = trim((string) $atts['filter']);
        if ($filter !== '') {
            $items = array_values(array_filter($items, static function ($h) use ($filter): bool {
                return stripos((string) $h['text'], $filter) !== false;
            }));
        }

        // Optional sort.
        $sort = strtolower((string) $atts['sort']);
        if (!in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = '';
        }
        if ($sort === 'asc' || $sort === 'desc') {
            usort($items, static function ($a, $b) use ($sort): int {
                $cmp = strcasecmp((string) $a['text'], (string) $b['text']);
                return $sort === 'asc' ? $cmp : -$cmp;
            });
        } elseif ($sort === 'date' || $sort === 'date_desc') {
            usort($items, static function ($a, $b) use ($sort): int {
                $da = is_int($a['date'] ?? null) ? $a['date'] : PHP_INT_MAX;
                $db = is_int($b['date'] ?? null) ? $b['date'] : PHP_INT_MAX;
                $cmp = $da <=> $db;
                return $sort === 'date' ? $cmp : -$cmp;
            });
        }

        // Validate inline date_format override.
        $date_override = (string) $atts['date_format'];
        if (!in_array($date_override, MarkdownController::ALLOWED_DATE_FORMATS, true)) {
            $date_override = '';
        }

        $output = '';
        foreach ($items as $h) {
            LoopContext::push($post_id, $label, $level, $h, $date_override);
            $output .= do_shortcode($content);
            LoopContext::pop();
        }
        return $output;
    }

    /**
     * Resolve a `source` attribute to a Markdown post ID.
     * Order:
     *   1. Numeric ID matching our CPT
     *   2. Post slug matching our CPT
     *   3. Inherit from outer loop frame (no attr given)
     *   4. Current post if it's our CPT
     *   5. 0 (no source)
     */
    private static function resolve_source(string $source): int {
        $source = trim($source);

        if ($source === '') {
            $outer = LoopContext::top();
            if ($outer) {
                return (int) $outer['post_id'];
            }
            $post = get_post();
            if ($post && $post->post_type === Markdown::POST_TYPE) {
                return (int) $post->ID;
            }
            return 0;
        }

        if (ctype_digit($source)) {
            $post = get_post((int) $source);
            return ($post && $post->post_type === Markdown::POST_TYPE) ? (int) $post->ID : 0;
        }

        $page = get_page_by_path($source, OBJECT, Markdown::POST_TYPE);
        return $page ? (int) $page->ID : 0;
    }

    /**
     * Find which heading level a label maps to on a given post.
     */
    private static function level_for_label(int $post_id, string $label): int {
        $settings = MarkdownController::get_level_settings($post_id);
        foreach ($settings as $level => $cfg) {
            if (empty($cfg['enabled'])) {
                continue;
            }
            if (self::normalise_underscore((string) ($cfg['label'] ?? '')) === $label) {
                return (int) $level;
            }
        }
        return 0;
    }

    /**
     * Look up the saved date format for a post/level.
     */
    private static function date_format_for(int $post_id, int $level): string {
        $settings = MarkdownController::get_level_settings($post_id);
        $cfg = $settings[(string) $level] ?? null;
        return is_array($cfg) ? (string) ($cfg['dateFormat'] ?? '') : '';
    }

    /**
     * Apply a stored format string (or sentinel) to a Unix timestamp.
     */
    private static function format_date(int $timestamp, string $format): string {
        if ($format === '') {
            return '';
        }
        if ($format === 'timestamp') {
            return (string) $timestamp;
        }
        if ($format === 'wp') {
            $wp_format = (string) get_option('date_format', 'F j, Y');
            return (string) wp_date($wp_format, $timestamp);
        }
        return (string) wp_date($format, $timestamp);
    }

    /**
     * Strip the `markdown_` prefix from an outer loop tag to recover the label.
     */
    private static function extract_label(string $tag): string {
        $prefix = 'markdown_';
        if (strpos($tag, $prefix) !== 0) {
            return '';
        }
        return (string) substr($tag, strlen($prefix));
    }

    /**
     * Collect the union of every enabled, normalised label across every
     * Markdown CPT post. Cached in a transient and invalidated on post-meta
     * write to keep registration cheap.
     *
     * @return array<int, string>
     */
    public static function collect_all_labels(): array {
        $cached = get_transient(self::LABEL_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $post_ids = get_posts([
            'post_type'        => Markdown::POST_TYPE,
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'post_status'      => ['publish', 'private', 'draft'],
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);

        $labels = [];
        foreach ($post_ids as $post_id) {
            $settings = MarkdownController::get_level_settings((int) $post_id);
            foreach ($settings as $cfg) {
                if (empty($cfg['enabled'])) {
                    continue;
                }
                $normalised = self::normalise_underscore((string) ($cfg['label'] ?? ''));
                if ($normalised !== '') {
                    $labels[$normalised] = true;
                }
            }
        }

        $list = array_keys($labels);
        set_transient(self::LABEL_CACHE_KEY, $list, self::LABEL_CACHE_TTL);
        return $list;
    }

    /**
     * Lowercase + collapse non-alphanumerics to underscores.
     * Mirrors the JS normaliser used for shortcode previews in the editor.
     */
    public static function normalise_underscore(string $label): string {
        $label = function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label);
        $label = (string) preg_replace('/[^a-z0-9]+/', '_', $label);
        return trim($label, '_');
    }

    /**
     * Drop the label cache when our level-settings meta changes on any post.
     */
    public static function maybe_invalidate_label_cache(int $meta_id, int $post_id, string $meta_key): void {
        if ($meta_key === MarkdownController::META_LEVEL_SETTINGS) {
            delete_transient(self::LABEL_CACHE_KEY);
        }
    }

    /**
     * Drop the label cache when a Markdown post is deleted.
     */
    public static function invalidate_label_cache_for_post(int $post_id): void {
        if (get_post_type($post_id) === Markdown::POST_TYPE) {
            delete_transient(self::LABEL_CACHE_KEY);
        }
    }
}
