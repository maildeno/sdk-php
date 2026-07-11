<?php

declare(strict_types=1);

namespace Maildeno\Cache;

/**
 * All cache implementations satisfy this interface. TemplateCache delegates
 * every operation to the active store so the rest of the SDK is unaware of
 * which strategy is in use. Port of the CacheStore interface in src/cache.ts.
 *
 * Unlike the JS SDK these methods are synchronous — PHP filesystem I/O is
 * synchronous, so there is no Promise wrapping. Behaviour is identical.
 */
interface CacheStore
{
    /** @return array<string,mixed>|null Fresh (non-stale) template, or null. */
    public function getFresh(string $id): ?array;

    /** @return array<string,mixed>|null Cached template ignoring staleness, or null. */
    public function getFallback(string $id): ?array;

    /** @param array<string,mixed> $template */
    public function set(string $id, array $template): void;

    public function invalidate(string $id): void;

    public function clear(): void;

    /** @return list<string> IDs currently held in the cache. */
    public function list(): array;
}
