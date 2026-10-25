<?php

namespace Zhylon\ZhylonIdService\Exceptions;

use Throwable;
use RuntimeException;
use Zhylon\ZhylonIdService\Enums\FailureReason;

/**
 * ZhylonID could not give a trustworthy answer (unreachable, timeout, auth or
 * server error, unparseable response). Callers must not treat this as
 * "user does not exist" — block the action instead (DECISION-011).
 *
 * Messages never contain response bodies, secrets, tokens or email addresses.
 */
class ZhylonIdUnavailableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly FailureReason $reason,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
        // Seconds from the Retry-After header, only set for rate limits.
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function timeout(?Throwable $previous = null): self
    {
        return new self('ZhylonID request timed out.', FailureReason::Timeout, null, $previous);
    }

    public static function connectionFailed(?Throwable $previous = null): self
    {
        return new self('ZhylonID could not be reached.', FailureReason::ConnectionFailed, null, $previous);
    }

    public static function unauthorized(int $status): self
    {
        return new self("ZhylonID rejected the client credentials (HTTP {$status}).", FailureReason::Unauthorized, $status);
    }

    public static function serverError(int $status): self
    {
        return new self("ZhylonID returned a server error (HTTP {$status}).", FailureReason::ServerError, $status);
    }

    public static function rateLimited(?int $retryAfter): self
    {
        return new self('ZhylonID rate limit reached (HTTP 429).', FailureReason::RateLimited, 429, null, $retryAfter);
    }

    public static function unexpectedStatus(int $status): self
    {
        return new self("ZhylonID returned an unexpected status (HTTP {$status}).", FailureReason::UnexpectedStatus, $status);
    }

    public static function invalidResponse(int $status): self
    {
        return new self("ZhylonID returned an invalid response (HTTP {$status}).", FailureReason::InvalidResponse, $status);
    }

    public static function tokenRequestFailed(int $status): self
    {
        return new self("ZhylonID OAuth token request failed (HTTP {$status}).", FailureReason::TokenRequestFailed, $status);
    }

    public static function tokenMissing(int $status): self
    {
        return new self('ZhylonID OAuth response did not contain an access token.', FailureReason::InvalidResponse, $status);
    }
}
