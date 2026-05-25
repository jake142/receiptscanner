<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Exceptions;

use RuntimeException;

class ProviderException extends ReceiptScannerException
{
    public static function upstreamFailure(string $message, ?int $status = null, array $context = []): self
    {
        $suffix = [];

        if ($status !== null) {
            $suffix[] = 'status=' . $status;
        }

        foreach ($context as $key => $value) {
            if ($value === null || $value === '' || is_array($value) || is_object($value)) {
                continue;
            }

            $suffix[] = $key . '=' . (string) $value;
        }

        if ($suffix !== []) {
            $message .= ' (' . implode(', ', $suffix) . ')';
        }

        return new self($message, $status ?? 0);
    }

    public static function invalidResponse(string $message, array $context = []): self
    {
        return self::upstreamFailure($message, null, $context);
    }

    public static function jsonParseFailure(string $message, array $context = []): self
    {
        return self::upstreamFailure($message, null, $context);
    }
}
