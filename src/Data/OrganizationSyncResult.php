<?php

namespace Zhylon\ZhylonIdService\Data;

use Zhylon\ZhylonIdService\Enums\OrganizationSyncStatus;

/**
 * Result of one entry of POST /api/internals/organizations/sync.
 *
 * $organizationId / $organizationSlug are set for created, exists and conflict (owner_mismatch:
 * the organization the ref belongs to), otherwise null.
 */
final readonly class OrganizationSyncResult
{
    /**
     * @param  list<string>  $issues  Issue codes (owner_mismatch, duplicate_in_request, personal_organization, unknown_owner, name_differs).
     * @param  list<OrganizationSyncMemberResult>  $members
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public OrganizationSyncStatus $status,
        public ?string $organizationId,
        public ?string $organizationSlug,
        public array $issues = [],
        public array $members = [],
    ) {}

    public function hasIssue(string $code): bool
    {
        return in_array($code, $this->issues, true);
    }
}
