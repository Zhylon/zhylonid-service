<?php

namespace Zhylon\ZhylonIdService\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Http\Client\Response;
use Zhylon\ZhylonIdService\Data\ZhylonIdUser;
use Zhylon\ZhylonIdService\Data\EmailChangeResult;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

/**
 * Methods whose result drives a decision (userExists, findUserByEmail, getUser,
 * changeEmail, getSyncUsers) follow zhylonid-service.on_failure: "block" (default)
 * throws ZhylonIdUnavailableException, "allow" returns the negative result and logs
 * a warning.
 */
interface ZhylonIdServiceContract
{
    /**
     * Check whether a ZhylonID account already exists for this email
     * (POST /api/internals/users/lookup).
     *
     * true = exists, false = does not exist (or ZhylonID unavailable with on_failure=allow).
     *
     * @throws ZhylonIdUnavailableException ZhylonID gave no trustworthy answer (on_failure=block).
     * @throws \InvalidArgumentException The email is empty or invalid.
     */
    public function userExists(string $email): bool;

    /**
     * Like userExists(), but returns the ZhylonID user (with emailVerified).
     *
     * Only trust the email for account linking when emailVerified is true.
     *
     * @throws ZhylonIdUnavailableException ZhylonID gave no trustworthy answer (on_failure=block).
     * @throws \InvalidArgumentException The email is empty or invalid.
     *
     * @return ZhylonIdUser|null Null if no account exists, or when ZhylonID is unavailable with on_failure=allow.
     */
    public function findUserByEmail(string $email): ?ZhylonIdUser;

    /**
     * Look up a ZhylonID user, including whether the owner verified the email.
     *
     * Only trust the email for account linking when emailVerified is true.
     *
     * @throws ZhylonIdUnavailableException ZhylonID gave no trustworthy answer (on_failure=block).
     * @throws \InvalidArgumentException The zhylon id is empty.
     *
     * @return ZhylonIdUser|null Null for an unknown user, or when ZhylonID is unavailable with on_failure=allow.
     */
    public function getUser(string $zhylonId): ?ZhylonIdUser;

    /**
     * Ask ZhylonID to change a user's email. ZhylonID checks uniqueness, sends a
     * confirmation link to the new address and swaps it only after confirmation.
     *
     * Apps must NEVER change their local email before or because of this call.
     *
     * @throws ZhylonIdUnavailableException ZhylonID gave no trustworthy answer (on_failure=block).
     * @throws \InvalidArgumentException Invalid input, or ZhylonID rejected the address for another reason.
     */
    public function changeEmail(string $zhylonId, string $newEmail): EmailChangeResult;

    /**
     * Authenticated GET against the ZhylonID internal API (raw, no on_failure handling).
     */
    public function get(string $endpoint, array $query = []): Response;

    /**
     * Authenticated POST against the ZhylonID internal API (raw, no on_failure handling).
     */
    public function post(string $endpoint, array $data = []): Response;

    /**
     * Push a batch of local users to ZhylonID for ecosystem-wide sync.
     *
     * Note: ZhylonID returns the EXISTING account for an email it already knows.
     * Only push users you checked with userExists() first.
     *
     * @throws ZhylonIdUnavailableException ZhylonID gave no trustworthy answer (on_failure=block).
     *
     * @return array<int, array<string, mixed>>|null Null only with on_failure=allow.
     */
    public function getSyncUsers(array|Collection $usersToSync): ?array;
}
