<?php

declare(strict_types=1);

namespace Maildeno\Http;

/**
 * Default HttpTransport backed by ext-curl. Distinguishes a timeout from a
 * generic network failure so the client can raise TIMEOUT vs NETWORK_ERROR.
 */
final class CurlTransport implements HttpTransport
{
    public function __construct()
    {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException(
                'The curl extension is required by CurlTransport. '
                . 'Install ext-curl or inject a custom HttpTransport.',
            );
        }
    }

    public function get(string $url, array $headers, int $timeoutMs): array
    {
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_FOLLOWLOCATION    => false,
        ]);

        $body   = \curl_exec($ch);
        $errno  = \curl_errno($ch);
        $error  = \curl_error($ch);
        $status = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        \curl_close($ch);

        if ($errno !== 0) {
            // 28 == CURLE_OPERATION_TIMEDOUT (connect or transfer timeout).
            $isTimeout = ($errno === 28);
            throw new TransportException($error !== '' ? $error : 'Network request failed', $isTimeout);
        }

        return ['status' => $status, 'body' => (string) $body];
    }
}
