<?php

declare(strict_types=1);

namespace Maildeno\Http;

/**
 * Raised by an HttpTransport when the request fails to complete (connection
 * refused, DNS failure, timeout). A completed HTTP response — including 4xx and
 * 5xx — is NOT an exception; it is returned so the client can map the status.
 *
 * This mirrors the JS SDK's distinction between fetch() rejecting (network) or
 * aborting (timeout) versus a resolved Response with `!ok`.
 */
final class TransportException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $timeout = false)
    {
        parent::__construct($message);
    }
}
