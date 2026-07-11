<?php

declare(strict_types=1);

namespace Maildeno;

use Maildeno\Cache\CacheStore;
use Maildeno\Cache\DiskStore;
use Maildeno\Cache\MemoryStore;
use Maildeno\Cache\TemplateCache;
use Maildeno\Http\CurlTransport;
use Maildeno\Http\HttpTransport;
use Maildeno\Http\TransportException;

/**
 * MaildenoClient — faithful port of js/ts and python sdk.
 *
 * Fetches template JSON from the Maildeno API, caches it locally (memory or
 * disk), and renders output with the bundled native maildeno-engine
 * executable (via NativeEngine) — no configuration needed for normal use.
 *
 * Construct one client per worker process and reuse it — template JSON is
 * fetched once and cached.
 *
 * @example
 *   $client = new MaildenoClient(['apiKey' => 'sk_live_...']);
 *   $html = $client->renderHtml('550e8400-e29b-41d4-a716-446655440000', [
 *       'merge_tags' => ['text' => ['name' => 'Noruwa']],
 *       'context'    => ['plan' => 'pro'],
 *   ]);
 *
 * Shipping the engine binary somewhere non-standard, or testing against a
 * stub? Pass 'enginePath' with an exact path to override the bundled-binary
 * auto-detection, or inject a pre-built RenderEngine as the 3rd constructor
 * argument.
 */
final class MaildenoClient
{
    private const DEFAULT_BASE_URL   = 'https://api.maildeno.com';
    private const DEFAULT_TIMEOUT    = 30000;   // ms
    private const DEFAULT_TTL        = 300000;  // 5 minutes, ms
    private const DEFAULT_MAX        = 50;
    private const DEFAULT_CACHE_PATH = '.maildeno-cache';
    private const TEMPLATE_PATH      = '/v1/sdk/template';

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly int $timeout;
    private readonly TemplateCache $cache;
    private readonly HttpTransport $transport;

    private ?RenderEngine $engine;
    private readonly ?string $enginePath;

    /**
     * @param array{
     *     apiKey: string,
     *     baseUrl?: string,
     *     timeout?: int,
     *     cache?: array{type?: string, path?: string, ttl?: int, maxEntries?: int},
     *     enginePath?: string,
     *     clock?: callable(): int
     * } $config
     * @param HttpTransport|null $transport Custom transport (defaults to cURL).
     * @param RenderEngine|null $engine     Pre-built engine (else built lazily
     *                                       on first render: enginePath if given,
     *                                       otherwise the bundled binary via
     *                                       NativeEngine::locate()).
     */
    public function __construct(array $config, ?HttpTransport $transport = null, ?RenderEngine $engine = null)
    {
        if (empty($config['apiKey'])) {
            throw new MaildenoError('INVALID_API_KEY', 'apiKey is required.');
        }

        $this->apiKey  = (string) $config['apiKey'];
        $this->baseUrl = \rtrim((string) ($config['baseUrl'] ?? self::DEFAULT_BASE_URL), '/');
        $this->timeout = (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT);

        $clock = $config['clock'] ?? null;
        $this->cache = new TemplateCache(self::buildStore($config['cache'] ?? null, $clock));

        $this->transport  = $transport ?? new CurlTransport();
        $this->engine     = $engine;
        $this->enginePath = isset($config['enginePath']) ? (string) $config['enginePath'] : null;
    }

    /**
     * @param array{type?: string, path?: string, ttl?: int, maxEntries?: int}|null $cfg
     * @param (callable(): int)|null $clock
     */
    private static function buildStore(?array $cfg, ?callable $clock): CacheStore
    {
        $ttl        = (int) ($cfg['ttl'] ?? self::DEFAULT_TTL);
        $maxEntries = (int) ($cfg['maxEntries'] ?? self::DEFAULT_MAX);

        if (($cfg['type'] ?? null) === 'disk') {
            // Resolve relative paths against the current working directory so
            // behaviour is predictable regardless of where this is used from.
            $path = $cfg['path'] ?? self::DEFAULT_CACHE_PATH;
            $dir  = self::resolvePath($path);
            return new DiskStore($dir, $ttl, $maxEntries, $clock);
        }

        return new MemoryStore($ttl, $maxEntries, $clock);
    }

    private static function resolvePath(string $path): string
    {
        // Absolute (POSIX "/..." or Windows "C:\...") → as-is; else join to cwd.
        $isAbsolute = \str_starts_with($path, DIRECTORY_SEPARATOR)
            || (bool) \preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
        return $isAbsolute ? $path : \getcwd() . DIRECTORY_SEPARATOR . $path;
    }

    // ── Render ────────────────────────────────────────────────────────────────

    /**
     * Render a template to HTML, React Email TSX, or MJML. Template JSON is
     * fetched once and cached; subsequent calls with the same templateId render
     * with zero network overhead until the TTL expires.
     *
     * @param array{templateId: string, target?: string, dynamicData?: array<string,mixed>|null} $options
     *
     * @throws MaildenoError
     */
    public function render(array $options): RenderResult
    {
        $templateId  = (string) $options['templateId'];
        $target      = (string) ($options['target'] ?? 'html');
        $dynamicData = $options['dynamicData'] ?? null;

        [$template, $fromStaleCache] = $this->getTemplate($templateId, $target);

        $raw    = $this->engine()->renderTemplate($template, $target, $dynamicData);
        $output = Minify::output($target, $raw);

        return new RenderResult($templateId, $target, $output, $fromStaleCache);
    }

    /**
     * Convenience: render to HTML.
     *
     * @param array<string,mixed>|null $dynamicData
     */
    public function renderHtml(string $templateId, ?array $dynamicData = null): string
    {
        return $this->render(['templateId' => $templateId, 'target' => 'html', 'dynamicData' => $dynamicData])->output;
    }

    /**
     * Convenience: render to React Email TSX.
     *
     * @param array<string,mixed>|null $dynamicData
     */
    public function renderReact(string $templateId, ?array $dynamicData = null): string
    {
        return $this->render(['templateId' => $templateId, 'target' => 'react-email', 'dynamicData' => $dynamicData])->output;
    }

    /**
     * Convenience: render to MJML.
     *
     * @param array<string,mixed>|null $dynamicData
     */
    public function renderMjml(string $templateId, ?array $dynamicData = null): string
    {
        return $this->render(['templateId' => $templateId, 'target' => 'mjml', 'dynamicData' => $dynamicData])->output;
    }

    // ── Cache management ──────────────────────────────────────────────────────

    /** @return list<string> IDs of all templates currently held in the cache. */
    public function listCached(): array
    {
        return $this->cache->list();
    }

    /**
     * Remove a single template from the cache. The next render for it fetches a
     * fresh copy regardless of TTL.
     */
    public function deleteCached(string $templateId): void
    {
        $this->cache->invalidate($templateId);
    }

    /** Remove all templates from the cache. */
    public function clearCache(): void
    {
        $this->cache->clear();
    }

    /** @deprecated Use deleteCached(). Retained for compatibility. */
    public function invalidate(string $templateId): void
    {
        $this->deleteCached($templateId);
    }

    // ── Template fetching ─────────────────────────────────────────────────────

    /**
     * @return array{0: array<string,mixed>, 1: bool} [template, fromStaleCache]
     * @throws MaildenoError
     */
    private function getTemplate(string $id, string $target): array
    {
        // 1. Fresh hit — no network needed.
        $fresh = $this->cache->getFresh($id);
        if ($fresh !== null) {
            return [$fresh, false];
        }

        // 2. Miss or stale — try the network.
        try {
            $template = $this->getJson(self::TEMPLATE_PATH . '/' . \rawurlencode($id) . '?target=' . \rawurlencode($target));
            $this->cache->set($id, $template);
            return [$template, false];
        } catch (MaildenoError $err) {
            // 3. Network/HTTP failed — use a stale copy if we have one.
            $stale = $this->cache->getFallback($id);
            if ($stale !== null) {
                return [$stale, true];
            }
            throw $err; // No cached copy at all — surface the error.
        }
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed> Decoded JSON body.
     * @throws MaildenoError
     */
    private function getJson(string $path): array
    {
        $url = $this->baseUrl . $path;

        try {
            ['status' => $status, 'body' => $body] = $this->transport->get(
                $url,
                ['Authorization: Bearer ' . $this->apiKey],
                $this->timeout,
            );
        } catch (TransportException $e) {
            if ($e->timeout) {
                throw new MaildenoError('TIMEOUT', "Request timed out after {$this->timeout}ms");
            }
            throw new MaildenoError('NETWORK_ERROR', $e->getMessage() !== '' ? $e->getMessage() : 'Network request failed');
        }

        if ($status < 200 || $status >= 300) {
            $detail = null;
            $json = \json_decode($body, true);
            if (\is_array($json) && \array_key_exists('detail', $json)) {
                $detail = $json['detail'];
            }
            throw MaildenoError::fromStatus($status, $detail);
        }

        $data = \json_decode($body, true);
        if (!\is_array($data)) {
            throw new MaildenoError('UNKNOWN', 'Malformed template JSON in API response.', $status);
        }
        return $data;
    }

    /**
     * Lazily construct the engine if not injected. enginePath, if given,
     * overrides which exact binary to use; otherwise NativeEngine::locate()
     * auto-detects the binary bundled with this package for the current
     * OS/architecture — this is what makes the SDK work with zero engine
     * configuration.
     */
    private function engine(): RenderEngine
    {
        if ($this->engine === null) {
            $path = $this->enginePath ?? NativeEngine::locate();
            $this->engine = new NativeEngine($path);
        }
        return $this->engine;
    }
}
