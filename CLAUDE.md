# CLAUDE.md

## Project Properties

- **Plugin Name:** Markdown to Bricks
- **Description:** Upload a Markdown file to a "Markdown" CPT, parse its headings, map heading levels to Labels, and expose the parsed data to Bricks Builder via Dynamic Tokens and Query Loops.
- **Minimum WordPress:** 6.5
- **Minimum PHP:** 8.0
- **PHP Namespace:** `AB\MarkdownToBricks`
- **Constants Prefix:** `ABMTB_`
- **Textdomain:** `ab-markdown-to-bricks`
- **REST API Namespace:** `abmtb/v1`
- **CPT Slug:** `abmtb_markdown`
- **JS Global:** `ABMTB` (window.ABMTB)
- **CSS Prefix:** `.abmtb-`

## Stack

- **Admin UI:** WordPress admin pages + CPT edit screens. No code lives in the Bricks Builder editor itself.
- **Reactivity:** Alpine.js (loaded from `assets/js/alpine.min.js`). No Vite, no build step, no TypeScript.
- **PHP:** PSR-4 autoloaded classes under `src/` with the `AB\MarkdownToBricks` namespace.
- **Server I/O:** REST API at `/wp-json/abmtb/v1/...` with `X-WP-Nonce` auth.
- **Bricks integration:** PHP filters only — register dynamic tags + custom query loop types. The plugin does **not** inject JS or CSS into the Bricks Builder editor.

## What the plugin does (flow)

1. User creates a post in the **Markdown** CPT.
2. User uploads / pastes a `.md` file. It's stored as post content (or attached as a media file — see `BRICKS_NOTES.md` for the decision).
3. On save, the plugin parses headings (H1–H6) into a tree.
4. User maps heading levels to **Labels** (e.g. H1 → "Section", H2 → "Topic", H3 → "Item"). Labels are persisted in post meta.
5. Labels register as **Bricks Dynamic Tokens** so users can drop `{abmtb_section_title}` etc. into Bricks elements.
6. Each Label also registers as a **Bricks Query Loop type** so users can iterate over the parsed tree at the matching level.

## Required Reading

| File | Purpose |
|---|---|
| `CODE_STANDARDS.md` | Naming, security, PHP/Alpine/CSS standards |
| `BRICKS_NOTES.md` | Bricks Dynamic Tags + Query Loop integration |
| `WP_CLI_LOCAL.md` | Running WP-CLI against this Local site |

## Security — non-negotiable

These are the patterns that matter for this plugin. Full details in `CODE_STANDARDS.md`.

1. **Escape every dynamic string in HTML.** Heading text from the uploaded MD is user-supplied — `esc_html()` in PHP, `x-text` (not `x-html`) in Alpine. Never interpolate parsed text into `innerHTML` or template literals.
2. **Validate uploaded files.** Use `is_uploaded_file()` on the temp path, cap file size before parsing, restrict to `.md` / `text/markdown` via `wp_check_filetype_and_ext`.
3. **REST IDOR.** Every handler that takes a `post_id` must re-check `current_user_can('edit_post', $post_id)` inside the handler, not just a generic `edit_posts` in `permission_callback`.
4. **Nonces.** All REST writes verify `X-WP-Nonce`. Admin form posts verify `wp_verify_nonce()`.
5. **No `error_log()` of user MD content** unless `WP_DEBUG` is true. Log metadata (`post_id`, `byte_len`, `heading_count`) freely.

## Alpine.js conventions

- Mount Alpine from a single entry script: `assets/js/admin.js`. Pass server data via `window.ABMTB = {...}` printed in `admin_head`.
- Use `x-data="abmtbMarkdownEditor()"` style: define components as functions on `window.ABMTB.components.{name}` so the HTML stays declarative.
- Bind text with `x-text`, never `x-html`, when the source is user data.
- Save state via `fetch('/wp-json/abmtb/v1/...', { headers: { 'X-WP-Nonce': ABMTB.nonce } })`. Use `Alpine.debounce(fn, 500)` for autosave inputs.

## Deviation reporting

When you deviate from these conventions for a technical reason (e.g., using a small Vue island instead of Alpine, or skipping REST in favor of `admin-post.php`), say so before implementing:

> **Note:** [What I'm deviating from] — [Why] — [Alternative if you prefer]

## Description

The **Markdown to Bricks** plugin turns a Markdown file into structured data that Bricks Builder can consume natively. Editors work in plain Markdown; designers work in Bricks. The plugin is the bridge — heading levels become labels, labels become Bricks Dynamic Tokens and Query Loops.
