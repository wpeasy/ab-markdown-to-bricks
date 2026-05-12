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
     * Remove a previously-matched date substring from a heading and tidy up
     * the leftover whitespace and adjacent punctuation.
     *
     * Example: ("Release 2024-01-15: hotfix", "2024-01-15") → "Release: hotfix"
     */
    public static function strip(string $text, string $match): string {
        if ($match === '') {
            return $text;
        }
        $clean = str_replace($match, '', $text);
        $clean = (string) preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);
        // Trim punctuation that commonly sits next to a date ("2024 -" / "- 2024" etc.).
        $clean = (string) preg_replace('/^[\-:,;.\s]+|[\-:,;.\s]+$/', '', $clean);
        return $clean;
    }
}
