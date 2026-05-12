<?php
/**
 * Loop iteration context for shortcodes.
 *
 * @package AB\MarkdownToBricks
 * @since   0.1.0
 */

declare(strict_types=1);

namespace AB\MarkdownToBricks\Shortcodes;

defined('ABSPATH') || exit;

/**
 * Stack of nested shortcode loop frames.
 *
 * Each frame records which post + label + heading is the "current" item
 * for the surrounding loop. Accessor shortcodes walk the stack from the
 * inside out to find the frame matching their own label, so deeper code
 * can still reach an ancestor loop's current item.
 *
 * @phpstan-type Heading array{level:int,text:string,description:string,html:string,date:?int}
 * @phpstan-type Frame   array{post_id:int,label:string,level:int,heading:Heading}
 */
final class LoopContext {

    /**
     * Stack of frames. Top of stack = innermost loop iteration.
     *
     * @var array<int, array{post_id:int,label:string,level:int,heading:array<string,mixed>}>
     */
    private static array $stack = [];

    /**
     * Push a new frame onto the stack.
     *
     * `date_format_override` lets the outer loop shortcode pass an inline
     * `date_format="..."` value that should win over the post's saved
     * per-level setting for this iteration. Empty string = use saved.
     *
     * @param array<string, mixed> $heading
     */
    public static function push(int $post_id, string $label, int $level, array $heading, string $date_format_override = ''): void {
        self::$stack[] = [
            'post_id'     => $post_id,
            'label'       => $label,
            'level'       => $level,
            'heading'     => $heading,
            'date_format' => $date_format_override,
        ];
    }

    /**
     * Pop the topmost frame.
     */
    public static function pop(): void {
        array_pop(self::$stack);
    }

    /**
     * Top of stack (innermost loop). Null when no loop is active.
     *
     * @return array<string, mixed>|null
     */
    public static function top(): ?array {
        $top = end(self::$stack);
        return $top === false ? null : $top;
    }

    /**
     * Find the nearest frame matching a label, searching from inside out.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $label): ?array {
        for ($i = count(self::$stack) - 1; $i >= 0; $i--) {
            if (self::$stack[$i]['label'] === $label) {
                return self::$stack[$i];
            }
        }
        return null;
    }

    /**
     * Reset the stack. Test/safety helper — not used in normal flow.
     */
    public static function reset(): void {
        self::$stack = [];
    }
}
