<?php
/**
 * Bricks Builder integration: custom Query Loop type + dynamic tags.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks\Bricks;

use AB\MarkdownToBricks\CPT\Markdown;
use AB\MarkdownToBricks\REST\MarkdownController;
use AB\MarkdownToBricks\Service\HeadingCache;
use AB\MarkdownToBricks\Shortcodes\ShortcodesController;

defined('ABSPATH') || exit;

/**
 * Registers everything that makes Markdown CPT data available inside Bricks:
 *
 *  - A "Markdown" entry in the Query Loop type dropdown.
 *  - Two custom controls on layout-container elements (Section / Container /
 *    Block / Div) for choosing the source post and heading level. The
 *    controls only appear when the query type is set to "Markdown".
 *  - Three dynamic tags: {md-title}, {md-description}, {md-date}.
 *
 * Activates only when the Bricks theme is the active theme — otherwise the
 * filters do nothing.
 */
final class Integration {

    /**
     * Element names that commonly host Query Loops. Custom controls are
     * filtered onto each one so the Markdown controls appear regardless of
     * which container the user picked.
     */
    private const LOOP_ELEMENTS = ['section', 'container', 'block', 'div'];

    /**
     * Outer query type — selects the source Markdown post. Acts as a
     * single-iteration context provider. Nested per-label loops walk the
     * Bricks active-loop stack to find this one and inherit its post.
     */
    public const OBJECT_TYPE_OUTER = 'markdown';

    /**
     * Inner per-label query types are named `markdown_<label>` (e.g.
     * `markdown_changelog`). One per unique enabled label across all
     * Markdown posts.
     */
    public const INNER_PREFIX = 'markdown_';

    /**
     * Allowed `sort` values on the per-label inner query controls.
     */
    private const ALLOWED_SORTS = ['', 'asc', 'desc', 'date', 'date_desc'];

    /**
     * Per-element error flags set during run_query and consumed in the
     * no_results_content filter. Keyed by Bricks element ID; value is the
     * inner-loop label that failed.
     *
     * @var array<string, string>
     */
    private static array $missing_outer_errors = [];

    /**
     * Wire all Bricks filters.
     */
    public static function init(): void {
        if (!self::bricks_active()) {
            return;
        }

        add_filter('bricks/setup/control_options', [self::class, 'add_query_type']);

        foreach (self::LOOP_ELEMENTS as $element) {
            add_filter("bricks/elements/{$element}/controls", [self::class, 'add_controls']);
        }

        add_filter('bricks/query/run', [self::class, 'run_query'], 10, 2);
        add_filter('bricks/query/loop_object', [self::class, 'set_loop_object'], 10, 3);
        add_filter('bricks/query/no_results_content', [self::class, 'render_missing_outer_error'], 10, 3);

        add_filter('bricks/dynamic_tags_list', [self::class, 'register_tags']);
        add_filter('bricks/dynamic_data/render_tag', [self::class, 'render_tag'], 10, 3);
        add_filter('bricks/dynamic_data/render_content', [self::class, 'render_content'], 10, 3);
    }

    /**
     * Whether the Bricks theme is active (parent or current).
     */
    public static function bricks_active(): bool {
        if (defined('BRICKS_VERSION')) {
            return true;
        }
        $theme = wp_get_theme();
        return $theme->get('Name') === 'Bricks' || $theme->get('Template') === 'bricks';
    }

    /**
     * Add "Markdown" + every per-label inner type to the queryTypes dropdown.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function add_query_type(array $options): array {
        if (!isset($options['queryTypes']) || !is_array($options['queryTypes'])) {
            $options['queryTypes'] = [];
        }

        $options['queryTypes'][self::OBJECT_TYPE_OUTER] = esc_html__('Markdown', 'ab-markdown-to-bricks');

        foreach (ShortcodesController::collect_all_labels() as $label) {
            $key = self::INNER_PREFIX . $label;
            /* translators: %s: a normalised heading label (e.g. "topic"). */
            $options['queryTypes'][$key] = sprintf(esc_html__('Markdown — %s', 'ab-markdown-to-bricks'), $label);
        }
        return $options;
    }

    /**
     * Add Markdown query controls to layout elements. Controls are gated on
     * `query.objectType === 'markdown'` so they only appear after the user
     * picks the Markdown query type.
     *
     * @param array<string, mixed> $controls
     * @return array<string, mixed>
     */
    public static function add_controls(array $controls): array {
        if (isset($controls['abmtbMdSeparator'])) {
            return $controls;
        }

        $inner_types = [];
        foreach (ShortcodesController::collect_all_labels() as $label) {
            $inner_types[] = self::INNER_PREFIX . $label;
        }
        $all_md_types = array_merge([self::OBJECT_TYPE_OUTER], $inner_types);

        $new = [
            'abmtbMdSeparator' => [
                'tab'      => 'content',
                'type'     => 'separator',
                'label'    => esc_html__('Markdown Loop', 'ab-markdown-to-bricks'),
                'required' => ['query.objectType', '=', $all_md_types],
            ],
            'abmtbMdInfo' => [
                'tab'      => 'content',
                'type'     => 'info',
                'content'  => esc_html__(
                    'Outer "Markdown" loop: pick a source post. Inner "Markdown — <label>" loops (nested inside the outer) inherit the post and iterate that label\'s headings with sort + filter.',
                    'ab-markdown-to-bricks'
                ),
                'required' => ['query.objectType', '=', $all_md_types],
            ],
            'abmtbMdSource' => [
                'tab'         => 'content',
                'label'       => esc_html__('Markdown post', 'ab-markdown-to-bricks'),
                'type'        => 'select',
                'options'     => self::get_post_options(),
                'placeholder' => esc_html__('Select a Markdown post', 'ab-markdown-to-bricks'),
                'searchable'  => true,
                'description' => esc_html__('Source post for the outer "Markdown" loop.', 'ab-markdown-to-bricks'),
                'required'    => ['query.objectType', '=', self::OBJECT_TYPE_OUTER],
            ],
            'abmtbMdSort' => [
                'tab'         => 'content',
                'label'       => esc_html__('Sort', 'ab-markdown-to-bricks'),
                'type'        => 'select',
                'options'     => [
                    ''          => esc_html__('Document order', 'ab-markdown-to-bricks'),
                    'asc'       => esc_html__('Heading text A → Z', 'ab-markdown-to-bricks'),
                    'desc'      => esc_html__('Heading text Z → A', 'ab-markdown-to-bricks'),
                    'date'      => esc_html__('Date ascending', 'ab-markdown-to-bricks'),
                    'date_desc' => esc_html__('Date descending', 'ab-markdown-to-bricks'),
                ],
                'placeholder' => esc_html__('Document order', 'ab-markdown-to-bricks'),
                'required'    => ['query.objectType', '=', $inner_types],
            ],
            'abmtbMdFilter' => [
                'tab'         => 'content',
                'label'       => esc_html__('Filter', 'ab-markdown-to-bricks'),
                'type'        => 'text',
                'placeholder' => esc_html__('Substring match…', 'ab-markdown-to-bricks'),
                'description' => esc_html__('Case-insensitive substring match on heading text.', 'ab-markdown-to-bricks'),
                'required'    => ['query.objectType', '=', $inner_types],
            ],
        ];

        // Splice the new controls in directly after Bricks' own `query`
        // control so the Markdown fields appear immediately under the
        // Query loop popup, not at the end of the element's panel.
        return self::insert_after($controls, 'query', $new);
    }

    /**
     * Return $controls with $insertion spliced in directly after $after_key.
     * Falls back to appending if $after_key isn't present.
     *
     * @param array<string, mixed> $controls
     * @param array<string, mixed> $insertion
     * @return array<string, mixed>
     */
    private static function insert_after(array $controls, string $after_key, array $insertion): array {
        $keys     = array_keys($controls);
        $position = array_search($after_key, $keys, true);
        if ($position === false) {
            return $controls + $insertion;
        }
        return array_slice($controls, 0, $position + 1, true)
            + $insertion
            + array_slice($controls, $position + 1, null, true);
    }

    /**
     * Provide loop items for either the outer "Markdown" type or any inner
     * "Markdown — <label>" type.
     *
     * @param array<int, mixed>|mixed $results
     * @param object                  $query   Bricks\Query instance.
     * @return mixed
     */
    public static function run_query($results, $query) {
        $object_type = $query->object_type ?? '';

        if ($object_type === self::OBJECT_TYPE_OUTER) {
            return self::run_outer_query($query);
        }

        if (is_string($object_type) && strpos($object_type, self::INNER_PREFIX) === 0) {
            $label = (string) substr($object_type, strlen(self::INNER_PREFIX));
            return self::run_inner_query($query, $label);
        }

        return $results;
    }

    /**
     * Outer loop body — a single-iteration "context provider". The single
     * item carries the chosen post's ID; nested per-label loops pull it
     * back out via current_outer_post_id().
     *
     * @return array<int, array<string, mixed>>
     */
    private static function run_outer_query($query): array {
        $settings = is_array($query->settings ?? null) ? $query->settings : [];
        $post_id  = (int) ($settings['abmtbMdSource'] ?? 0);
        if ($post_id <= 0) {
            return [];
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== Markdown::POST_TYPE) {
            return [];
        }

        return [[
            '_abmtb_post_id' => $post_id,
            '_abmtb_outer'   => true,
            'post'           => $post,
            'title'          => $post->post_title,
        ]];
    }

    /**
     * Inner loop body — iterate headings at a given label's level, inside
     * the post chosen by the enclosing outer "Markdown" loop.
     *
     * Applies the `sort` and `filter` controls.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function run_inner_query($query, string $label): array {
        $post_id = self::current_outer_post_id();
        if ($post_id <= 0) {
            // Flag this element so the no_results filter can show a clear
            // "you need an outer loop" message instead of silently rendering
            // nothing.
            $element_id = (string) ($query->element_id ?? '');
            if ($element_id !== '') {
                self::$missing_outer_errors[$element_id] = $label;
            }
            return [];
        }

        $level = self::level_for_label($post_id, $label);
        if ($level === 0) {
            return [];
        }

        $headings = HeadingCache::get($post_id);

        // Scope to the document range belonging to the nearest enclosing
        // inner-Markdown loop's current iteration. Without this, every
        // nested loop iterates the entire document instead of just the
        // section under its parent heading.
        [$start_idx, $end_idx] = self::parent_range($headings);

        $items = [];
        foreach ($headings as $idx => $h) {
            if ($idx < $start_idx || $idx >= $end_idx) {
                continue;
            }
            if ((int) $h['level'] !== $level) {
                continue;
            }
            $h['_abmtb_post_id'] = $post_id;
            $h['_abmtb_level']   = $level;
            $h['_abmtb_label']   = $label;
            $h['_abmtb_idx']     = $idx;
            $items[] = $h;
        }

        $settings = is_array($query->settings ?? null) ? $query->settings : [];

        // Filter
        $filter = trim((string) ($settings['abmtbMdFilter'] ?? ''));
        if ($filter !== '') {
            $items = array_values(array_filter($items, static function ($h) use ($filter): bool {
                return stripos((string) $h['text'], $filter) !== false;
            }));
        }

        // Sort
        $sort = strtolower((string) ($settings['abmtbMdSort'] ?? ''));
        if (!in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = '';
        }
        if ($sort === 'asc' || $sort === 'desc') {
            // strnatcasecmp = natural-order, case-insensitive. Critical for
            // version-like titles (v0.1.99 must come before v0.1.100, which
            // strcasecmp gets wrong because "1" < "9" lexicographically).
            usort($items, static function ($a, $b) use ($sort): int {
                $cmp = strnatcasecmp((string) $a['text'], (string) $b['text']);
                return $sort === 'asc' ? $cmp : -$cmp;
            });
        } elseif ($sort === 'date' || $sort === 'date_desc') {
            usort($items, static function ($a, $b) use ($sort): int {
                $da = is_int($a['date'] ?? null) ? $a['date'] : PHP_INT_MAX;
                $db = is_int($b['date'] ?? null) ? $b['date'] : PHP_INT_MAX;
                $cmp = $da <=> $db;
                // Same-day items: tiebreak by natural-order title so two
                // releases on the same date still sort sensibly.
                if ($cmp === 0) {
                    $cmp = strnatcasecmp((string) $a['text'], (string) $b['text']);
                }
                return $sort === 'date' ? $cmp : -$cmp;
            });
        }

        return $items;
    }

    /**
     * Compute the [start, end) heading-index range that an inner loop is
     * allowed to iterate within, based on its nearest enclosing inner-
     * Markdown loop's current iteration. The end index is the position of
     * the next heading at the parent's level or shallower — i.e. where
     * the parent's "section" ends in the document.
     *
     * Returns the full-document range [0, count) when no inner parent is
     * active. Outer "markdown" loops don't count as parents — they set the
     * post context, not the heading-range scope.
     *
     * @param array<int, array<string, mixed>> $headings
     * @return array{0:int,1:int}
     */
    private static function parent_range(array $headings): array {
        $count = count($headings);

        global $bricks_loop_query;
        if (empty($bricks_loop_query) || !is_array($bricks_loop_query)) {
            return [0, $count];
        }

        foreach (array_reverse($bricks_loop_query) as $loop) {
            if (!is_object($loop) || empty($loop->is_looping)) {
                continue;
            }
            $type = $loop->object_type ?? '';
            if (!is_string($type) || strpos($type, self::INNER_PREFIX) !== 0) {
                continue;
            }
            $obj = $loop->loop_object ?? null;
            if (!is_array($obj) || !isset($obj['_abmtb_idx'], $obj['level'])) {
                continue;
            }

            $parent_idx   = (int) $obj['_abmtb_idx'];
            $parent_level = (int) $obj['level'];
            if ($parent_level <= 0) {
                continue;
            }

            $end_idx = $count;
            for ($i = $parent_idx + 1; $i < $count; $i++) {
                $h_level = (int) ($headings[$i]['level'] ?? 0);
                if ($h_level > 0 && $h_level <= $parent_level) {
                    $end_idx = $i;
                    break;
                }
            }

            return [$parent_idx + 1, $end_idx];
        }

        return [0, $count];
    }

    /**
     * Walk Bricks' active-loop stack to find the nearest enclosing
     * "Markdown" loop and return its post_id. 0 when no outer is found.
     */
    private static function current_outer_post_id(): int {
        global $bricks_loop_query;
        if (empty($bricks_loop_query) || !is_array($bricks_loop_query)) {
            return 0;
        }
        // Newest loops sit at the end; walk in reverse so we pick the
        // innermost enclosing outer-Markdown loop.
        foreach (array_reverse($bricks_loop_query) as $loop) {
            if (!is_object($loop) || empty($loop->is_looping)) {
                continue;
            }
            if (($loop->object_type ?? '') !== self::OBJECT_TYPE_OUTER) {
                continue;
            }
            $obj = $loop->loop_object ?? null;
            if (is_array($obj) && isset($obj['_abmtb_post_id'])) {
                return (int) $obj['_abmtb_post_id'];
            }
        }
        return 0;
    }

    /**
     * Pass-through hook — our items are already the right shape. Other
     * Bricks query types map IDs to objects here; we don't need that.
     *
     * @param mixed  $loop_object
     * @param mixed  $loop_key
     * @param object $query
     */
    public static function set_loop_object($loop_object, $loop_key, $query) {
        return $loop_object;
    }

    /**
     * Render a visible error when an inner Markdown loop runs without an
     * enclosing outer Markdown loop. Hooked to bricks/query/no_results_content
     * so it overrides the empty / "nothing found" fallback Bricks would
     * otherwise show.
     *
     * Restricted to builder context and logged-in editors so anonymous
     * frontend visitors never see plugin-internal messages.
     *
     * @param string               $content
     * @param array<string, mixed> $settings
     * @param string               $element_id
     */
    public static function render_missing_outer_error($content, $settings, $element_id) {
        if (!is_string($element_id) || !isset(self::$missing_outer_errors[$element_id])) {
            return $content;
        }

        $label = self::$missing_outer_errors[$element_id];
        // Consume — one render per failure, then forget it.
        unset(self::$missing_outer_errors[$element_id]);

        if (!self::should_show_builder_errors()) {
            return $content;
        }

        $style = 'padding:10px 14px;margin:0;border:1px solid #d63638;'
               . 'background:#fcf0f1;color:#1d2327;border-radius:3px;'
               . 'font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;';

        return '<div class="abmtb-loop-error" style="' . esc_attr($style) . '">'
            . '<strong style="color:#d63638;">'
            . esc_html__('Markdown to Bricks', 'ab-markdown-to-bricks')
            . '</strong> — '
            . sprintf(
                /* translators: %s: the inner loop's label (e.g. "topic") */
                esc_html__('The "Markdown — %s" loop must be nested inside an outer "Markdown" query loop. Without it there is no source post to pull from.', 'ab-markdown-to-bricks'),
                esc_html($label)
            )
            . '</div>';
    }

    /**
     * Whether to show builder-style error notices for the current request.
     * True in the Bricks builder / preview iframe, and for logged-in users
     * with `edit_posts`. False for anonymous public visitors.
     */
    private static function should_show_builder_errors(): bool {
        if (function_exists('bricks_is_builder') && bricks_is_builder()) {
            return true;
        }
        if (function_exists('bricks_is_builder_iframe') && bricks_is_builder_iframe()) {
            return true;
        }
        return is_user_logged_in() && current_user_can('edit_posts');
    }

    /**
     * Add the three Markdown tags to Bricks' dynamic-data picker.
     *
     * @param array<int, array<string, string>> $tags
     * @return array<int, array<string, string>>
     */
    public static function register_tags(array $tags): array {
        $group = esc_html__('Markdown', 'ab-markdown-to-bricks');
        $tags[] = ['name' => '{md-title}',       'label' => esc_html__('MD: Title',       'ab-markdown-to-bricks'), 'group' => $group];
        $tags[] = ['name' => '{md-description}', 'label' => esc_html__('MD: Description', 'ab-markdown-to-bricks'), 'group' => $group];
        $tags[] = ['name' => '{md-date}',        'label' => esc_html__('MD: Date',        'ab-markdown-to-bricks'), 'group' => $group];
        return $tags;
    }

    /**
     * Resolve a single tag. Bricks calls this once per `{tag}` it encounters.
     *
     * @param string $tag     The full tag including braces (and any |filters).
     * @param mixed  $post    Bricks passes the queried post — we ignore it.
     * @param string $context 'text' | 'link' | 'image'
     */
    public static function render_tag($tag, $post = null, $context = 'text') {
        if (!is_string($tag) || strpos($tag, '{md-') !== 0) {
            return $tag;
        }

        // Strip braces and any "|filter" suffix Bricks may append.
        $inner = trim($tag, '{}');
        $bare  = explode('|', $inner, 2)[0] ?? '';

        if (!in_array($bare, ['md-title', 'md-description', 'md-date'], true)) {
            return $tag;
        }

        $value = self::resolve($bare);
        // If we're not in a Markdown loop, return empty so Bricks doesn't
        // print the raw `{md-...}` literal on the page.
        return $value;
    }

    /**
     * Replace any inline `{md-*}` tokens in a chunk of content.
     */
    public static function render_content($content, $post = null, $context = 'text') {
        if (!is_string($content) || strpos($content, '{md-') === false) {
            return $content;
        }
        return preg_replace_callback(
            '/\{md-(title|description|date)\}/',
            static function (array $m): string {
                return (string) self::resolve('md-' . $m[1]);
            },
            $content
        );
    }

    /**
     * Read the current Markdown loop object and produce the requested field.
     *
     * Tokens only resolve when we're inside a per-label INNER loop (where
     * the loop object is a heading). If only the outer "Markdown" loop is
     * active, the loop object is the post-context — there's no heading
     * yet, so we return empty rather than guessing.
     */
    private static function resolve(string $bare): string {
        if (!class_exists('\\Bricks\\Query')) {
            return '';
        }
        $loop_object = \Bricks\Query::get_loop_object();
        if (!is_array($loop_object)
            || !isset($loop_object['_abmtb_post_id'])
            || !empty($loop_object['_abmtb_outer'])
            || !isset($loop_object['level'], $loop_object['text'])
        ) {
            return '';
        }

        $post_id = (int) $loop_object['_abmtb_post_id'];
        $level   = (int) $loop_object['_abmtb_level'];
        $format  = self::date_format_for($post_id, $level);

        switch ($bare) {
            case 'md-title':
                $text = ($format !== '' && isset($loop_object['text_clean']))
                    ? (string) $loop_object['text_clean']
                    : (string) ($loop_object['text'] ?? '');
                return esc_html($text);
            case 'md-description':
                return (string) ($loop_object['html'] ?? '');
            case 'md-date':
                $ts = $loop_object['date'] ?? null;
                if (!is_int($ts)) {
                    return '';
                }
                return self::format_date($ts, $format);
        }
        return '';
    }

    /**
     * Build the Markdown-post dropdown options. Title-keyed by post ID
     * (string-cast for Bricks' select control).
     *
     * @return array<string, string>
     */
    private static function get_post_options(): array {
        $post_ids = get_posts([
            'post_type'        => Markdown::POST_TYPE,
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'post_status'      => ['publish', 'private', 'draft'],
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'orderby'          => 'title',
            'order'            => 'ASC',
        ]);

        $options = [];
        foreach ($post_ids as $pid) {
            $title = get_the_title($pid);
            $slug  = get_post_field('post_name', $pid);
            $options[(string) $pid] = ($title !== '' ? $title : '#' . $pid)
                . ($slug !== '' ? ' (' . $slug . ')' : '');
        }
        return $options;
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
            $cfg_label = ShortcodesController::normalise_underscore((string) ($cfg['label'] ?? ''));
            if ($cfg_label === $label) {
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
}
