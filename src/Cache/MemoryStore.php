<?php

declare(strict_types=1);

namespace Maildeno\Cache;

/**
 * In-process memory cache with TTL + stale-on-error fallback.
 * Port of the MemoryStore class in src/cache.ts.
 *
 *   TTL expiry     — after `ttl` ms getFresh() returns null (triggers a fetch).
 *   Stale fallback — getFallback() returns the entry even when stale, so
 *                    renders keep working when the server is down.
 *   Eviction       — when `maxEntries` is reached the oldest entry is dropped
 *                    (only when adding a NEW key, not when updating one).
 *
 * All times are in milliseconds to match the JS SDK's Date.now()/TTL semantics.
 */
final class MemoryStore implements CacheStore
{
    /** @var array<string, array{template: array<string,mixed>, fetchedAt: int, ttl: int}> */
    private array $store = [];

    /** @var callable(): int */
    private $clock;

    /**
     * @param int                $ttl        Freshness window in milliseconds.
     * @param int                $maxEntries Capacity before oldest-entry eviction.
     * @param (callable(): int)|null $clock  Millisecond clock (testing seam).
     */
    public function __construct(
        private readonly int $ttl,
        private readonly int $maxEntries,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) (\microtime(true) * 1000);
    }

    public function getFresh(string $id): ?array
    {
        $entry = $this->store[$id] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($this->isStale($entry)) {
            return null;
        }
        return $entry['template'];
    }

    public function getFallback(string $id): ?array
    {
        return $this->store[$id]['template'] ?? null;
    }

    public function set(string $id, array $template): void
    {
        // Evict oldest when full — only when adding a new key, not updating.
        if (\count($this->store) >= $this->maxEntries && !isset($this->store[$id])) {
            $oldestId = null;
            $oldestAt = PHP_INT_MAX;
            foreach ($this->store as $key => $entry) {
                if ($entry['fetchedAt'] < $oldestAt) {
                    $oldestAt = $entry['fetchedAt'];
                    $oldestId = $key;
                }
            }
            if ($oldestId !== null) {
                unset($this->store[$oldestId]);
            }
        }

        $this->store[$id] = [
            'template'  => $template,
            'fetchedAt' => ($this->clock)(),
            'ttl'       => $this->ttl,
        ];
    }

    public function invalidate(string $id): void
    {
        unset($this->store[$id]);
    }

    public function clear(): void
    {
        $this->store = [];
    }

    public function list(): array
    {
        return \array_keys($this->store);
    }

    /** @param array{fetchedAt: int, ttl: int} $entry */
    private function isStale(array $entry): bool
    {
        return ($this->clock)() - $entry['fetchedAt'] > $entry['ttl'];
    }
}
