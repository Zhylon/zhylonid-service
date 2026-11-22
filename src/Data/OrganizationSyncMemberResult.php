<?php

namespace Zhylon\ZhylonIdService\Data;

/**
 * Result for one member of one sync entry.
 *
 * $status is kept as the raw string ZhylonID sends (added, would_add, owner, unknown_user,
 * unknown_role, duplicate, member, role_differs, not_member), so a new value never breaks parsing.
 */
final readonly class OrganizationSyncMemberResult
{
    public function __construct(
        public string $zhylonId,
        public string $role,
        public ?string $mappedRole,
        public string $status,
    ) {}
}
