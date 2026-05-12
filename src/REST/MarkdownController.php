<?php
/**
 * REST controller for Markdown CPT operations.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks\REST;

use AB\MarkdownToBricks\CPT\Markdown;
use AB\MarkdownToBricks\Service\HeadingCache;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit;

/**
 * Endpoints under /wp-json/abmtb/v1/.
 */
final class MarkdownController {

    public const NAMESPACE          = 'abmtb/v1';
    public const META_LEVEL_SETTINGS = '_abmtb_level_settings';
    public const MAX_UPLOAD_BYTES    = 2097152; // 2 MB

    /**
     * Allowed values for the per-level dateFormat field.
     *
     * '' = no date formatting (date token suppressed). Special sentinels
     * 'timestamp' and 'wp' are handled by the renderer; all other entries
     * are PHP date() format strings.
     */
    public const ALLOWED_DATE_FORMATS = [
        '',
        'timestamp',
        'wp',
        'Y-m-d',
        'F j, Y',
        'M j, Y',
        'm/d/Y',
        'd/m/Y',
    ];

    /**
     * Register routes.
     */
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * Register REST routes.
     */
    public static function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/markdown', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'upload_markdown'],
            'permission_callback' => [self::class, 'can_edit_post'],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/levels', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'save_levels'],
            'permission_callback' => [self::class, 'can_edit_post'],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/source', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'save_source'],
            'permission_callback' => [self::class, 'can_edit_post'],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Verify caller can edit the post in the URL.
     */
    public static function can_edit_post(WP_REST_Request $request): bool {
        $post_id = (int) $request->get_param('id');
        return $post_id > 0
            && get_post_type($post_id) === Markdown::POST_TYPE
            && current_user_can('edit_post', $post_id);
    }

    /**
     * Handle .md file upload: store in post_content, parse headings, return parsed state.
     */
    public static function upload_markdown(WP_REST_Request $request): WP_REST_Response {
        $post_id = (int) $request->get_param('id');
        if (!self::can_edit_post($request)) {
            return self::error('Forbidden', 403);
        }

        $files = $request->get_file_params();
        if (empty($files['file']) || !is_array($files['file'])) {
            return self::error(__('No file uploaded.', 'ab-markdown-to-bricks'), 400);
        }

        $file = $files['file'];
        $tmp  = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $err  = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($err !== UPLOAD_ERR_OK) {
            return self::error(__('Upload failed.', 'ab-markdown-to-bricks'), 400);
        }
        if (!$tmp || !is_uploaded_file($tmp)) {
            return self::error(__('Invalid upload.', 'ab-markdown-to-bricks'), 400);
        }
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return self::error(
                sprintf(
                    /* translators: %s: maximum size in MB */
                    __('File too large. Max size is %s MB.', 'ab-markdown-to-bricks'),
                    number_format_i18n(self::MAX_UPLOAD_BYTES / 1048576, 1)
                ),
                400
            );
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'md' && $ext !== 'markdown') {
            return self::error(__('Only .md files are accepted.', 'ab-markdown-to-bricks'), 400);
        }

        $content = file_get_contents($tmp);
        if ($content === false) {
            return self::error(__('Failed to read uploaded file.', 'ab-markdown-to-bricks'), 500);
        }

        $content = preg_replace("/^\xEF\xBB\xBF/", '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", (string) $content);

        $updated = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $content,
        ], true);
        if (is_wp_error($updated)) {
            return self::error($updated->get_error_message(), 500);
        }

        // save_post will have already invalidated the cache; rebuild now so
        // the response (and the next read) is a memory hit.
        $headings = HeadingCache::rebuild($post_id);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[ABMTB] upload: post=' . $post_id . ' len=' . strlen($content) . ' headings=' . count($headings));
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'markdown'      => $content,
                'headings'      => $headings,
                'levelSettings' => self::get_level_settings($post_id),
            ],
        ]);
    }

    /**
     * Save raw markdown text edited inline (no file upload), then re-parse
     * and return the refreshed state.
     */
    public static function save_source(WP_REST_Request $request): WP_REST_Response {
        $post_id = (int) $request->get_param('id');
        if (!self::can_edit_post($request)) {
            return self::error('Forbidden', 403);
        }

        $markdown = $request->get_param('markdown');
        if (!is_string($markdown)) {
            return self::error(__('Missing markdown payload.', 'ab-markdown-to-bricks'), 400);
        }

        if (strlen($markdown) > self::MAX_UPLOAD_BYTES) {
            return self::error(
                sprintf(
                    /* translators: %s: maximum size in MB */
                    __('Content too large. Max size is %s MB.', 'ab-markdown-to-bricks'),
                    number_format_i18n(self::MAX_UPLOAD_BYTES / 1048576, 1)
                ),
                400
            );
        }

        // Strip BOM, normalise line endings to LF.
        $markdown = preg_replace("/^\xEF\xBB\xBF/", '', $markdown);
        $markdown = str_replace(["\r\n", "\r"], "\n", (string) $markdown);

        $updated = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $markdown,
        ], true);
        if (is_wp_error($updated)) {
            return self::error($updated->get_error_message(), 500);
        }

        $headings = HeadingCache::rebuild($post_id);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[ABMTB] inline save: post=' . $post_id . ' len=' . strlen($markdown) . ' headings=' . count($headings));
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'markdown'      => $markdown,
                'headings'      => $headings,
                'levelSettings' => self::get_level_settings($post_id),
            ],
        ]);
    }

    /**
     * Save the per-level settings map (enabled/label/sort/filter per heading level).
     */
    public static function save_levels(WP_REST_Request $request): WP_REST_Response {
        $post_id = (int) $request->get_param('id');
        if (!self::can_edit_post($request)) {
            return self::error('Forbidden', 403);
        }

        $raw = $request->get_param('levels');
        if (!is_array($raw)) {
            return self::error(__('Invalid levels payload.', 'ab-markdown-to-bricks'), 400);
        }

        $clean = [];
        foreach ($raw as $level => $settings) {
            $level = (int) $level;
            if ($level < 1 || $level > 6) {
                continue;
            }
            if (!is_array($settings)) {
                continue;
            }
            $clean[(string) $level] = self::sanitise_level_settings($settings);
        }

        update_post_meta($post_id, self::META_LEVEL_SETTINGS, $clean);

        return rest_ensure_response([
            'success' => true,
            'data'    => ['levelSettings' => $clean],
        ]);
    }

    /**
     * Sanitise one level's settings object.
     *
     * @param array<string, mixed> $settings
     * @return array{enabled:bool,label:string,dateFormat:string}
     */
    private static function sanitise_level_settings(array $settings): array {
        $label = isset($settings['label'])
            ? sanitize_text_field((string) $settings['label'])
            : '';
        if (mb_strlen($label) > 200) {
            $label = mb_substr($label, 0, 200);
        }

        $date_format = isset($settings['dateFormat']) ? (string) $settings['dateFormat'] : '';
        if (!in_array($date_format, self::ALLOWED_DATE_FORMATS, true)) {
            $date_format = '';
        }

        return [
            'enabled'    => !empty($settings['enabled']),
            'label'      => $label,
            'dateFormat' => $date_format,
        ];
    }

    /**
     * Load the per-level settings map for a post.
     *
     * @return array<string, array{enabled:bool,label:string,dateFormat:string}>
     */
    public static function get_level_settings(int $post_id): array {
        $stored = get_post_meta($post_id, self::META_LEVEL_SETTINGS, true);
        if (!is_array($stored)) {
            return [];
        }

        $out = [];
        foreach ($stored as $k => $v) {
            $level = (int) $k;
            if ($level < 1 || $level > 6 || !is_array($v)) {
                continue;
            }
            $out[(string) $level] = self::sanitise_level_settings($v);
        }
        return $out;
    }

    /**
     * Build a uniform error response.
     */
    private static function error(string $message, int $status): WP_REST_Response {
        return new WP_REST_Response([
            'success' => false,
            'error'   => $message,
        ], $status);
    }
}
