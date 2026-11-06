<?php

namespace App\Sesame\Ingest;

use RuntimeException;

/** An error in SES v2's REST-JSON shape: x-amzn-ErrorType header plus a message body. */
final class SesError extends RuntimeException
{
    public function __construct(public readonly string $type, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function badRequest(string $message): self
    {
        return new self('BadRequestException', 400, $message);
    }

    public static function throttled(): self
    {
        return new self('TooManyRequestsException', 429, 'Maximum sending rate exceeded.');
    }

    public static function rejected(): self
    {
        return new self('MessageRejected', 400, 'Email address is not verified or message content was rejected.');
    }

    public static function paused(): self
    {
        return new self('SendingPausedException', 400, 'Sending is paused for this account.');
    }

    public static function unrecognizedClient(): self
    {
        return new self('UnrecognizedClientException', 403, 'The security token included in the request is invalid.');
    }
}
