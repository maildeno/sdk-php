<?php

declare(strict_types=1);

namespace Maildeno;

/**
 * All errors thrown by the Maildeno SDK are instances of MaildenoError.
 *
 * @example
 *   try {
 *       $client->render(['templateId' => '...']);
 *   } catch (MaildenoError $e) {
 *       error_log($e->code . ' ' . $e->getMessage() . ' ' . $e->status);
 *       if ($e->issues !== null) { error_log(json_encode($e->issues)); }
 *   }
 *
 * Error codes (SdkErrorCode):
 *   INVALID_API_KEY | FORBIDDEN | TEMPLATE_NOT_FOUND | RENDER_ERROR
 *   NETWORK_ERROR   | TIMEOUT   | UNKNOWN
 */
final class MaildenoError extends \RuntimeException
{
    /**
     * Machine-readable error code (an SdkErrorCode string). Declared public and
     * non-readonly only because it widens the inherited Exception::$code, which
     * PHP forbids redeclaring as readonly. Treat it as immutable.
     *
     * @var string
     */
    public $code;

    /**
     * @param string                         $code    Machine-readable error code.
     * @param string                         $message Human-readable message.
     * @param int                            $status  HTTP status (0 for network / timeout errors).
     * @param list<array<string,mixed>>|null $issues  Structured validation issues (422), else null.
     */
    public function __construct(
        string $code,
        string $message,
        public readonly int $status = 0,
        public readonly ?array $issues = null,
    ) {
        parent::__construct($message);
        $this->code = $code;
    }

    /**
     * Build a MaildenoError from an HTTP status and the raw `detail` field.
     * `detail` may be a string (HTTPException), an array (validation issues),
     * or null (missing/unparseable).
     */
    public static function fromStatus(int $status, mixed $detail): self
    {
        static $codeMap = [
            401 => 'INVALID_API_KEY',
            403 => 'FORBIDDEN',
            404 => 'TEMPLATE_NOT_FOUND',
            422 => 'RENDER_ERROR',
        ];
        $code = $codeMap[$status] ?? 'UNKNOWN';
        [$message, $issues] = self::formatDetail($detail, $status);
        return new self($code, $message, $status, $issues);
    }

    /**
     * Normalise the `detail` field into a message and optional issues array.
     *
     * @return array{0: string, 1: list<array<string,mixed>>|null}
     */
    private static function formatDetail(mixed $detail, int $status): array
    {
        // String — HTTPException("...") path. Use as-is.
        if (\is_string($detail) && $detail !== '') {
            return [$detail, null];
        }

        // Array — RequestValidationError path. Each entry has msg + loc.
        if (\is_array($detail) && $detail !== [] && self::isValidationIssueArray($detail)) {
            $issues = \array_values($detail);
            $message = \implode('; ', \array_map(
                static function (array $issue): string {
                    // loc is like ["body", "template_id"] — drop the leading
                    // "body" since it's noise to API consumers.
                    $loc = $issue['loc'] ?? [];
                    $parts = [];
                    foreach ($loc as $i => $p) {
                        if ($i === 0 && $p === 'body') {
                            continue;
                        }
                        $parts[] = (string) $p;
                    }
                    $path = \implode('.', $parts);
                    $msg = (string) ($issue['msg'] ?? '');
                    return $path !== '' ? "{$path}: {$msg}" : $msg;
                },
                $issues,
            ));
            return [$message, $issues];
        }

        // Object / null / anything else — status-based fallback.
        return ["HTTP {$status}", null];
    }

    /** @param list<mixed> $arr */
    private static function isValidationIssueArray(array $arr): bool
    {
        foreach ($arr as $item) {
            if (!\is_array($item) || !isset($item['msg']) || !\is_string($item['msg'])) {
                return false;
            }
        }
        return true;
    }
}
