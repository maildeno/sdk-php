# Maildeno PHP SDK

The official PHP SDK for [Maildeno](https://maildeno.com). It fetches template JSON from the Maildeno API,
caches it locally (memory or disk), and renders HTML / React Email / MJML
using a small native `maildeno-engine` executable bundled with the package
for your platform. No engine setup — install it and render:

```bash
composer require maildeno/maildeno-php
```

```php
use Maildeno\MaildenoClient;

$client = new MaildenoClient(['apiKey' => 'sk_live_...']);
$html   = $client->renderHtml('550e8400-e29b-41d4-a716-446655440000', [
    'merge_tags' => ['text' => ['name' => 'Noruwa']],
    'context'    => ['plan' => 'pro'],
]);
```

`MaildenoClient` resolves the right `maildeno-engine` binary for the current
OS/architecture the first time you render, from a `bin/<platform>/` directory
bundled inside the package itself — nothing to configure, and it works
regardless of where in your project you construct the client.

## Requirements

- **PHP ≥ 8.1** with `ext-curl` and `ext-json`.
- [`symfony/process`](https://packagist.org/packages/symfony/process) — a
  real dependency, installed automatically with `composer require
  maildeno/maildeno-php`.
- A `maildeno-engine` binary bundled for your platform. Windows, Linux
  (x64/arm64), and macOS (x64/arm64) are supported; see *Getting the native
  binaries* below if you need to add one for a platform that isn't bundled
  yet, or if you're building this package from source rather than a release.

### Getting the native binaries

Build `maildeno-engine` for each platform you deploy to and place it under
`bin/<platform>/engine[.exe]` — run `NativeEngine::locate()` to see
the exact layout expected, or let it resolve the path for you. Build the
Linux targets against musl (statically linked) so one binary per architecture
runs on both glibc and musl (Alpine) hosts, and code-sign the macos-arm64
build (`codesign -s -`) or it will not launch on Apple Silicon.

## Install

```bash
composer require maildeno/maildeno-php
```

No Composer? This package ships a PSR-4 autoloader for `Maildeno\*` classes
that doesn't need Composer *for that* — but rendering still needs
`symfony/process` autoloadable (a real dependency, not optional), so you'd
need to get that from somewhere too. See the docblock in `autoload.php` for
the details; using Composer is the straightforward path.

```php
require 'path/to/maildeno-php/autoload.php';
```

## Quick start

```php
use Maildeno\MaildenoClient;

$client = new MaildenoClient(['apiKey' => 'sk_live_...']);

// Convenience helpers return the rendered string.
$html = $client->renderHtml('550e8400-e29b-41d4-a716-446655440000', [
    'merge_tags' => ['text' => ['name' => 'Noruwa']],
    'context'    => ['plan' => 'pro'],
]);

$tsx  = $client->renderReact($templateId, $dynamicData);
$mjml = $client->renderMjml($templateId, $dynamicData);
```

Full form returns a `RenderResult`:

```php
$result = $client->render([
    'templateId'  => $templateId,
    'target'      => 'html',          // 'html' | 'react-email' | 'mjml'
    'dynamicData' => ['merge_tags' => ['text' => ['name' => 'Noruwa']]],
]);

$result->output;          // string
$result->target;          // 'html'
$result->fromStaleCache;  // true if a stale cached copy was used (see below)
```

Shipping the binary somewhere non-standard, or testing against a stub? Pass
`enginePath` with an exact path to override auto-detection:

```php
new MaildenoClient(['apiKey' => '...', 'enginePath' => '/custom/path/to/maildeno-engine']);
```

## Caching

Template JSON is fetched once and cached; subsequent renders of the same
`templateId` do zero network I/O until the TTL expires.

```php
// In-memory (default) — fast, per-process, lost on restart.
new MaildenoClient(['apiKey' => '...', 'cache' => ['ttl' => 60_000, 'maxEntries' => 20]]);

// Disk — survives restarts. Path may be absolute or relative to CWD.
new MaildenoClient(['apiKey' => '...', 'cache' => [
    'type' => 'disk',
    'path' => '/var/cache/maildeno',
    'ttl'  => 300_000,   // ms
]]);
```

Defaults: memory cache, `ttl` 300000 ms (5 min), `maxEntries` 50. When capacity
is reached the oldest entry is evicted.

Management:

```php
$client->listCached();            // ['550e8400-...', ...]
$client->deleteCached($id);       // force a fresh fetch for one template
$client->clearCache();            // drop everything
```

### Stale-on-error fallback

If the TTL has expired and the API is unreachable (network failure or a non-2xx
response), the last known-good cached copy is used and `result->fromStaleCache`
is `true`, so renders keep working during an outage. If there is no cached copy
at all, the underlying `MaildenoError` is raised.

## Error handling

Every failure is a `MaildenoError`:

```php
use Maildeno\MaildenoError;

try {
    $client->renderHtml($id);
} catch (MaildenoError $e) {
    $e->code;     // 'INVALID_API_KEY' | 'FORBIDDEN' | 'TEMPLATE_NOT_FOUND'
                  // | 'RENDER_ERROR'  | 'NETWORK_ERROR' | 'TIMEOUT' | 'UNKNOWN'
    $e->status;   // HTTP status (0 for network/timeout)
    $e->issues;   // array of validation issues for 422s, else null
}
```

Status mapping: 401 → `INVALID_API_KEY`, 403 → `FORBIDDEN`, 404 →
`TEMPLATE_NOT_FOUND`, 422 → `RENDER_ERROR` (with `issues` populated from a
Pydantic-style `detail` array), other non-2xx → `UNKNOWN`.

A 403 with a message like *"This API key does not have access to the 'mjml'
target"* is the API telling you your key's plan doesn't include that target —
not a bug in the SDK; check your account/plan if you hit it unexpectedly.

> Note: PHP's `Exception` already owns an `int $code`, so `MaildenoError::$code`
> widens it to hold the string SDK code. It is public but should be treated as
> immutable.

## In production (php-fpm)

No FFI-style enablement dance — `proc_open` is available by default in both
the CLI and php-fpm SAPIs. The one thing to check on locked-down shared hosts
is that `proc_open`/`proc_close` aren't disabled via `disable_functions`. Each
render spawns the binary fresh (there's no compiled-module state to amortize),
so there's no strict need to build one client per worker the way some FFI-based
setups require — though nothing stops you from doing so for consistency. The
engine constructor takes an optional timeout in seconds for the subprocess
call (default 30):

```php
new NativeEngine('/path/to/maildeno-engine', timeoutSeconds: 10.0);
```

## Custom transport

The HTTP layer is injectable. The default is cURL; supply your own by
implementing `Maildeno\Http\HttpTransport` (useful for PSR-18 clients, custom
proxies, or testing):

```php
use Maildeno\Http\HttpTransport;

final class MyTransport implements HttpTransport {
    public function get(string $url, array $headers, int $timeoutMs): array {
        // ... return ['status' => 200, 'body' => '...'];
        // throw new \Maildeno\Http\TransportException($msg, $isTimeout) on failure
    }
}

$client = new MaildenoClient($config, new MyTransport());
```

You can also inject a pre-built engine (anything implementing
`Maildeno\RenderEngine` — `NativeEngine`, or your own stub) as the third
constructor argument. The test suite does this to reuse one engine across many
clients, and it's the easiest way to test your own code against Maildeno
without spawning a real process.

## Low-level: the engine directly

If you only need rendering (no API/cache), use the engine on its own:

```php
use Maildeno\NativeEngine;

$engine = new NativeEngine(NativeEngine::locate());
$html   = $engine->renderTemplate($templateArray, 'html', $dynamicData);
```

## Testing

The package includes a zero-dependency test suite (no PHPUnit required):

```bash
php tests/run.php
```

101 tests cover the error mapping, both cache stores (TTL, eviction, disk
persistence, stale fallback), the minifier (with exact-output parity vs the JS
SDK), and the client (request shape, caching, stale-on-error, error codes,
full render pipeline against the real bundled engine for your platform). The
client/render suite skips gracefully if no binary is bundled for the platform
running the tests.


## License

MIT © Maildeno
