<?php
/**
 * Date detection inside heading text.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks\Service;

defined('ABSPATH') || exit;

/**
 * Looks for the first parseable date inside a string.
 *
 * Designed for heading text where the date may sit alongside other words —
 * e.g., "Posted on 2024-01-15: Release notes". The regexes are deliberately
 * narrow (whole-word matches against well-known patterns) so we don't grab
 * random number sequences that look date-ish.
 */
final class DateDetector {

    /**
     * Regex patterns tried in order. The first capture group is the candidate
     * substring; that substring is then handed to strtotime() for parsing.
     *
     * @var string[]
     */
    private const PATTERNS = [
        // ISO 8601 datetime with optional time + timezone.
        '/\b(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)\b/',
        // ISO 8601 date.
        '/\b(\d{4}-\d{2}-\d{2})\b/',
        // 15 January 2024
        '/\b(\d{1,2}\s+(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4})\b/i',
        // January 15, 2024  (also "January 15 2024")
        '/\b((?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b/i',
        // 15 Jan 2024
        '/\b(\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{4})\b/i',
        // Jan 15, 2024
        '/\b((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},?\s+\d{4})\b/i',
        // Slash dates — strtotime defaults to US (m/d/Y).
        '/\b(\d{1,2}\/\d{1,2}\/\d{4})\b/',
        // Dot dates — strtotime treats as d.m.Y.
        '/\b(\d{1,2}\.\d{1,2}\.\d{4})\b/',
    ];

    /**
     * Find the first date-looking substring in $text and return both its
     * Unix timestamp and the literal substring that matched (so callers can
     * strip it from the surrounding text). Returns null when nothing parses.
     *
     * @return array{timestamp:int,match:string}|null
     */
    public static function extract(string $text): ?array {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $ts = strtotime($m[1]);
                if ($ts !== false) {
                    return ['timestamp' => $ts, 'match' => $m[1]];
                }
            }
        }
        return null;
    }

    /**
     * Matching bracket pairs that should be stripped along with the date
     * when the date is wrapped in them. Order is not significant.
     */
    private const BRACKET_PAIRS = [
        ['(', ')'],
        ['[', ']'],
        ['{', '}'],
        ['<', '>'],
    ];

    /**
     * Remove a previously-matched date substring from a heading and tidy up
     * the leftover whitespace and adjacent punctuation.
     *
     * If the date is wrapped in a matching bracket pair — `(2024-01-15)`,
     * `[2024-01-15]`, `{2024-01-15}`, `<2024-01-15>` — the brackets are
     * stripped along with the date so we don't leave behind empty `()` /
     * `[]` shells.
     *
     * Examples:
     *   ("Release 2024-01-15: hotfix", "2024-01-15") → "Release: hotfix"
     *   ("Release (2024-01-15) hotfix", "2024-01-15") → "Release hotfix"
     *   ("Release [2024-01-15]", "2024-01-15") → "Release"
     */
    public static function strip(string $text, string $match): string {
        if ($match === '') {
            return $text;
        }

        $quoted = preg_quote($match, '/');

        // Try each bracket pair first; if the date is wrapped in any of
        // them, remove the brackets together with the date so we don't
        // leave behind empty `()` shells.
        foreach (self::BRACKET_PAIRS as [$open, $close]) {
            $pattern = '/' . preg_quote($open, '/') . '\s*' . $quoted . '\s*' . preg_quote($close, '/') . '/';
            if (preg_match($pattern, $text)) {
                $clean = (string) preg_replace($pattern, '', $text, 1);
                return self::tidy($clean);
            }
        }

        // No surrounding brackets — strip the bare date substring.
        $clean = (string) preg_replace('/' . $quoted . '/', '', $text, 1);
        return self::tidy($clean);
    }

    /**
     * Whitespace + adjacent-punctuation cleanup applied after any strip path.
     */
    private static function tidy(string $text): string {
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        // Trim punctuation that commonly sits next to a date ("2024 -" / "- 2024" etc.).
        $text = (string) preg_replace('/^[\-:,;.\s]+|[\-:,;.\s]+$/', '', $text);
        return $text;
    }
}
