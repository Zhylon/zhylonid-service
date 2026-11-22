<?php

namespace Zhylon\ZhylonIdService\Data;

use Zhylon\ZhylonIdService\Enums\OrganizationSyncStatus;

/**
 * Report of POST /api/internals/organizations/sync.
 *
 * Recommended flow: run with dryRun=true, sync missing users and add role mappings until
 * isClean() is true, then run with dryRun=false.
 */
final readonly class OrganizationSyncReport
{
    /**
     * @param  list<OrganizationSyncResult>  $results
     * @param  array<string, int>  $summary  As sent by ZhylonID (created, would_create, exists, conflict, skipped, unknown_user, unknown_role).
     */
    public function __construct(
        public bool $dryRun,
        public array $results,
        public array $summary = [],
    ) {}

    /**
     * No conflicts, no skipped organizations, no unknown or unmapped members.
     */
    public function isClean(): bool
    {
        foreach ($this->results as $result) {
            if (in_array($result->status, [OrganizationSyncStatus::Conflict, OrganizationSyncStatus::Skipped], true)) {
                return false;
            }

            foreach ($result->members as $member) {
                if (in_array($member->status, ['unknown_user', 'unknown_role'], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function resultFor(string $provider, string|int $externalId): ?OrganizationSyncResult
    {
        foreach ($this->results as $result) {
            if ($result->provider === $provider && $result->externalId === (string) $externalId) {
                return $result;
            }
        }

        return null;
    }

    /**
     * The ZhylonID organization uuid for an app organization, or null if it has none (yet).
     */
    public function organizationIdFor(string $provider, string|int $externalId): ?string
    {
        return $this->resultFor($provider, $externalId)?->organizationId;
    }
}
