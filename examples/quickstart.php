<?php

declare(strict_types=1);

/**
 * Maildeno PHP SDK — quickstart.
 *
 *   php examples/quickstart.php
 *
 * Construct a client, then render templates by
 * ID. Template JSON is fetched from the API once and cached; the bundled
 * native engine renders locally — no engine configuration needed. Set
 * MAILDENO_API_KEY to hit the real API — otherwise the network call will
 * fail (and, with no cache, raise a MaildenoError).
 */

require __DIR__ . '/../autoload.php';

use Maildeno\MaildenoClient;
use Maildeno\MaildenoError;

$client = new MaildenoClient([
    'apiKey' => \getenv('MAILDENO_API_KEY') ?: 'MAILDENO_API_KEY',
    'cache'  => [
        'type' => 'disk',                 // survives restarts; omit for in-memory
        'path' => \sys_get_temp_dir() . '/maildeno-cache',
        'ttl'  => 300_000,                // 5 minutes (ms)
    ],
]);

$templateId = '550e8400-e29b-41d4-a716-446655440000';

try {
    // Convenience helpers — return the rendered string directly.
    $html = $client->renderHtml($templateId, [
        'merge_tags' => ['text' => ['name' => 'Noruwa']],
        'context'    => ['plan' => 'pro'],
    ]);
    echo $html, "\n";

    // Full form — returns a RenderResult (templateId, target, output, fromStaleCache).
    $result = $client->render([
        'templateId'  => $templateId,
        'target'      => 'html', // 'html' | 'react-email' | 'mjml'
        'dynamicData' => ['merge_tags' => ['text' => ['name' => 'Noruwa']]],
    ]);
    if ($result->fromStaleCache) {
        \fwrite(STDERR, "[warn] served from stale cache — API was unreachable\n");
    }

    // Cache management
    $client->listCached();          // ['550e8400-...']
    $client->deleteCached($templateId); // force a fresh fetch next time
    // $client->clearCache();
} catch (MaildenoError $e) {
    \fwrite(STDERR, "Maildeno error [{$e->code}] status={$e->status}: {$e->getMessage()}\n");
    if ($e->issues !== null) {
        \fwrite(STDERR, 'validation issues: ' . \json_encode($e->issues) . "\n");
    }
    exit(1);
}
