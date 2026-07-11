<?php

declare(strict_types=1);

namespace Maildeno\Cache;

/**
 * Thin facade over the active CacheStore. Port of the TemplateCache class in
 * src/cache.ts. The client uses this exclusively and never references
 * MemoryStore or DiskStore directly, so swapping strategies is transparent.
 */
final class TemplateCache
{
    public function __construct(private readonly CacheStore $store) {}

    /** @return array<string,mixed>|null */
    public function getFresh(string $id): ?array
    {
        return $this->store->getFresh($id);
    }

    /** @return array<string,mixed>|null */
    public function getFallback(string $id): ?array
    {
        return $this->store->getFallback($id);
    }

    /** @param array<string,mixed> $template */
    public function set(string $id, array $template): void
    {
        $this->store->set($id, $template);
    }

    public function invalidate(string $id): void
    {
        $this->store->invalidate($id);
    }

    public function clear(): void
    {
        $this->store->clear();
    }

    /** @return list<string> */
    public function list(): array
    {
        return $this->store->list();
    }
}
