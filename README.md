# Markdown to Bricks

WordPress plugin that turns a Markdown file into structured data Bricks Builder can consume natively.

Upload a `.md` file to a **Markdown** post type, map heading levels (H1–H6) to Labels, and the plugin exposes the parsed data to Bricks as:

- **Dynamic Tokens** — drop `{abmtb_<label>_title}` etc. into any Bricks element.
- **Query Loops** — iterate over parsed headings at any level using a standard Bricks loop.

Editors work in plain Markdown; designers work in Bricks. The plugin is the bridge.

## Status

Early development. Currently registers the `abmtb_markdown` custom post type under the Bricks admin menu. Markdown parsing, label mapping, dynamic tokens, and query loops are not yet implemented.

## Requirements

- WordPress 6.5+
- PHP 8.0+
- [Bricks Builder](https://bricksbuilder.io/) (for dynamic tokens + query loops)

## Installation

1. Clone or download into `wp-content/plugins/ab-markdown-to-bricks`.
2. Activate **Markdown to Bricks** in **Plugins**.
3. Find the new **Markdown** entry under the Bricks admin menu.

## Project documentation

- `CLAUDE.md` — plugin properties, flow, security non-negotiables
- `CODE_STANDARDS.md` — PHP, Alpine.js, CSS, REST, security standards
- `BRICKS_NOTES.md` — Bricks Dynamic Tag + Query Loop integration notes
- `WP_CLI_LOCAL.md` — running WP-CLI against Local by Flywheel on Windows

## License

GPL-2.0-or-later
