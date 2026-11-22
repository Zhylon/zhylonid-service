<?php

namespace Zhylon\ZhylonIdService\Contracts;

use Illuminate\Support\Collection;
use Zhylon\ZhylonIdService\Data\OrganizationMember;
use Zhylon\ZhylonIdService\Data\ZhylonIdOrganization;
use Zhylon\ZhylonIdService\Data\OrganizationSyncReport;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

/**
 * Organizations owned by ZhylonID (DECISION-018). Contract: zhylon-id docs/internal-api-organizations.md.
 *
 * Organizations are referenced by uuid, never by name or hash ID. Personal organizations never
 * appear here; use ZhylonIdUser::$personalOrgUuid / $personalOrgSlug.
 *
 * All methods follow zhylonid-service.on_failure: "block" (default) throws
 * ZhylonIdUnavailableException, "allow" returns null and logs a warning.
 */
interface ZhylonIdOrganizationsContract
{
    /**
     * GET /api/internals/organizations/{uuid}
     *
     * @throws ZhylonIdUnavailableException ZhylonID gave no trustworthy answer (on_failure=block).
     * @throws \InvalidArgumentException The uuid is not a uuid.
     *
     * @return ZhylonIdOrganization|null Null for an unknown or personal organization, or with on_failure=allow.
     */
    public function getOrganization(string $uuid): ?ZhylonIdOrganization;

    /**
     * Organizations the user owns or is a member of, without the personal organization.
     * All pages are fetched. ZhylonIdOrganization::$role is the role of this user.
     *
     * @throws ZhylonIdUnavailableException ZhylonID gave no trustworthy answer (on_failure=block).
     * @throws \InvalidArgumentException The zhylon id is empty.
     *
     * @return Collection<int, ZhylonIdOrganization>|null Null for an unknown user, or with on_failure=allow.
     */
    public function getUserOrganizations(string $zhylonId): ?Collection;

    /**
     * The owner and all members of an organization. All pages are fetched.
     *
     * Do not rely on a cached member list alone to revoke access (DECISION-020).
     *
     * @throws ZhylonIdUnavailableException ZhylonID gave no trustworthy answer (on_failure=block).
     * @throws \InvalidArgumentException The uuid is not a uuid.
     *
     * @return Collection<int, OrganizationMember>|null Null for an unknown organization, or with on_failure=allow.
     */
    public function getOrganizationMembers(string $uuid): ?Collection;

    /**
     * One-time, idempotent import of existing app organizations (POST /api/internals/organizations/sync).
     * Existing organizations are never changed; differences are reported.
     *
     * Each entry: provider (optional, defaults to zhylonid-service.sync.provider), external_id,
     * name, owner_zhylon_id, members (optional list of zhylon_id + role in the app).
     *
     * @param  array<int, array<string, mixed>>|Collection<int, array<string, mixed>>  $organizations  1–100 entries.
     *
     * @throws ZhylonIdUnavailableException ZhylonID gave no trustworthy answer (on_failure=block).
     * @throws \InvalidArgumentException Empty list, or ZhylonID rejected the document (422).
     *
     * @return OrganizationSyncReport|null Null only with on_failure=allow.
     */
    public function syncOrganizations(array|Collection $organizations, bool $dryRun): ?OrganizationSyncReport;
}
