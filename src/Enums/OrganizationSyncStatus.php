<?php

namespace Zhylon\ZhylonIdService\Enums;

/**
 * Status of one entry of POST /api/internals/organizations/sync.
 */
enum OrganizationSyncStatus: string
{
    // The organization was created (dry_run: false).
    case Created = 'created';

    // The organization would be created (dry_run: true).
    case WouldCreate = 'would_create';

    // provider + external_id is already linked to an organization with the same owner. Nothing changed.
    case Exists = 'exists';

    // owner_mismatch, duplicate_in_request or personal_organization. Resolve manually in ZhylonID.
    case Conflict = 'conflict';

    // The owner has no ZhylonID account (unknown_owner). Sync the user first.
    case Skipped = 'skipped';
}
