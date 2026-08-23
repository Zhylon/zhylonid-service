<?php

namespace Zhylon\ZhylonIdService\Contracts;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

interface ZhylonIdServiceContract
{
    /**
     * Check whether a ZhylonID account already exists for this email.
     */
    public function userExists(string $email): bool;

    /**
     * Authenticated GET against the ZhylonID internal API.
     */
    public function get(string $endpoint, array $query = []): Response;

    /**
     * Authenticated POST against the ZhylonID internal API.
     */
    public function post(string $endpoint, array $data = []): Response;

    /**
     * Push a batch of local users to ZhylonID for ecosystem-wide sync.
     *
     * @return array<int, array<string, mixed>>|null Null on failure.
     */
    public function getSyncUsers(array|Collection $usersToSync): ?array;
}
