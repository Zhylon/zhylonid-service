<?php

namespace Zhylon\ZhylonIdService\Data;

use DateTimeImmutable;

/**
 * An open, unconfirmed email change in ZhylonID.
 */
final readonly class PendingEmailChange
{
    public function __construct(
        public string $newEmail,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}
}
