<?php

namespace Zhylon\ZhylonIdService\Data;

/**
 * A member of an organization (GET /api/internals/organizations/{uuid}/members).
 *
 * $role is the ZhylonID organization role (owner, admin, member; organizations created
 * before DECISION-016 may still contain other values). Name and email come from getUser().
 */
final readonly class OrganizationMember
{
    public function __construct(
        public string $zhylonId,
        public string $role,
    ) {}
}
