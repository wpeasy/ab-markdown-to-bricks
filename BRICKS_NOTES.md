# Bricks Builder Integration Notes

This plugin does **not** inject UI into the Bricks Builder editor. It integrates with Bricks purely server-side, by:

1. Registering **Dynamic Tags** (so users can write `{abmtb_section_title}` in any Bricks element).
2. Registering **custom Query Loop types** (so users can loop over parsed heading data inside Bricks).

Everything below is about those two integrations. State-of-the-builder, Vue access, iframe communication etc. are not in scope.

---

## Dynamic Tags

Bricks lets plugins register custom dynamic tags via the `bricks/dynamic_tags_list` filter (for the picker UI) and `bricks/dynamic_data/render_tag` filter (for the actual rendering).

### Register a tag in the picker

```php
add_filter('bricks/dynamic_tags_list', function (array $tags): array {
    $tags[] = [
        'name'  => '{abmtb_section_title}',
        'label' => __('MTB: Section Title', 'ab-markdown-to-bricks'),
        'group' => __('Markdown to Bricks', 'ab-markdown-to-bricks'),
    ];
    return $tags;
});
```

### Resolve a tag at render time

```php
add_filter('bricks/dynamic_data/render_tag', function ($tag, $post, $context) {
    // $tag is the full tag including braces, e.g. '{abmtb_section_title}'
    if (strpos($tag, '{abmtb_') !== 0) {
        return $tag;
    }

    $value = \AB\MarkdownToBricks\Bricks\Tags::resolve($tag, $post);

    // $context is 'text' | 'link' | 'image' — escape accordingly when needed.
    // Bricks generally handles escaping for 'text' context, but if you return
    // HTML you must ensure it's safe.
    return is_string($value) ? $value : $tag;
}, 10, 3);
```

### Resolve tags inside larger content (text fields)

```php
add_filter('bricks/dynamic_data/render_content', function ($content, $post, $context) {
    return \AB\MarkdownToBricks\Bricks\Tags::replace_all_in($content, $post);
}, 10, 3);
```

### Naming convention for this plugin's tags

`{abmtb_<label_key>_<field>}` — e.g. for a label "section" with field "title": `{abmtb_section_title}`. The `_title` / `_body` / `_id` suffix is fixed by the plugin; the `<label_key>` part comes from the user-defined level → label mapping.

---

## Query Loops

Bricks supports custom query loop types via `bricks/setup/control_options` (registers the option in the Loop dropdown) and `bricks/query/run` (provides the actual array of objects to loop over).

### Register a loop type

```php
add_filter('bricks/setup/control_options', function (array $options): array {
    $options['queryTypes']['abmtb_headings'] = __('Markdown Headings', 'ab-markdown-to-bricks');
    return $options;
});
```

### Provide the items at runtime

```php
add_filter('bricks/query/run', function ($results, $query_obj) {
    if (($query_obj->settings['queryType'] ?? '') !== 'abmtb_headings') {
        return $results;
    }
    $post_id = (int) ($query_obj->settings['abmtb_source_post'] ?? 0);
    $level   = (string) ($query_obj->settings['abmtb_level'] ?? '');

    return \AB\MarkdownToBricks\Bricks\Loops::get_headings($post_id, $level);
}, 10, 2);
```

### Per-loop-iteration context

Inside a loop, Bricks exposes the current item to dynamic tags. To make `{abmtb_section_title}` resolve to the *current loop iteration* instead of the active post, expose a per-iteration context object — typically by hooking `bricks/query/loop_object` or by checking `Bricks\Query::get_loop_object()` inside your tag resolver.

```php
public static function resolve(string $tag, $post) {
    $loop_object = \Bricks\Query::get_loop_object('abmtb_headings');
    if ($loop_object) {
        // Inside a loop — resolve against the current iteration.
        return $loop_object[ $tag_to_field_map[$tag] ] ?? '';
    }
    // Outside a loop — fall back to the post-level data.
    return self::resolve_for_post($tag, $post);
}
```

---

## Data shape

The parsed tree we persist as `_abmtb_headings_tree` post meta:

```php
[
    [
        'id'       => 'h-1a2b',           // stable per heading, derived from position+text hash
        'level'    => 1,                   // 1–6
        'label'    => 'section',           // mapped from level via _abmtb_level_labels
        'title'    => 'Getting Started',   // raw heading text (NOT html — sanitize on render)
        'slug'     => 'getting-started',   // sanitize_title()
        'body'     => '...',               // markdown content between this heading and the next sibling
        'children' => [ /* recursive */ ],
    ],
    // ...
]
```

`_abmtb_level_labels` is a flat map:

```php
[
    '1' => 'section',
    '2' => 'topic',
    '3' => 'item',
]
```

---

## Render-time escaping

Bricks treats dynamic tag output as safe HTML by default. **We can't trust that.** The `title` / `body` fields come from user-uploaded Markdown — they may contain `<script>`, `<iframe>`, or `onerror` attributes.

- For `title` and other inline-text fields: return `esc_html($value)` from the tag resolver.
- For `body` (which is parsed Markdown intended to render as HTML): pass through `wp_kses_post()` before returning. Never return raw user HTML.
- Document this in the tag's tooltip / label so a designer doesn't expect raw HTML to survive.

---

## Useful Bricks references

- `bricks/dynamic_tags_list` — register tag entries in the picker
- `bricks/dynamic_data/render_tag` — resolve a single tag
- `bricks/dynamic_data/render_content` — resolve tags inside larger text
- `bricks/setup/control_options` — add a custom value to a control's options (used for `queryTypes`)
- `bricks/query/run` — provide items for a custom query loop type
- `\Bricks\Query::get_loop_object($key)` — the current iteration's object inside a loop

When in doubt, the Bricks source is at `wp-content/themes/bricks/includes/` — grep there before guessing filter names. Versions shift, so confirm against the installed Bricks version.

---

## What we explicitly do NOT touch

- Vue state inside the Bricks Builder editor.
- The Bricks preview iframe.
- Element settings / `_cssCustom` / global classes.
- `window.bricksData`.
- Any JS / CSS injected into the builder.

If we ever need to read or write builder state from JS, that's a different problem — and the conventions in this file don't cover it.
