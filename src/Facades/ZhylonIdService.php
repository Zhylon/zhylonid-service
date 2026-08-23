<?php

namespace Zhylon\ZhylonIdService\Facades;

use Illuminate\Support\Facades\Facade;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;

/**
 * @method static bool userExists(string $email)
 * @method static \Illuminate\Http\Client\Response get(string $endpoint, array $query = [])
 * @method static \Illuminate\Http\Client\Response post(string $endpoint, array $data = [])
 * @method static array<int, array<string, mixed>>|null getSyncUsers(array|\Illuminate\Support\Collection $usersToSync)
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
