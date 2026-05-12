<?php
/**
 * Parsed-heading cache.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks\Service;

use AB\MarkdownToBricks\CPT\Markdown;
use AB\MarkdownToBricks\Parser\HeadingParser;

defined('ABSPATH') || exit;

/**
 * Caches the parsed-headings array per Markdown post.
 *
 * Two layers:
 *
 *  1. **Post-meta cache** — keyed by md5(post_content). Stored alongside a
 *     schema version so bumping the parser output shape invalidates every
 *     cache automatically.
 *
 *  2. **Request-level static cache** — populated on first read, reused for
 *     every subsequent read in the same request. Lets multiple Bricks
 *     loops + shortcodes on one page share the same parsed result without
 *     hitting the DB / object cache repeatedly.
 *
 * Why hash the content instead of using `post_modified`? Because a third
 * party could update meta or run wp_update_post on the row in a way that
 * touches post_modified without changing the actual content. Hashing is
 * cheap and content-correct.
 */
final class HeadingCache {

    public const META_KEY = '_abmtb_parsed_cache';

    /**
     * Bumped whenever HeadingParser's output changes — either the shape
     * (new / removed fields) or the meaning of an existing field. Causes
     * every persisted cache to rebuild lazily on the next read after a
     * plugin upgrade.
     *
     * 2: text_clean now also strips wrapping brackets around dates.
     */
    private const VERSION = 2;

    /**
     * Per-request memoisation. Keyed by post_id.
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    private static array $runtime = [];

    /**
     * Wire post-save invalidation. Cheap to call — only sets up one action.
     */
    public static function init(): void {
        add_action('save_post_' . Markdown::POST_TYPE, [self::class, 'invalidate'], 20, 1);
        add_action('deleted_post', [self::class, 'invalidate']);
    }

    /**
     * Return the parsed headings for a Markdown post, building and storing
     * the cache when missing or stale.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get(int $post_id): array {
        if ($post_id <= 0) {
            return [];
        }
        if (isset(self::$runtime[$post_id])) {
            return self::$runtime[$post_id];
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== Markdown::POST_TYPE) {
            self::$runtime[$post_id] = [];
            return [];
        }

        $content = (string) $post->post_content;
        $hash    = self::hash($content);

        $cached = get_post_meta($post_id, self::META_KEY, true);
        if (is_array($cached)
            && (int) ($cached['version'] ?? 0) === self::VERSION
            && (string) ($cached['hash'] ?? '') === $hash
            && is_array($cached['headings'] ?? null)
        ) {
            self::$runtime[$post_id] = $cached['headings'];
            return $cached['headings'];
        }

        return self::store($post_id, $content, $hash);
    }

    /**
     * Force a rebuild from current post_content. Useful right after the
     * REST endpoints save new content so the next read is a memory hit.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rebuild(int $post_id): array {
        if ($post_id <= 0) {
            return [];
        }
        $post = get_post($post_id);
        if (!$post || $post->post_type !== Markdown::POST_TYPE) {
            return [];
        }
        $content = (string) $post->post_content;
        return self::store($post_id, $content, self::hash($content));
    }

    /**
     * Drop both cache layers for a post. Called on save_post (in case the
     * post was edited via the classic editor or programmatic flow) and on
     * deleted_post (housekeeping).
     */
    public static function invalidate(int $post_id): void {
        $post_id = (int) $post_id;
        if ($post_id > 0) {
            unset(self::$runtime[$post_id]);
            delete_post_meta($post_id, self::META_KEY);
        }
    }

    /**
     * Parse content, write the persistent cache entry, prime the runtime
     * cache, and return the headings.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function store(int $post_id, string $content, string $hash): array {
        $headings = HeadingParser::parse($content);

        update_post_meta($post_id, self::META_KEY, [
            'version'  => self::VERSION,
            'hash'     => $hash,
            'built_at' => time(),
            'headings' => $headings,
        ]);

        self::$runtime[$post_id] = $headings;
        return $headings;
    }

    private static function hash(string $content): string {
        return md5($content);
    }
}
