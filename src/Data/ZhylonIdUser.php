<?php

namespace Zhylon\ZhylonIdService\Data;

/**
 * A user as returned by GET /api/internals/users/{zhylon_id}.
 *
 * Only trust $email for account linking when $emailVerified is true: users created
 * or synced by a product start unverified and are never verified automatically.
 *
 * $personalOrgUuid is the uuid of the user's personal organization, stable for the life
 * of the user; store it as zhylon_org_id. Null only if ZhylonID does not send it yet.
 */
final readonly class ZhylonIdUser
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public bool $emailVerified,
        public ?PendingEmailChange $pendingEmailChange = null,
        public ?string $personalOrgUuid = null,
    ) {}
}
