<?php

declare(strict_types=1);

use Maildeno\Http\HttpTransport;
use Maildeno\Http\TransportException;

/**
 * Tiny zero-dependency test harness. Shared static counters accumulate across
 * all *_test.php files required by run.php within a single process.
 */
final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static int $skip = 0;
    /** @var list<string> */
    public static array $failures = [];
    public static ?string $group = null;

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n  # {$name}\n";
    }

    public static function ok(string $label, bool $cond): void
    {
        if ($cond) {
            self::$pass++;
            echo "  [PASS] {$label}\n";
            return;
        }
        self::$fail++;
        self::$failures[] = (self::$group !== null ? self::$group . ' :: ' : '') . $label;
        echo "  [FAIL] {$label}\n";
    }

    public static function eq(string $label, mixed $expected, mixed $actual): void
    {
        $cond = $expected === $actual;
        self::ok($label, $cond);
        if (!$cond) {
            echo '         expected: ' . self::dump($expected) . "\n";
            echo '         actual:   ' . self::dump($actual) . "\n";
        }
    }

    public static function skip(string $label, string $why): void
    {
        self::$skip++;
        echo "  [SKIP] {$label} — {$why}\n";
    }

    /**
     * Assert that $fn throws, optionally of a given class and with a given
     * ->code (for MaildenoError).
     */
    public static function throws(string $label, callable $fn, ?string $class = null, ?string $codeEquals = null): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $ok = true;
            if ($class !== null && !($e instanceof $class)) {
                $ok = false;
            }
            if ($codeEquals !== null) {
                $code = \property_exists($e, 'code') ? $e->code : null;
                if ($code !== $codeEquals) {
                    $ok = false;
                }
            }
            self::ok($label, $ok);
            if (!$ok) {
                echo '         got: ' . \get_class($e)
                    . ' code=' . (\property_exists($e, 'code') ? (string) $e->code : 'n/a')
                    . ' msg=' . $e->getMessage() . "\n";
            }
            return;
        }
        self::ok($label, false);
        echo "         expected an exception, none thrown\n";
    }

    private static function dump(mixed $v): string
    {
        if (\is_string($v)) {
            return \strlen($v) > 80 ? '"' . \substr($v, 0, 77) . '..."' : '"' . $v . '"';
        }
        return \var_export($v, true);
    }
}

/** Mutable millisecond clock for deterministic TTL/eviction tests. */
final class FakeClock
{
    public function __construct(public int $now = 1_000_000) {}

    public function __invoke(): int
    {
        return $this->now;
    }

    public function advance(int $ms): void
    {
        $this->now += $ms;
    }
}

/**
 * Fake HttpTransport: enqueue responses/failures and record outgoing requests.
 * Mirrors how the JS test-suite mocks global fetch.
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<callable(): array{status:int, body:string}> */
    private array $queue = [];
    /** @var list<array{url:string, headers:list<string>, timeout:int}> */
    public array $requests = [];
    public int $calls = 0;

    public function pushJson(int $status, mixed $data): void
    {
        $body = \is_string($data) ? $data : \json_encode($data);
        $this->queue[] = static fn (): array => ['status' => $status, 'body' => (string) $body];
    }

    public function pushRaw(int $status, string $body): void
    {
        $this->queue[] = static fn (): array => ['status' => $status, 'body' => $body];
    }

    public function pushNetworkError(string $msg = 'connection refused'): void
    {
        $this->queue[] = static function () use ($msg): array {
            throw new TransportException($msg, false);
        };
    }

    public function pushTimeout(string $msg = 'operation timed out'): void
    {
        $this->queue[] = static function () use ($msg): array {
            throw new TransportException($msg, true);
        };
    }

    public function get(string $url, array $headers, int $timeoutMs): array
    {
        $this->calls++;
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'timeout' => $timeoutMs];
        if ($this->queue === []) {
            throw new \RuntimeException("FakeTransport: no queued response for {$url}");
        }
        $next = \array_shift($this->queue);
        return $next();
    }

    public function lastUrl(): string
    {
        $last = \end($this->requests);
        return $last === false ? '' : $last['url'];
    }

    /** @return list<string> */
    public function lastHeaders(): array
    {
        $last = \end($this->requests);
        return $last === false ? [] : $last['headers'];
    }
}
