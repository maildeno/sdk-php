<?php

declare(strict_types=1);

namespace Maildeno;

/**
 * Result of a render call.
 *
 * Note: in the JS SDK `fromStaleCache` is omitted (undefined) when the render
 * used a fresh template. Here it is always present as a bool and is simply
 * `false` in that case.
 */
final class RenderResult
{
    public function __construct(
        public readonly string $templateId,
        public readonly string $target,
        /** The rendered output string (HTML, TSX, or MJML), post-minify. */
        public readonly string $output,
        /** True when a stale cached template was used (server unreachable). */
        public readonly bool $fromStaleCache = false,
    ) {}
}
