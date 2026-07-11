<?php

declare(strict_types=1);

namespace Maildeno;

/**
 * Anything that can turn a template + target + dynamic data into rendered
 * output implements this. MaildenoClient depends only on this interface,
 * not on `NativeEngine` (the only real implementation) directly — so tests
 * and advanced use cases can inject a stub or alternative engine without
 * needing to spawn a real process.
 */
interface RenderEngine
{
    /**
     * @param array<string, mixed>      $template
     * @param array<string, mixed>|null $dynamicData
     * @throws MaildenoError
     */
    public function renderTemplate(array $template, string $target, ?array $dynamicData = null): string;

    /**
     * Low-level: exact input-envelope JSON in, exact rendered output string
     * out (already unwrapped — not JSON), unparsed.
     *
     * @throws MaildenoError
     */
    public function renderRawResult(string $inputJson): string;
}
