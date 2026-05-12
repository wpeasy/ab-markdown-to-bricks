# Changelog

All notable changes to **Markdown to Bricks** are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/).

## [0.1.2] - 2026-05-13

### Added
- **Two-stage upload status.** The upload button now shows `Uploading…` while the file body is in flight, then flips to `Parsing…` the moment the server takes over (driven by `XMLHttpRequest.upload.load`). Replaces the single `Uploading…` state.

### Changed
- **Date strip removes wrapping brackets.** When a date is detected and stripped from a heading title, surrounding `(…)`, `[…]`, `{…}`, or `<…>` brackets are removed too — no more empty `()` shells.
  - `## Release (2024-01-15) hotfix` → `Release hotfix`
  - `## v0.1.99 [2024-01-15]` → `v0.1.99`
- `HeadingCache::VERSION` bumped to `2` so existing posts re-parse on next read and pick up the new strip behaviour. No user action needed.

## [0.1.1] - 2026-05-12

First substantial release. Initial scaffold (0.1.0) had only the empty CPT registration; this release ships the full upload → parse → label → output pipeline for both Bricks Builder and WordPress shortcodes.

### Added

#### CPT and admin UI
- Top-level **Markdown Parser** admin menu (moved out of the Bricks menu — the plugin now works standalone).
- Three-column edit screen built on Alpine.js: source on the left (2fr), heading levels in the middle, usage reference on the right.
- Drag-and-drop / file-picker **upload** with a 2 MB cap, `.md` / `.markdown` extension whitelist, and `is_uploaded_file()` enforcement.
- **Click-to-edit** source pane — `<pre>` swaps to a `<textarea>` of the same dimensions (no height jump), blur saves, Esc cancels.
- **Remove horizontal rules** button that strips `---` / `***` / `___` lines from the saved source and collapses leftover blank runs.
- Per-level rows with:
  - Enable / disable switch.
  - Editable **label** (defaults to the first heading text at that level).
  - **Date format** dropdown (shown only when at least one heading at the level contains a parseable date) with options: Unix timestamp, WordPress date format, ISO 8601, Long, Short, EU, US.
- Usage column with three exclusive accordions (only one open at a time): Bricks Dynamic Queries, WordPress Loop Shortcodes, WordPress Data Shortcodes.

#### Markdown parser
- `HeadingParser` extracts ATX-style headings (`#` through `######`), preserving document order. Skips fenced code blocks so `# Title` inside a code example isn't misclassified.
- `DateDetector` finds the first parseable date inside a heading (ISO 8601, long-month, short-month, slash, dot forms). Returns timestamp + matched substring so the title can be cleaned.
- Descriptions (content between a heading and the next) parsed to HTML through vendored Parsedown 1.7.4 with `setSafeMode(true)` + `setMarkupEscaped(true)`, then passed through `wp_kses_post()` as a backstop.

#### REST API (`/wp-json/abmtb/v1/`)
- `POST /posts/{id}/markdown` — file upload, returns parsed state.
- `POST /posts/{id}/source` — inline-edit text save, returns parsed state.
- `POST /posts/{id}/levels` — per-level settings save (enabled, label, dateFormat).
- All routes gated on `current_user_can('edit_post', $post_id)` plus a post-type re-check inside the handler.

#### Performance
- `HeadingCache` — two-layer cache. Persistent layer keyed by `md5(post_content)` in post meta; per-request static cache on top so multiple loops on one page share the parsed result without DB reads.

#### Bricks Builder integration
- Custom Query Loop **type "Markdown"** registered via `bricks/setup/control_options`. Acts as a single-iteration context provider that selects the source post.
- Per-label inner Query Loop types — one per unique label across all Markdown posts (e.g. **Markdown — changelog**, **Markdown — version**).
- Custom controls injected onto Section / Container / Block / Div under the Query control: Markdown post selector, Heading-label selector, Sort, Filter. Gated on `query.objectType` via nested-key `required` clauses.
- Dynamic tags `{md-title}`, `{md-description}`, `{md-date}` resolve from the active inner loop's heading. Title is date-stripped when a date format is selected.
- Nested loops are **document-scoped** — an inner loop sees only the headings under its parent loop's current iteration (e.g. status headings under the currently-rendered version).
- Sort options: document order, alphabetical (natural-order — `v0.1.99` sorts before `v0.1.100`), date ascending / descending. Tiebreak by natural-order title.
- Filter is a case-insensitive substring match on heading text.
- Visible error notice when an inner loop runs outside its outer Markdown loop. Restricted to logged-in editors / builder context.

#### WordPress shortcodes
- Per-label outer loop tags: `[markdown_<label> source="…"]…[/markdown_<label>]`. Supports `source`, `sort`, `filter`, `date_format` attributes.
- Three inner accessors: `[md_title]`, `[md_description]`, `[md_date]`. `md_` prefix avoids colliding with common global shortcode names.
- `source` resolves to a numeric post ID or post slug; inherits from the outer loop when omitted.
- Shortcode label registration cached in a transient, invalidated on `_abmtb_level_settings` writes and post deletion.

### Changed
- The `abmtb_markdown` CPT is now strictly non-public: `public`, `publicly_queryable`, `show_in_rest` all false, plus a runtime `pre_get_posts` filter that strips it from front-end main queries.
- Date sort uses the parsed Unix timestamp directly — the dateFormat dropdown only affects display, not sort behaviour.
- Alphabetical sort upgraded from `strcasecmp` to `strnatcasecmp` so version-style strings (`v0.1.99` < `v0.1.100`) sort the way humans expect.

### Removed
- `BricksCheck` class and the "Bricks is not active" admin notice — the plugin no longer requires Bricks to be the active theme (it falls back to shortcodes).

### Security
- Defence-in-depth markdown rendering: Parsedown safe mode + markup escaped + `wp_kses_post()` backstop. No `<script>` / event handlers / `javascript:` URLs survive.
- All REST handlers re-check `current_user_can('edit_post', $post_id)` inside the handler (not just `permission_callback`) — prevents the standard IDOR pattern.
- Sensitive `error_log()` calls gated behind `WP_DEBUG`; only metadata (length, count, post_id) is ever logged.

## [0.1.0] - 2026-05-12

### Added
- Initial plugin scaffold: PSR-4 autoloader, `Plugin` bootstrap, `abmtb_markdown` CPT registration.
- `BricksCheck` admin notice (removed in 0.1.1).
