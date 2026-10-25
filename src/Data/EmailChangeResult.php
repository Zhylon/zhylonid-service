<?php

namespace Zhylon\ZhylonIdService\Data;

use DateTimeImmutable;
use Zhylon\ZhylonIdService\Enums\EmailChangeStatus;

/**
 * Outcome of ZhylonIdServiceContract::changeEmail().
 *
 * ZhylonID swaps the address only after the user confirmed the link sent to the new
 * address. No status here means "changed": apps must never update their local email
 * based on this result.
 */
final readonly class EmailChangeResult
{
    public function __construct(
        public EmailChangeStatus $status,
        public ?string $changeId = null,
        public ?string $email = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    public static function pending(string $changeId, string $email, ?DateTimeImmutable $expiresAt): self
    {
        return new self(EmailChangeStatus::PendingVerification, $changeId, $email, $expiresAt);
    }

    public static function emailTaken(): self
    {
        return new self(EmailChangeStatus::EmailTaken);
    }

    public static function unavailable(): self
    {
        return new self(EmailChangeStatus::Unavailable);
    }

    public function isPending(): bool
    {
        return EmailChangeStatus::PendingVerification === $this->status;
    }
}
