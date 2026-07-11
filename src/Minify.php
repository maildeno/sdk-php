<?php

declare(strict_types=1);

namespace Maildeno;

/**
 * Whitespace-only compaction for all render targets.
 *
 * Collapses redundant whitespace so output is compact for transport, without
 * altering comments, attribute quotes/values, or CSS property values.
 */
final class Minify
{
    /**
     * Splits markup into alternating normal segments and special blocks
     * (<style>…</style>, <script>…</script>, <!-- … -->) so each kind is
     * compacted differently without corrupting its contents.
     */
    private const SPECIAL_BLOCK_RE =
        '#(<style[\s>].*?</style>|<script[\s>].*?</script>|<!--.*?-->)#is';

    /** Route `source` to the correct compactor for `target`. Unknown → unchanged. */
    public static function output(string $target, string $source): string
    {
        return match ($target) {
            'html'        => self::html($source),
            'mjml'        => self::mjml($source),
            'react-email' => self::react($source),
            default       => $source,
        };
    }

    public static function html(string $source): string
    {
        if ($source === '') {
            return $source;
        }
        return self::collapseMarkup($source);
    }

    public static function mjml(string $source): string
    {
        if ($source === '') {
            return $source;
        }
        return self::collapseMarkup($source);
    }

    public static function react(string $source): string
    {
        if ($source === '') {
            return $source;
        }
        // Strip leading/trailing spaces on each line.
        $result = \preg_replace('/^[ \t]+|[ \t]+$/m', '', $source);
        // Collapse runs of 3+ newlines to a single blank line.
        $result = \preg_replace('/\n{3,}/', "\n\n", $result);
        return \trim($result);
    }

    private static function collapseMarkup(string $source): string
    {
        // PREG_SPLIT_DELIM_CAPTURE keeps the captured special blocks in the
        // result, mirroring JS String.split() with a capturing group:
        // even indices = normal markup, odd indices = special blocks.
        $parts = \preg_split(
            self::SPECIAL_BLOCK_RE,
            $source,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        $out = [];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                // Special block — compact only leading/trailing whitespace per
                // line and consecutive blank lines. Selector/value content kept.
                $compacted = \preg_replace('/^[ \t]+|[ \t]+$/m', '', $part);
                $compacted = \preg_replace('/\n{2,}/', "\n", $compacted);
                $out[] = $compacted;
            } else {
                // Normal markup — collapse whitespace between tags and runs of
                // spaces/tabs inside text nodes.
                $segment = \preg_replace('/>\s+</', '><', $part);   // between tags
                $segment = \preg_replace('/[ \t]{2,}/', ' ', $segment); // text nodes
                $segment = \preg_replace('/\n+/', '', $segment);    // remove newlines
                $out[] = $segment;
            }
        }

        return \implode('', $out);
    }
}
