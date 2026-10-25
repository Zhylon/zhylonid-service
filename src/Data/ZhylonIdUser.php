<?php

namespace Zhylon\ZhylonIdService\Data;

/**
 * A user as returned by GET /api/internals/users/{zhylon_id}.
 *
 * Only trust $email for account linking when $emailVerified is true: users created
 * or synced by a product start unverified and are never verified automatically.
 */
final readonly class ZhylonIdUser
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public bool $emailVerified,
        public ?PendingEmailChange $pendingEmailChange = null,
    ) {}
}
