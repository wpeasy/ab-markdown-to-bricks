<?php
/**
 * Markdown heading + description parser.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks\Parser;

use AB\MarkdownToBricks\Service\DateDetector;
use AB\MarkdownToBricks\Service\MarkdownRenderer;

defined('ABSPATH') || exit;

/**
 * Extracts ATX-style headings (# through ######) and the body content
 * that follows each one, up to (but not including) the next heading line
 * of any level — or end of document.
 *
 * Skips fenced code blocks (```...``` and ~~~...~~~) for heading detection
 * so a `# Title` inside a code example isn't treated as a heading. Code
 * blocks ARE included as part of the surrounding description.
 *
 * Setext headings (underline-style) are intentionally not supported in v1.
 */
final class HeadingParser {

    /**
     * Parse headings from a markdown string.
     *
     * `text_clean` is the heading text with any detected date substring
     * removed (and surrounding punctuation tidied). When no date was
     * detected it equals `text` verbatim. Consumers that want to strip the
     * date when a date format is active read this field instead of `text`.
     *
     * @param string $markdown Raw markdown content.
     * @return array<int, array{level:int,text:string,text_clean:string,description:string,html:string,date:?int}>
     */
    public static function parse(string $markdown): array {
        $lines    = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
        $headings = [];
        $current  = -1;
        $in_fence = false;
        $fence    = '';

        foreach ($lines as $line) {
            // Track fenced code blocks. The fence lines themselves and
            // everything inside them are content, not heading candidates.
            if (!$in_fence && preg_match('/^(?:```|~~~)/', $line, $m)) {
                $in_fence = true;
                $fence    = substr($m[0], 0, 3);
                if ($current >= 0) {
                    $headings[$current]['description'] .= $line . "\n";
                }
                continue;
            }
            if ($in_fence) {
                if (strpos(ltrim($line), $fence) === 0) {
                    $in_fence = false;
                }
                if ($current >= 0) {
                    $headings[$current]['description'] .= $line . "\n";
                }
                continue;
            }

            // ATX heading?
            if (preg_match('/^(#{1,6})\s+(.+?)(?:\s+#+)?\s*$/', $line, $m)) {
                $text = trim($m[2]);
                if ($text === '') {
                    if ($current >= 0) {
                        $headings[$current]['description'] .= $line . "\n";
                    }
                    continue;
                }
                $detected   = DateDetector::extract($text);
                $text_clean = $detected
                    ? DateDetector::strip($text, $detected['match'])
                    : $text;
                $headings[] = [
                    'level'       => strlen($m[1]),
                    'text'        => $text,
                    'text_clean'  => $text_clean,
                    'description' => '',
                    'html'        => '',
                    'date'        => $detected ? $detected['timestamp'] : null,
                ];
                $current = count($headings) - 1;
                continue;
            }

            // Body line for the current heading (or preamble we discard).
            if ($current >= 0) {
                $headings[$current]['description'] .= $line . "\n";
            }
        }

        // Trim trailing whitespace and render description HTML for each.
        foreach ($headings as &$h) {
            $h['description'] = trim($h['description']);
            $h['html']        = MarkdownRenderer::render($h['description']);
        }
        unset($h);

        return $headings;
    }

    /**
     * Return sorted list of unique heading levels present in the parsed set.
     *
     * @param array<int, array{level:int,text:string}> $headings
     * @return array<int, int>
     */
    public static function levels_present(array $headings): array {
        $levels = [];
        foreach ($headings as $h) {
            $levels[(int) $h['level']] = true;
        }
        $levels = array_keys($levels);
        sort($levels);
        return $levels;
    }

    /**
     * Get the text of the first heading at a given level.
     *
     * @param array<int, array{level:int,text:string}> $headings
     */
    public static function first_text_at_level(array $headings, int $level): string {
        foreach ($headings as $h) {
            if ((int) $h['level'] === $level) {
                return (string) $h['text'];
            }
        }
        return '';
    }
}
