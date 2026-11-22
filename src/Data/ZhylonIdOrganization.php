<?php

namespace Zhylon\ZhylonIdService\Data;

use DateTimeImmutable;

/**
 * An organization as returned by GET /api/internals/organizations/{uuid} and
 * GET /api/internals/users/{zhylon_id}/organizations.
 *
 * Store $uuid (e.g. as zhylon_org_id) to reference the organization. $slug is owned by
 * ZhylonID and globally unique (DECISION-034): use it for URLs, never generate your own.
 * It is null only if ZhylonID does not send it yet.
 *
 * $role is the role of the requested user and is only set by getUserOrganizations().
 * Personal organizations never appear here; use ZhylonIdUser::$personalOrgUuid.
 */
final readonly class ZhylonIdOrganization
{
    public function __construct(
        public string $uuid,
        public string $name,
        public ?string $slug,
        public string $ownerZhylonId,
        public ?string $role = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}
}
