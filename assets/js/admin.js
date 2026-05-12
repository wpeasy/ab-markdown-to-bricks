/**
 * Markdown to Bricks — admin edit screen component.
 *
 * Mounted via x-data="abmtbEditor()" on the CPT edit screen. Reads bootstrap
 * data from window.ABMTB.data (printed inline by EditScreen::inline_data()).
 */
(function () {
    'use strict';

    window.ABMTB = window.ABMTB || {};
    window.ABMTB.components = window.ABMTB.components || {};

    /**
     * Normalise a label for use in a Bricks dynamic token or query id.
     * Lowercase, non-alphanumerics collapsed to a single hyphen, trimmed.
     */
    function normalise(label) {
        return String(label || '')
            .toLowerCase()
            .normalize('NFKD')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    /**
     * Normalise a label for use in a WordPress shortcode name.
     * Same as `normalise()` but with underscores — WP shortcode parser is
     * happier with `_` than `-` in tag names.
     */
    function normaliseUnderscore(label) {
        return String(label || '')
            .toLowerCase()
            .normalize('NFKD')
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    /**
     * Default settings shape for a single heading level.
     */
    function defaultLevelSettings(defaultLabel) {
        return {
            enabled: true,
            label: defaultLabel || '',
            dateFormat: '',
        };
    }

    window.ABMTB.components.abmtbEditor = function () {
        return {
            markdown: '',
            headings: [],
            levelSettings: {},
            uploading: false,
            savingLevels: false,
            editingSource: false,
            savingSource: false,
            toast: null,
            _toastTimer: null,
            _originalMarkdown: '',

            init() {
                const boot = (window.ABMTB && window.ABMTB.data) || {};
                this.markdown = boot.markdown || '';
                this.headings = Array.isArray(boot.headings) ? boot.headings : [];
                this.hydrateLevelSettings(boot.levelSettings || {});
            },

            /**
             * Merge persisted level settings with first-heading defaults so
             * every level present in the document has an entry to edit. Any
             * fields missing from the saved record get filled with defaults.
             */
            hydrateLevelSettings(saved) {
                const next = {};
                const seen = new Set();
                for (const h of this.headings) {
                    if (seen.has(h.level)) continue;
                    seen.add(h.level);
                    const key = String(h.level);
                    const fallback = defaultLevelSettings(h.text);
                    const persisted = (saved && saved[key]) || {};
                    next[key] = {
                        enabled:    typeof persisted.enabled === 'boolean' ? persisted.enabled : fallback.enabled,
                        label:      typeof persisted.label === 'string' && persisted.label !== '' ? persisted.label : fallback.label,
                        dateFormat: typeof persisted.dateFormat === 'string' ? persisted.dateFormat : '',
                    };
                }
                this.levelSettings = next;
            },

            get levels() {
                const set = new Set();
                for (const h of this.headings) set.add(h.level);
                return Array.from(set).sort((a, b) => a - b);
            },

            /**
             * Bricks queries are generated only for ENABLED levels that
             * actually appear in the parsed document.
             */
            get queries() {
                return this.levels
                    .filter((level) => this.settingsFor(level).enabled)
                    .map((level) => {
                        const s = this.settingsFor(level);
                        return {
                            level,
                            id: 'markdown:' + normalise(s.label || this.firstTextAt(level)),
                        };
                    });
            },

            settingsFor(level) {
                return this.levelSettings[String(level)] || defaultLevelSettings('');
            },

            firstTextAt(level) {
                const match = this.headings.find((h) => h.level === level);
                return match ? match.text : '';
            },

            /**
             * Outer loop shortcode tag for this level (e.g. "[markdown_topic]").
             * The bare inner accessors are universal — see exampleFor().
             */
            loopShortcodeFor(level) {
                const s = this.settingsFor(level);
                return '[markdown_' + normaliseUnderscore(s.label || this.firstTextAt(level)) + ']';
            },

            /**
             * Loop tag name used by the Bricks Query Loop "Markdown" type to
             * identify which heading level to iterate.
             */
            loopLabelFor(level) {
                const s = this.settingsFor(level);
                return normaliseUnderscore(s.label || this.firstTextAt(level));
            },

            /**
             * Build a worked example of the loop shortcode for one level,
             * using this post's slug as the source and including the
             * `[md_date]` line only when a date format is selected.
             */
            exampleFor(level) {
                const label = this.loopLabelFor(level);
                const s = this.settingsFor(level);
                const slug = (window.ABMTB && window.ABMTB.postSlug) || 'post-slug';
                const dateLine = (this.hasDateAtLevel(level) && s.dateFormat !== '')
                    ? '\n  [md_date]'
                    : '';
                return (
                    '[markdown_' + label + ' source="' + slug + '"]\n' +
                    '  <h' + level + '>[md_title]</h' + level + '>\n' +
                    '  [md_description]' + dateLine + '\n' +
                    '[/markdown_' + label + ']'
                );
            },

            /**
             * First level present that has detected dates (or null).
             * Used by the "Data Shortcodes" accordion to build a date example.
             */
            get firstDateLevel() {
                for (const lvl of this.levels) {
                    if (this.hasDateAtLevel(lvl)) {
                        return lvl;
                    }
                }
                return null;
            },

            _loopWith(level, extraAttrs) {
                const label = this.loopLabelFor(level);
                const slug = (window.ABMTB && window.ABMTB.postSlug) || 'post-slug';
                return (
                    '[markdown_' + label + ' source="' + slug + '" ' + extraAttrs + ']\n' +
                    '  <li>[md_title]</li>\n' +
                    '[/markdown_' + label + ']'
                );
            },

            get sortExample() {
                if (this.queries.length === 0) return '';
                return this._loopWith(this.queries[0].level, 'sort="desc"');
            },

            get filterExample() {
                if (this.queries.length === 0) return '';
                return this._loopWith(this.queries[0].level, 'filter="hotfix"');
            },

            get dateFormatExample() {
                const level = this.firstDateLevel;
                if (level === null) return '';
                const label = this.loopLabelFor(level);
                const slug = (window.ABMTB && window.ABMTB.postSlug) || 'post-slug';
                return (
                    '[markdown_' + label + ' source="' + slug + '" date_format="Y-m-d"]\n' +
                    '  <li>[md_title] — [md_date]</li>\n' +
                    '[/markdown_' + label + ']'
                );
            },

            hasDateAtLevel(level) {
                return this.headings.some((h) => h.level === level && h.date !== null && h.date !== undefined);
            },

            get dateFormats() {
                return (window.ABMTB && Array.isArray(window.ABMTB.dateFormats))
                    ? window.ABMTB.dateFormats
                    : [];
            },

            showToast(type, message, duration = 3000) {
                if (this._toastTimer) clearTimeout(this._toastTimer);
                this.toast = { type, message };
                this._toastTimer = setTimeout(() => {
                    this.toast = null;
                    this._toastTimer = null;
                }, duration);
            },

            async uploadFile(event) {
                const input = event.target;
                const file = input.files && input.files[0];
                if (!file) return;

                const formData = new FormData();
                formData.append('file', file);

                this.uploading = true;
                this.editingSource = false;
                try {
                    const res = await fetch(
                        window.ABMTB.apiUrl + '/posts/' + window.ABMTB.postId + '/markdown',
                        {
                            method: 'POST',
                            headers: { 'X-WP-Nonce': window.ABMTB.nonce },
                            body: formData,
                        }
                    );
                    const json = await res.json().catch(() => ({}));
                    if (!res.ok || !json.success) {
                        throw new Error(json.error || 'Upload failed');
                    }
                    this.markdown = json.data.markdown;
                    this.headings = json.data.headings;
                    this.hydrateLevelSettings(json.data.levelSettings || this.levelSettings);
                    this.showToast('success', 'File uploaded.');
                } catch (err) {
                    this.showToast('error', err.message || 'Upload failed');
                } finally {
                    this.uploading = false;
                    input.value = '';
                }
            },

            async editSource() {
                if (this.editingSource || this.uploading) return;

                // Capture the pre's rendered height so the textarea opens at
                // exactly the same size and doesn't snap to a smaller value.
                let lockedHeight = null;
                if (this.$refs.sourcePre) {
                    lockedHeight = this.$refs.sourcePre.offsetHeight;
                }

                this._originalMarkdown = this.markdown;
                this.editingSource = true;
                await this.$nextTick();

                const ta = this.$refs.sourceTextarea;
                if (ta) {
                    if (lockedHeight && lockedHeight > 0) {
                        ta.style.height = lockedHeight + 'px';
                    }
                    ta.focus();
                    const end = ta.value.length;
                    ta.setSelectionRange(end, end);
                }
            },

            cancelEdit() {
                this.markdown = this._originalMarkdown;
                this.editingSource = false;
            },

            /**
             * Strip every horizontal-rule line (---, ***, ___ with 3+ chars)
             * from the saved markdown, then re-save. Collapses any run of
             * 3+ blank lines that the removal creates back down to 2 so the
             * source stays tidy.
             */
            async removeHorizontalRules() {
                if (this.uploading || this.savingSource || this.editingSource) return;
                if (!this.markdown) return;

                const cleaned = this.markdown
                    .replace(/^[ \t]*(?:-{3,}|\*{3,}|_{3,})[ \t]*$/gm, '')
                    .replace(/\n{3,}/g, '\n\n');

                if (cleaned === this.markdown) {
                    this.showToast('success', 'No horizontal rules to remove.');
                    return;
                }

                this.savingSource = true;
                try {
                    const res = await fetch(
                        window.ABMTB.apiUrl + '/posts/' + window.ABMTB.postId + '/source',
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': window.ABMTB.nonce,
                            },
                            body: JSON.stringify({ markdown: cleaned }),
                        }
                    );
                    const json = await res.json().catch(() => ({}));
                    if (!res.ok || !json.success) {
                        throw new Error(json.error || 'Save failed');
                    }
                    this.markdown = json.data.markdown;
                    this.headings = json.data.headings;
                    this.hydrateLevelSettings(json.data.levelSettings || this.levelSettings);
                    this.showToast('success', 'Horizontal rules removed.');
                } catch (err) {
                    this.showToast('error', err.message || 'Save failed');
                } finally {
                    this.savingSource = false;
                }
            },

            async saveSource() {
                if (!this.editingSource) return;
                if (this.markdown === this._originalMarkdown) {
                    this.editingSource = false;
                    return;
                }

                this.savingSource = true;
                try {
                    const res = await fetch(
                        window.ABMTB.apiUrl + '/posts/' + window.ABMTB.postId + '/source',
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': window.ABMTB.nonce,
                            },
                            body: JSON.stringify({ markdown: this.markdown }),
                        }
                    );
                    const json = await res.json().catch(() => ({}));
                    if (!res.ok || !json.success) {
                        throw new Error(json.error || 'Save failed');
                    }
                    this.markdown = json.data.markdown;
                    this.headings = json.data.headings;
                    this.hydrateLevelSettings(json.data.levelSettings || this.levelSettings);
                    this.editingSource = false;
                    this.showToast('success', 'Source saved.');
                } catch (err) {
                    // Leave edit mode open so user can retry.
                    this.showToast('error', err.message || 'Save failed');
                } finally {
                    this.savingSource = false;
                }
            },

            async saveLevels() {
                this.savingLevels = true;
                try {
                    const res = await fetch(
                        window.ABMTB.apiUrl + '/posts/' + window.ABMTB.postId + '/levels',
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': window.ABMTB.nonce,
                            },
                            body: JSON.stringify({ levels: this.levelSettings }),
                        }
                    );
                    const json = await res.json().catch(() => ({}));
                    if (!res.ok || !json.success) {
                        throw new Error(json.error || 'Save failed');
                    }
                } catch (err) {
                    this.showToast('error', err.message || 'Save failed');
                } finally {
                    this.savingLevels = false;
                }
            },
        };
    };

    document.addEventListener('alpine:init', function () {
        window.Alpine.data('abmtbEditor', window.ABMTB.components.abmtbEditor);
    });
})();
