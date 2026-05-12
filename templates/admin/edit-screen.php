<?php
/**
 * Markdown CPT edit screen markup.
 *
 * @package AB\MarkdownToBricks
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$abmtb_date_format_options = \AB\MarkdownToBricks\Admin\EditScreen::date_format_options();
?>
<div id="abmtb-editor" class="abmtb-editor" x-data="abmtbEditor()" x-cloak>

    <div class="abmtb-toolbar">
        <input
            type="file"
            x-ref="fileInput"
            accept=".md,.markdown,text/markdown,text/plain"
            class="abmtb-file-input"
            @change="uploadFile($event)"
        />
        <button
            type="button"
            class="button button-primary abmtb-toolbar__button"
            @click="$refs.fileInput.click()"
            :disabled="uploading"
        >
            <span x-show="uploading && uploadStatus === 'uploading'"><?php esc_html_e('Uploading…', 'ab-markdown-to-bricks'); ?></span>
            <span x-show="uploading && uploadStatus === 'parsing'"><?php esc_html_e('Parsing…', 'ab-markdown-to-bricks'); ?></span>
            <span x-show="!uploading && !markdown"><?php esc_html_e('Upload .md File', 'ab-markdown-to-bricks'); ?></span>
            <span x-show="!uploading && markdown"><?php esc_html_e('Replace .md File', 'ab-markdown-to-bricks'); ?></span>
        </button>
        <span class="abmtb-toolbar__hint" x-show="!markdown">
            <?php esc_html_e('Single .md file, max 2 MB.', 'ab-markdown-to-bricks'); ?>
        </span>
    </div>

    <div
        class="abmtb-toast"
        :class="toast ? 'abmtb-toast--' + toast.type : ''"
        x-show="toast"
        x-transition.opacity.duration.200ms
    >
        <span x-text="toast?.message"></span>
    </div>

    <div class="abmtb-grid">

        <section class="abmtb-column abmtb-column--source">
            <h2 class="abmtb-column__title">
                <?php esc_html_e('Markdown Source', 'ab-markdown-to-bricks'); ?>
                <span class="abmtb-source__status" x-show="savingSource">
                    — <?php esc_html_e('saving…', 'ab-markdown-to-bricks'); ?>
                </span>
                <span class="abmtb-source__status" x-show="!savingSource && editingSource">
                    — <?php esc_html_e('editing (Esc to cancel)', 'ab-markdown-to-bricks'); ?>
                </span>
                <span class="abmtb-source__hint" x-show="!editingSource && markdown">
                    <?php esc_html_e('Click to edit', 'ab-markdown-to-bricks'); ?>
                </span>
            </h2>

            <div class="abmtb-source__actions" x-show="markdown && !editingSource">
                <button
                    type="button"
                    class="button button-small abmtb-source__action"
                    @click="removeHorizontalRules()"
                    :disabled="savingSource || uploading"
                    title="<?php esc_attr_e('Strip lines like --- *** ___ from the source', 'ab-markdown-to-bricks'); ?>"
                >
                    <?php esc_html_e('Remove horizontal rules', 'ab-markdown-to-bricks'); ?>
                </button>
            </div>

            <pre
                class="abmtb-source"
                x-ref="sourcePre"
                x-show="markdown && !editingSource"
                x-text="markdown"
                @click="editSource()"
                title="<?php esc_attr_e('Click to edit', 'ab-markdown-to-bricks'); ?>"
            ></pre>

            <textarea
                class="abmtb-source-edit"
                x-show="editingSource"
                x-ref="sourceTextarea"
                x-model="markdown"
                @blur="saveSource()"
                @keydown.escape.prevent="cancelEdit()"
                spellcheck="false"
            ></textarea>

            <p class="abmtb-empty" x-show="!markdown && !editingSource">
                <?php esc_html_e('No file uploaded yet. Click "Upload .md File" above, or', 'ab-markdown-to-bricks'); ?>
                <a href="#" @click.prevent="editSource()">
                    <?php esc_html_e('start typing here', 'ab-markdown-to-bricks'); ?>
                </a>.
            </p>
        </section>

        <section class="abmtb-column abmtb-column--levels">
            <h2 class="abmtb-column__title"><?php esc_html_e('Heading Levels', 'ab-markdown-to-bricks'); ?></h2>

            <p class="abmtb-empty" x-show="levels.length === 0 && markdown">
                <?php esc_html_e('No headings found in this file.', 'ab-markdown-to-bricks'); ?>
            </p>
            <p class="abmtb-empty" x-show="!markdown">
                <?php esc_html_e('Upload a file to see heading levels.', 'ab-markdown-to-bricks'); ?>
            </p>

            <div class="abmtb-levels" x-show="levels.length > 0">
                <template x-for="level in levels" :key="level">
                    <div
                        class="abmtb-level"
                        :class="settingsFor(level).enabled ? '' : 'abmtb-level--disabled'"
                    >
                        <div class="abmtb-level__row">
                            <label class="abmtb-switch" :title="settingsFor(level).enabled ? 'Disable' : 'Enable'">
                                <input
                                    type="checkbox"
                                    x-model="levelSettings[String(level)].enabled"
                                    @change="saveLevels()"
                                />
                                <span class="abmtb-switch__slider"></span>
                            </label>
                            <span class="abmtb-level__tag" x-text="'H' + level"></span>
                            <input
                                type="text"
                                class="abmtb-level__input"
                                :placeholder="firstTextAt(level)"
                                :disabled="!settingsFor(level).enabled"
                                x-model="levelSettings[String(level)].label"
                                @input.debounce.500ms="saveLevels()"
                            />
                            <span class="abmtb-level__saving" x-show="savingLevels">…</span>
                        </div>
                        <div class="abmtb-level__tokens">
                            <span class="abmtb-level__tokens-label"><?php esc_html_e('Bricks loop', 'ab-markdown-to-bricks'); ?></span>
                            <code class="abmtb-level__token">
                                <?php esc_html_e('Type:', 'ab-markdown-to-bricks'); ?>
                                <span x-text="'Markdown — ' + loopLabelFor(level)"></span>
                            </code>
                        </div>
                        <div class="abmtb-level__tokens">
                            <span class="abmtb-level__tokens-label"><?php esc_html_e('Shortcode', 'ab-markdown-to-bricks'); ?></span>
                            <code class="abmtb-level__token" x-text="loopShortcodeFor(level) + '…' + '[/' + loopShortcodeFor(level).slice(1, -1) + ']'"></code>
                        </div>
                        <div class="abmtb-level__date" x-show="hasDateAtLevel(level)">
                            <label class="abmtb-level__date-label">
                                <span><?php esc_html_e('Date format:', 'ab-markdown-to-bricks'); ?></span>
                                <select
                                    class="abmtb-level__date-select"
                                    :disabled="!settingsFor(level).enabled"
                                    x-model="levelSettings[String(level)].dateFormat"
                                    @change="saveLevels()"
                                >
                                    <?php foreach ($abmtb_date_format_options as $abmtb_opt) : ?>
                                        <option value="<?php echo esc_attr($abmtb_opt['value']); ?>">
                                            <?php echo esc_html($abmtb_opt['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        <section class="abmtb-column abmtb-column--usage">
            <h2 class="abmtb-column__title"><?php esc_html_e('Usage', 'ab-markdown-to-bricks'); ?></h2>

            <p class="abmtb-empty" x-show="queries.length === 0">
                <?php esc_html_e('Enable a heading level on the left to see Bricks and shortcode usage here.', 'ab-markdown-to-bricks'); ?>
            </p>

            <div class="abmtb-accordions" x-show="queries.length > 0">

                <details class="abmtb-accordion" name="abmtb-accordion" open>
                    <summary class="abmtb-accordion__summary">
                        <?php esc_html_e('Bricks Dynamic Queries', 'ab-markdown-to-bricks'); ?>
                    </summary>
                    <div class="abmtb-accordion__body">
                        <p class="abmtb-accordion__intro">
                            <?php esc_html_e('Bricks uses a two-tier loop. The outer "Markdown" loop selects the source post once. Nested "Markdown — <label>" loops iterate the headings at each level — they inherit the post from the outer loop and each have their own sort + filter controls.', 'ab-markdown-to-bricks'); ?>
                        </p>

                        <h4 class="abmtb-accordion__heading"><?php esc_html_e('Setup in Bricks', 'ab-markdown-to-bricks'); ?></h4>
                        <ol class="abmtb-accordion__list">
                            <li><?php esc_html_e('Add a Section / Container / Block / Div, enable Query loop, set Type = "Markdown", then choose this post as the Markdown post.', 'ab-markdown-to-bricks'); ?></li>
                            <li><?php esc_html_e('Inside it, add another Container etc., enable Query loop, set Type = "Markdown — <label>" (one of the inner types below).', 'ab-markdown-to-bricks'); ?></li>
                            <li><?php esc_html_e('Optionally set Sort and Filter on the inner loop.', 'ab-markdown-to-bricks'); ?></li>
                            <li><?php esc_html_e('Inside the inner loop, drop the dynamic tags below into Bricks text fields.', 'ab-markdown-to-bricks'); ?></li>
                        </ol>

                        <h4 class="abmtb-accordion__heading"><?php esc_html_e('Inner types available on this post', 'ab-markdown-to-bricks'); ?></h4>
                        <ul class="abmtb-queries__list">
                            <template x-for="q in queries" :key="q.level">
                                <li class="abmtb-queries__item">
                                    <code class="abmtb-queries__id">
                                        <?php esc_html_e('Markdown —', 'ab-markdown-to-bricks'); ?>
                                        <span x-text="loopLabelFor(q.level)"></span>
                                    </code>
                                    <span class="abmtb-queries__level" x-text="'H' + q.level"></span>
                                </li>
                            </template>
                        </ul>

                        <h4 class="abmtb-accordion__heading"><?php esc_html_e('Dynamic tags (use inside an inner loop)', 'ab-markdown-to-bricks'); ?></h4>
                        <ul class="abmtb-accordion__list">
                            <li><code>{md-title}</code> — <?php esc_html_e('current heading text (date stripped when a format is selected)', 'ab-markdown-to-bricks'); ?></li>
                            <li><code>{md-description}</code> — <?php esc_html_e('current heading\'s parsed HTML body', 'ab-markdown-to-bricks'); ?></li>
                            <li><code>{md-date}</code> — <?php esc_html_e('current heading\'s formatted date (only when a date was detected and format selected)', 'ab-markdown-to-bricks'); ?></li>
                        </ul>
                    </div>
                </details>

                <details class="abmtb-accordion" name="abmtb-accordion">
                    <summary class="abmtb-accordion__summary">
                        <?php esc_html_e('WordPress Loop Shortcodes', 'ab-markdown-to-bricks'); ?>
                    </summary>
                    <div class="abmtb-accordion__body">

                        <p class="abmtb-accordion__intro">
                            <?php esc_html_e('Use these shortcodes anywhere — Gutenberg blocks, the classic editor, widgets, or template parts. Standard WordPress shortcode rules apply.', 'ab-markdown-to-bricks'); ?>
                        </p>

                        <h4 class="abmtb-accordion__heading"><?php esc_html_e('Loop syntax', 'ab-markdown-to-bricks'); ?></h4>
<pre class="abmtb-codeblock"><code>[markdown_&lt;label&gt; source="post-slug-or-id"]
    [md_title]          <?php esc_html_e('// current heading text', 'ab-markdown-to-bricks'); ?>

    [md_description]    <?php esc_html_e('// parsed HTML body', 'ab-markdown-to-bricks'); ?>

    [md_date]           <?php esc_html_e('// formatted date (when a format is selected)', 'ab-markdown-to-bricks'); ?>

[/markdown_&lt;label&gt;]</code></pre>

                        <h4 class="abmtb-accordion__heading"><?php esc_html_e('Inner accessors', 'ab-markdown-to-bricks'); ?></h4>
                        <ul class="abmtb-accordion__list">
                            <li><code>[md_title]</code> — <?php esc_html_e('current heading text (date stripped when a format is selected)', 'ab-markdown-to-bricks'); ?></li>
                            <li><code>[md_description]</code> — <?php esc_html_e('current heading\'s parsed HTML body', 'ab-markdown-to-bricks'); ?></li>
                            <li><code>[md_date]</code> — <?php esc_html_e('current heading\'s formatted date', 'ab-markdown-to-bricks'); ?></li>
                        </ul>

                        <h4 class="abmtb-accordion__heading"><?php esc_html_e('Examples for this document', 'ab-markdown-to-bricks'); ?></h4>
                        <template x-for="q in queries" :key="q.level">
                            <pre class="abmtb-codeblock"><code x-text="exampleFor(q.level)"></code></pre>
                        </template>

                        <h4 class="abmtb-accordion__heading"><?php esc_html_e('source attribute', 'ab-markdown-to-bricks'); ?></h4>
                        <ul class="abmtb-accordion__list">
                            <li><code>source="slug"</code> — <?php esc_html_e('matches the post slug', 'ab-markdown-to-bricks'); ?></li>
                            <li><code>source="123"</code> — <?php esc_html_e('matches the numeric post ID', 'ab-markdown-to-bricks'); ?></li>
                            <li><em><?php esc_html_e('omitted', 'ab-markdown-to-bricks'); ?></em> — <?php esc_html_e('inherits from the outer loop; falls back to the current post when it is a Markdown post', 'ab-markdown-to-bricks'); ?></li>
                        </ul>

                    </div>
                </details>

                <details class="abmtb-accordion" name="abmtb-accordion">
                    <summary class="abmtb-accordion__summary">
                        <?php esc_html_e('WordPress Data Shortcodes', 'ab-markdown-to-bricks'); ?>
                    </summary>
                    <div class="abmtb-accordion__body">

                        <p class="abmtb-accordion__intro">
                            <?php esc_html_e('The outer loop accepts attributes that shape its data: sort, filter, and an inline date format override. All optional.', 'ab-markdown-to-bricks'); ?>
                        </p>

                        <h4 class="abmtb-accordion__heading"><?php esc_html_e('sort', 'ab-markdown-to-bricks'); ?></h4>
                        <ul class="abmtb-accordion__list">
                            <li><em><?php esc_html_e('omitted', 'ab-markdown-to-bricks'); ?></em> — <?php esc_html_e('document order (the order headings appear in the .md file)', 'ab-markdown-to-bricks'); ?></li>
                            <li><code>sort="asc"</code> / <code>sort="desc"</code> — <?php esc_html_e('alphabetical by heading text', 'ab-markdown-to-bricks'); ?></li>
                            <li><code>sort="date"</code> / <code>sort="date_desc"</code> — <?php esc_html_e('chronological (headings without a detected date are sorted to the end)', 'ab-markdown-to-bricks'); ?></li>
                        </ul>
                        <pre class="abmtb-codeblock" x-show="sortExample"><code x-text="sortExample"></code></pre>

                        <h4 class="abmtb-accordion__heading"><?php esc_html_e('filter', 'ab-markdown-to-bricks'); ?></h4>
                        <p class="abmtb-accordion__intro">
                            <?php esc_html_e('Case-insensitive substring match against the heading text. Headings that don\'t contain the substring are skipped.', 'ab-markdown-to-bricks'); ?>
                        </p>
                        <pre class="abmtb-codeblock" x-show="filterExample"><code x-text="filterExample"></code></pre>

                        <h4 class="abmtb-accordion__heading"><?php esc_html_e('date_format', 'ab-markdown-to-bricks'); ?></h4>
                        <p class="abmtb-accordion__intro">
                            <?php esc_html_e('Overrides the saved per-level date format for this loop only. Accepts the same values as the editor dropdown:', 'ab-markdown-to-bricks'); ?>
                        </p>
                        <ul class="abmtb-accordion__list">
                            <li><code>timestamp</code> — <?php esc_html_e('Unix timestamp (seconds since 1970)', 'ab-markdown-to-bricks'); ?></li>
                            <li><code>wp</code> — <?php esc_html_e('uses the site\'s Settings → General → Date Format', 'ab-markdown-to-bricks'); ?></li>
                            <li><code>Y-m-d</code>, <code>F j, Y</code>, <code>M j, Y</code>, <code>d/m/Y</code>, <code>m/d/Y</code> — <?php esc_html_e('explicit PHP date format strings', 'ab-markdown-to-bricks'); ?></li>
                        </ul>
                        <pre class="abmtb-codeblock" x-show="dateFormatExample"><code x-text="dateFormatExample"></code></pre>
                        <p class="abmtb-accordion__hint" x-show="!dateFormatExample">
                            <?php esc_html_e('Upload a file with dated headings (e.g. "## Release 2024-01-15") to see a date_format example here.', 'ab-markdown-to-bricks'); ?>
                        </p>

                    </div>
                </details>

            </div>
        </section>

    </div>
</div>
