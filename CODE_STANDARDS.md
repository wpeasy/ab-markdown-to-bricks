# Code Standards

Standards for the Markdown to Bricks plugin. See `CLAUDE.md` for plugin properties and prefixes.

---

## General Principles

1. **Consistency over preference** — match existing patterns in this codebase.
2. **Explicit over implicit** — readable code over clever shortcuts.
3. **DRY but not premature** — extract only after 3+ repetitions.
4. **Comments explain "why"**, not "what".
5. **Fail fast** — validate early, return `WP_Error` / 4xx with a clear message.

---

## File Organization

```
ab-markdown-to-bricks/
├── ab-markdown-to-bricks.php   # Main plugin file
├── uninstall.php               # Cleanup on uninstall
├── composer.json               # PSR-4 autoloader
├── CLAUDE.md                   # Plugin properties + flow (source of truth)
├── CODE_STANDARDS.md
├── BRICKS_NOTES.md
├── WP_CLI_LOCAL.md
│
├── src/                        # PHP — namespace AB\MarkdownToBricks
│   ├── Plugin.php              # Bootstrap
│   ├── CPT/                    # Custom post type registration
│   ├── Parser/                 # Markdown → heading tree
│   ├── Bricks/                 # Dynamic tags + query loop registration
│   ├── REST/                   # REST controllers
│   └── Admin/                  # Admin menus, meta boxes, assets
│
├── assets/
│   ├── js/
│   │   ├── alpine.min.js       # Alpine.js (vendored, not built)
│   │   └── admin.js            # Plugin's admin script
│   └── css/
│       └── admin.css
│
├── templates/                  # PHP partials for admin views
└── languages/                  # .pot / .po / .mo
```

---

## Naming Conventions

### Files

| Type | Convention | Example |
|---|---|---|
| PHP classes | PascalCase | `MarkdownParser.php` |
| PHP traits | PascalCase + `Trait` | `SingletonTrait.php` |
| JS | kebab-case | `admin.js`, `heading-tree.js` |
| CSS | kebab-case | `admin.css` |

### Code

| Language | Variables | Functions | Classes | Constants |
|---|---|---|---|---|
| PHP | `$snake_case` | `snake_case()` | `PascalCase` | `UPPER_SNAKE` |
| JS | `camelCase` | `camelCase()` | `PascalCase` | `UPPER_SNAKE` |
| CSS | `--kebab-case` | n/a | `.kebab-case` | n/a |

### Prefixes (from CLAUDE.md)

- PHP constants: `ABMTB_PLUGIN_PATH`, `ABMTB_VERSION`
- REST routes: `/abmtb/v1/...`
- Options: `abmtb_settings`
- Post meta: `_abmtb_*` (underscored = hidden from custom-fields UI)
- Transients: `abmtb_cache_*`
- Hooks: `abmtb_action_name`, `abmtb_filter_name`
- CPT: `abmtb_markdown`

---

## PHP Standards

### File header

```php
<?php
/**
 * Brief description.
 *
 * @package AB\MarkdownToBricks
 * @since   1.0.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks;

defined('ABSPATH') || exit;
```

### Rules

1. **ABSPATH check** on every PHP file.
2. **`declare(strict_types=1);`** on every PHP file.
3. **Type hints** on every parameter and return.
4. **PSR-4 autoload** via Composer.
5. **`final` classes** for hook-integration classes (no inheritance needed).
6. **Static hook-integration pattern** — `init()` registers hooks, handlers are static methods. Use instance classes only when there's real state.

```php
final class MarkdownCPT {

    public static function init(): void {
        add_action('init', [self::class, 'register']);
    }

    public static function register(): void {
        register_post_type('abmtb_markdown', [
            'label'        => __('Markdown', 'ab-markdown-to-bricks'),
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'supports'     => ['title', 'custom-fields'],
        ]);
    }
}
```

### DocBlocks

Required on classes (`@package`, `@since`), all public/protected methods, and any property with a non-obvious type.

---

## JavaScript (Alpine.js)

### Rules

1. **No build step.** Plain ES2020 JS, loaded via `wp_enqueue_script`.
2. **No jQuery.**
3. **Alpine for reactivity.** Don't reach for vanilla `addEventListener` loops when an `x-on:click` does the job.
4. **Module pattern via `window.ABMTB`** — one namespace, no global pollution.

### Entry point

```javascript
// assets/js/admin.js
(function () {
    'use strict';

    window.ABMTB = window.ABMTB || {};
    window.ABMTB.components = window.ABMTB.components || {};

    window.ABMTB.components.headingMapper = function () {
        return {
            headings: [],
            labels: {},
            loading: false,

            async init() {
                this.headings = await this.fetchHeadings();
            },

            async fetchHeadings() {
                const res = await fetch(`${ABMTB.apiUrl}/posts/${ABMTB.postId}/headings`, {
                    headers: { 'X-WP-Nonce': ABMTB.nonce },
                });
                if (!res.ok) throw new Error('Fetch failed');
                return res.json();
            },

            async save() {
                this.loading = true;
                try {
                    await fetch(`${ABMTB.apiUrl}/posts/${ABMTB.postId}/labels`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': ABMTB.nonce,
                        },
                        body: JSON.stringify(this.labels),
                    });
                } finally {
                    this.loading = false;
                }
            },
        };
    };
})();
```

### Server data injection

```php
add_action('admin_head', function () {
    if (get_current_screen()?->post_type !== 'abmtb_markdown') return;

    $data = [
        'apiUrl' => esc_url_raw(rest_url('abmtb/v1')),
        'nonce'  => wp_create_nonce('wp_rest'),
        'postId' => (int) get_the_ID(),
    ];
    echo '<script>window.ABMTB = ' . wp_json_encode($data) . ';</script>';
});
```

### Alpine safety rules

- **`x-text`, never `x-html`**, for user-supplied content (heading titles, label names).
- **Debounce inputs** with `Alpine.debounce()` for autosave.
- **Surface errors** in the UI, don't just `console.error`. Use a toast / inline message.

---

## CSS Standards

### BEM naming

```css
.abmtb-mapper { }                /* Block */
.abmtb-mapper__row { }           /* Element */
.abmtb-mapper__row--active { }   /* Modifier */
```

### Rules

1. **Always scope selectors.** Never style bare `button`, `input`, `div`. The admin page is shared with WordPress and other plugins.
2. **No `!important`.** Increase specificity instead.
3. **No hardcoded colors** in plugin styles when WordPress admin CSS variables work. WP admin already supports dark/light via `color-scheme`.
4. **Spacing via flex/gap**, not margins. Use `display: flex; flex-direction: column; gap: ...` on parents.

```css
.abmtb-section {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.abmtb-section h3 {
    /* No margin — parent gap handles spacing */
}
```

---

## Security

### Input sanitization

```php
$title   = sanitize_text_field($_POST['title'] ?? '');
$content = wp_kses_post($_POST['content'] ?? '');
$slug    = sanitize_key($_POST['slug'] ?? '');
$int     = absint($_POST['count'] ?? 0);
```

### Output escaping

```php
echo esc_html($heading_text);
echo esc_attr($input_value);
echo esc_url($file_url);
```

### Nonce verification

```php
// REST — register with permission_callback; WP verifies X-WP-Nonce automatically.
register_rest_route('abmtb/v1', '/posts/(?P<id>\d+)/labels', [
    'methods'             => WP_REST_Server::CREATABLE,
    'callback'            => [LabelsController::class, 'save'],
    'permission_callback' => [LabelsController::class, 'can_edit'],
    'args'                => LabelsController::save_args(),
]);

// Admin form
if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'abmtb_action')) {
    wp_die(esc_html__('Security check failed', 'ab-markdown-to-bricks'));
}
```

### Capability + ownership (IDOR prevention)

`permission_callback` runs before body params are reliable. For any handler that operates on a specific post, re-check ownership inside the handler:

```php
public static function save(WP_REST_Request $req): WP_REST_Response {
    $post_id = (int) $req->get_param('id');
    if (!$post_id || get_post_type($post_id) !== 'abmtb_markdown'
        || !current_user_can('edit_post', $post_id)) {
        return new WP_REST_Response(
            ['success' => false, 'error' => 'Forbidden'],
            403
        );
    }
    // ... safe to write meta on $post_id now
}
```

### File uploads

When the user uploads a `.md` file:

1. **Verify it's an actual upload** — `is_uploaded_file($_FILES['md']['tmp_name'])`.
2. **Cap size** — reject anything over `ABMTB_MAX_UPLOAD_BYTES` (e.g. 2 MB) BEFORE reading.
3. **Check MIME + extension** — `wp_check_filetype_and_ext($tmp, $name, ['md' => 'text/markdown'])`.
4. **Read with `file_get_contents()`** only after the checks pass.

```php
public static function process_markdown(string $tmp_path, string $original_name): array {
    if (!is_uploaded_file($tmp_path)) {
        return ['ok' => false, 'error' => 'Not an uploaded file'];
    }
    if (filesize($tmp_path) > ABMTB_MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'error' => 'File too large'];
    }
    $type = wp_check_filetype_and_ext($tmp_path, $original_name, ['md' => 'text/markdown']);
    if (empty($type['ext'])) {
        return ['ok' => false, 'error' => 'Only .md files are accepted'];
    }
    $content = file_get_contents($tmp_path);
    // ... parse
}
```

### HTML in JS

If you must build DOM from a template literal that includes user data, escape it. Prefer Alpine's `x-text`:

```html
<!-- ✓ Correct — Alpine escapes for you -->
<span x-text="heading.title"></span>

<!-- ✗ Wrong — x-html allows script injection from MD content -->
<span x-html="heading.title"></span>
```

If you're constructing DOM manually:

```javascript
// ✓ Correct
const span = document.createElement('span');
span.textContent = heading.title;
container.append(span);

// ✗ Wrong
container.innerHTML = `<span>${heading.title}</span>`;
```

### Sensitive logging

`error_log()` of user content (raw MD bodies, parsed heading text) must be gated behind `WP_DEBUG`. Metadata can always log.

```php
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('[ABMTB] parse: ' . substr($content, 0, 200));
} else {
    error_log('[ABMTB] parse: post=' . $post_id . ' len=' . strlen($content));
}
```

### Document parser hardening

We parse Markdown — not XML — so the XXE class of bugs doesn't apply directly. But if a future feature accepts `.docx` / `.pdf` / HTML:

- Pin minimum versions of any parser library in `composer.json` so CVE patches are forced.
- Set `libxml_use_internal_errors(true)` and `LIBXML_NONET` explicitly before any XML parsing.
- Cap input size before handing to the parser.

---

## REST API

### Registration

```php
register_rest_route('abmtb/v1', '/posts/(?P<id>\d+)/headings', [
    'methods'             => WP_REST_Server::READABLE,
    'callback'            => [HeadingsController::class, 'get'],
    'permission_callback' => [HeadingsController::class, 'can_read'],
    'args'                => [
        'id' => ['type' => 'integer', 'required' => true],
    ],
]);
```

### Response format

```php
// Success
return rest_ensure_response([
    'success' => true,
    'data'    => $headings,
]);

// Error
return new WP_REST_Response([
    'success' => false,
    'error'   => __('Post not found', 'ab-markdown-to-bricks'),
], 404);
```

### Client request

```javascript
async function saveLabels(labels) {
    const res = await fetch(`${ABMTB.apiUrl}/posts/${ABMTB.postId}/labels`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': ABMTB.nonce,
        },
        body: JSON.stringify(labels),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.error || 'Request failed');
    }
    return res.json();
}
```

---

## Database

This plugin stores everything in standard WP tables — no custom tables.

- **MD content** → post content of the `abmtb_markdown` CPT.
- **Parsed heading tree** → post meta `_abmtb_headings_tree` (JSON-encoded).
- **Level → label mapping** → post meta `_abmtb_level_labels` (associative array, JSON-encoded).
- **Plugin settings** → option `abmtb_settings`.

If a custom table becomes necessary later, follow the WP convention: `{$wpdb->prefix}abmtb_tablename`, use `dbDelta`, track schema version in `option('abmtb_db_version')`.

---

## Error Handling

### PHP

```php
// Expected failures → WP_Error or WP_REST_Response with status
if (empty($title)) {
    return new WP_Error('validation_error', __('Title is required', 'ab-markdown-to-bricks'));
}

// Unexpected failures → throw, let WP_DEBUG surface it
if (!file_exists($config_path)) {
    throw new \RuntimeException('Config file missing: ' . $config_path);
}
```

### JavaScript

```javascript
try {
    await saveLabels(this.labels);
    this.toast = { type: 'success', message: 'Saved' };
} catch (err) {
    this.toast = { type: 'error', message: err.message };
}
```

Always show the user the failure — never let it die silently in the console.

---

## Accessibility

1. Every interactive element keyboard-reachable.
2. Icon-only buttons get `aria-label`.
3. Form labels associate with inputs via `for` / `id`.
4. Color contrast: WCAG AA (4.5:1 for body text).
5. Don't `outline: none` on focus without replacing it with something visible.

### Clickable switch labels

If you build a custom toggle, the text label next to it must also toggle the state:

```html
<label class="abmtb-switch">
    <input type="checkbox" x-model="enabled">
    <span>Enable parsing on save</span>
</label>
```

`<label>` wrapping the input handles this for free — only build a `role="button"` span when the markup can't be a `<label>`.

---

## Git Conventions

```
type(scope): short description

Longer description if needed.
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `chore`.

Branches: `feature/short-description`, `fix/issue-description`.

---

## Code Review Checklist

- [ ] Naming follows the prefixes in CLAUDE.md
- [ ] All PHP files have `defined('ABSPATH') || exit;` and `declare(strict_types=1);`
- [ ] Output escaped (`esc_html`, `esc_attr`, `esc_url`)
- [ ] REST endpoints check capability + post ownership
- [ ] Nonces verified on writes
- [ ] User MD content not logged unless `WP_DEBUG`
- [ ] No bare element selectors in CSS
- [ ] No `!important` in CSS
- [ ] Translatable strings wrapped in `__()` / `esc_html__()` with `ab-markdown-to-bricks` textdomain
- [ ] No console errors / warnings on the admin page
