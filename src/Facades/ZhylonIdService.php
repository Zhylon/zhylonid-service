<?php

namespace Zhylon\ZhylonIdService\Facades;

use Illuminate\Support\Facades\Facade;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;

/**
 * @method static bool userExists(string $email)
 * @method static \Zhylon\ZhylonIdService\Data\ZhylonIdUser|null findUserByEmail(string $email)
 * @method static \Zhylon\ZhylonIdService\Data\ZhylonIdUser|null getUser(string $zhylonId)
 * @method static \Zhylon\ZhylonIdService\Data\EmailChangeResult changeEmail(string $zhylonId, string $newEmail)
 * @method static \Illuminate\Http\Client\Response get(string $endpoint, array $query = [])
 * @method static \Illuminate\Http\Client\Response post(string $endpoint, array $data = [])
 * @method static array<int, array<string, mixed>>|null getSyncUsers(array|\Illuminate\Support\Collection $usersToSync)
 * @method static \Zhylon\ZhylonIdService\Data\ZhylonIdOrganization|null getOrganization(string $uuid)
 * @method static \Illuminate\Support\Collection<int, \Zhylon\ZhylonIdService\Data\ZhylonIdOrganization>|null getUserOrganizations(string $zhylonId)
 * @method static \Illuminate\Support\Collection<int, \Zhylon\ZhylonIdService\Data\OrganizationMember>|null getOrganizationMembers(string $uuid)
 * @method static \Zhylon\ZhylonIdService\Data\OrganizationSyncReport|null syncOrganizations(array|\Illuminate\Support\Collection $organizations, bool $dryRun)
 *
 * @see \Zhylon\ZhylonIdService\Services\ZhylonIdManager
 */
class ZhylonIdService extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ZhylonIdServiceContract::class;
    }
}
