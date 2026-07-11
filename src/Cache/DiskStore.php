<?php

declare (strict_types = 1);

namespace Maildeno\Cache;

/**
 * Persistent disk-based cache. Port of the DiskStore class in src/cache.ts.
 *
 * Layout — one file per template, named by the (sanitised) template UUID:
 *
 *   {cacheDir}/550e8400-e29b-41d4-a716-446655440000.json
 *
 * File contents:
 *   {"templateId":"...","fetchedAt":1717776000000,"ttl":300000,"template":{...}}
 *
 * Freshness is evaluated identically to MemoryStore (stale when
 * now - fetchedAt > ttl). Writes are atomic (temp file + rename). The cache
 * directory is created on first write; the caller is responsible for pointing
 * `cacheDir` at a writable location.
 */
final class DiskStore implements CacheStore
{
    /** @var callable(): int */
    private $clock;

    /**
     * @param string             $cacheDir   Directory for cache files.
     * @param int                $ttl        Freshness window in milliseconds.
     * @param int                $maxEntries Capacity before oldest-file eviction.
     * @param (callable(): int)|null $clock  Millisecond clock (testing seam).
     */
    public function __construct(
        private readonly string $cacheDir,
        private readonly int $ttl,
        private readonly int $maxEntries,
        ? callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn() : int => (int) (\microtime(true) * 1000);
    }

    public function getFresh(string $id): ?array
    {
        $entry = $this->read($id);
        if ($entry === null) {
            return null;
        }
        if ($this->isStale($entry)) {
            return null;
        }
        return $entry['template'] ?? null;
    }

    public function getFallback(string $id): ?array
    {
        $entry = $this->read($id);
        return $entry['template'] ?? null;
    }

    public function set(string $id, array $template): void
    {
        if (! \is_dir($this->cacheDir)) {
            \error_clear_last();
            if (! @\mkdir($this->cacheDir, 0777, true) && ! \is_dir($this->cacheDir)) {
                $this->warn("create cache directory {$this->cacheDir}");
                return; // no directory — nothing we can write into.
            }
        }
        $this->enforceLimit();

        $entry = [
            'templateId' => $id,
            'template'   => $template,
            'fetchedAt'  => ($this->clock)(),
            'ttl'        => $this->ttl,
        ];

        $json = \json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->warn("JSON-encode cache entry for \"{$id}\" (" . \json_last_error_msg() . ')');
            return;
        }

        // Write atomically: temp file then rename, so a crash mid-write never
        // leaves a corrupted cache file.
        $finalPath = $this->path($id);
        $tmpPath   = $finalPath . '.tmp';

        \error_clear_last();
        if (@\file_put_contents($tmpPath, $json) === false) {
            $this->warn("write temp cache file {$tmpPath}");
            return;
        }

        \error_clear_last();
        if (! @\rename($tmpPath, $finalPath)) {
            $this->warn("rename {$tmpPath} to {$finalPath}");
            @\unlink($tmpPath); // don't leave a stray .tmp file behind
        }
    }

    /**
     * Surface an otherwise-silent cache write failure via PHP's standard
     * warning channel (visible in logs / display_errors) without throwing —
     * a broken cache must never take down a render. Before this, failures
     * (permissions, a read-only path, a full disk, disabled mkdir/
     * file_put_contents) were swallowed completely, which is exactly what
     * makes "the directory appeared but no .json file did" undiagnosable.
     */
    private function warn(string $what): void
    {
        $last   = \error_get_last();
        $reason = $last['message'] ?? 'unknown error';
        \trigger_error("Maildeno DiskStore: failed to {$what}: {$reason}", E_USER_WARNING);
    }

    public function invalidate(string $id): void
    {
        $path = $this->path($id);
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    public function clear(): void
    {
        foreach ($this->jsonFiles() as $file) {
            @\unlink($this->cacheDir . DIRECTORY_SEPARATOR . $file);
        }
    }

    public function list(): array
    {
        return \array_map(
            static fn(string $f): string => \substr($f, 0, -5), // strip ".json"
            $this->jsonFiles(),
        );
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Full path for a template file. Template IDs are UUIDs ([0-9a-f-]) so they
     * pass through unchanged; any other char is replaced with `_` defensively.
     */
    private function path(string $id): string
    {
        $safeId = \preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
        return $this->cacheDir . DIRECTORY_SEPARATOR . $safeId . '.json';
    }

    /** @return array<string,mixed>|null Parsed disk entry, or null on miss/corruption. */
    private function read(string $id): ?array
    {
        $path = $this->path($id);
        if (! \is_file($path)) {
            return null;
        }
        $raw = @\file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $parsed = \json_decode($raw, true);
        return \is_array($parsed) ? $parsed : null; // corrupted → cache miss
    }

    /** @param array{fetchedAt?: int, ttl?: int} $entry */
    private function isStale(array $entry): bool
    {
        $fetchedAt = (int) ($entry['fetchedAt'] ?? 0);
        $ttl       = (int) ($entry['ttl'] ?? 0);
        return ($this->clock)() - $fetchedAt > $ttl;
    }

    /** @return list<string> Bare "*.json" filenames (never ".tmp"). */
    private function jsonFiles(): array
    {
        if (! \is_dir($this->cacheDir)) {
            return [];
        }
        $files = @\scandir($this->cacheDir);
        if ($files === false) {
            return [];
        }
        return \array_values(\array_filter(
            $files,
            static fn(string $f): bool =>
            \str_ends_with($f, '.json') && ! \str_ends_with($f, '.tmp'),
        ));
    }

    /**
     * Keep the number of cached files within maxEntries, evicting the oldest by
     * fetchedAt to make room for one new entry. Reads only the fetchedAt header
     * from each file. Corrupted files sort first (fetchedAt 0) and are evicted.
     */
    private function enforceLimit(): void
    {
        $files = $this->jsonFiles();
        if (\count($files) < $this->maxEntries) {
            return;
        }

        $entries = [];
        foreach ($files as $file) {
            $raw       = @\file_get_contents($this->cacheDir . DIRECTORY_SEPARATOR . $file);
            $fetchedAt = 0;
            if ($raw !== false) {
                $parsed = \json_decode($raw, true);
                if (\is_array($parsed)) {
                    $fetchedAt = (int) ($parsed['fetchedAt'] ?? 0);
                }
            }
            $entries[] = ['file' => $file, 'fetchedAt' => $fetchedAt];
        }

        \usort($entries, static fn(array $a, array $b): int => $a['fetchedAt'] <=> $b['fetchedAt']);

        $toEvict = \array_slice($entries, 0, \count($entries) - $this->maxEntries + 1);
        foreach ($toEvict as $e) {
            @\unlink($this->cacheDir . DIRECTORY_SEPARATOR . $e['file']);
        }
    }
}
