<?php

declare(strict_types=1);

namespace Maildeno\Http;

/**
 * Abstraction over the HTTP GET used to fetch template JSON. The default
 * implementation is CurlTransport; tests inject a fake to drive the client
 * without a network.
 */
interface HttpTransport
{
    /**
     * Perform a GET request.
     *
     * @param string        $url       Absolute URL.
     * @param list<string>  $headers   Raw header lines, e.g. "Authorization: Bearer ...".
     * @param int           $timeoutMs Timeout in milliseconds.
     *
     * @return array{status: int, body: string} Completed response (any status).
     *
     * @throws TransportException If the request did not complete (network/timeout).
     */
    public function get(string $url, array $headers, int $timeoutMs): array;
}
