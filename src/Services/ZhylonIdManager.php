<?php

namespace Zhylon\ZhylonIdService\Services;

use Throwable;
use RuntimeException;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\PendingRequest;
use Zhylon\ZhylonIdService\Enums\OnFailure;
use Zhylon\ZhylonIdService\Data\ZhylonIdUser;
use Illuminate\Http\Client\ConnectionException;
use Zhylon\ZhylonIdService\Data\EmailChangeResult;
use Zhylon\ZhylonIdService\Data\OrganizationMember;
use Zhylon\ZhylonIdService\Data\PendingEmailChange;
use Zhylon\ZhylonIdService\Data\ZhylonIdOrganization;
use Zhylon\ZhylonIdService\Data\OrganizationSyncReport;
use Zhylon\ZhylonIdService\Data\OrganizationSyncResult;
use Zhylon\ZhylonIdService\Enums\OrganizationSyncStatus;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;
use Zhylon\ZhylonIdService\Data\OrganizationSyncMemberResult;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdOrganizationsContract;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

class ZhylonIdManager implements ZhylonIdOrganizationsContract, ZhylonIdServiceContract
{
    /** page[size] for list endpoints (ZhylonID maximum). */
    private const PAGE_SIZE = 100;

    /** Safety cap for cursor pagination; hitting it is an error, never a partial list. */
    private const MAX_PAGES = 50;

    private readonly string $baseUrl;

    private readonly string $clientId;

    private readonly string $clientSecret;

    private readonly string $scope;

    private readonly string $userAgent;

    private readonly int $timeout;

    private readonly int $connectTimeout;

    private readonly int $tokenTtl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('zhylonid-service.endpoint'), '/');
        $this->clientId = (string) config('zhylonid-service.client_id');
        $this->clientSecret = (string) config('zhylonid-service.client_secret');
        $this->scope = (string) config('zhylonid-service.scope', 'internal.read internal.write');
        $this->userAgent = (string) config('zhylonid-service.user_agent', config('app.name').'/1.0');
        $this->timeout = (int) config('zhylonid-service.http_timeout', 5);
        $this->connectTimeout = (int) config('zhylonid-service.connect_timeout', 2);
        $this->tokenTtl = (int) config('zhylonid-service.token_cache_ttl', 3500);

        if ('' === $this->baseUrl || '' === $this->clientId || '' === $this->clientSecret) {
            throw new RuntimeException(
                'ZhylonID client is not configured. Set ZHYLONID_SERVICE_ENDPOINT, ZHYLONID_SERVICE_CLIENT_ID and ZHYLONID_SERVICE_CLIENT_SECRET.'
            );
        }

        if (config('zhylonid-service.require_https', true) && ! str_starts_with($this->baseUrl, 'https://')) {
            throw new RuntimeException(
                'ZHYLONID_SERVICE_ENDPOINT must use https://. Set ZHYLONID_SERVICE_REQUIRE_HTTPS=false only for local development.'
            );
        }
    }

    public function userExists(string $email): bool
    {
        $email = $this->assertEmail($email);

        try {
            return null !== $this->lookupByEmail($email);
        } catch (ZhylonIdUnavailableException $e) {
            return $this->handleFailure($e, 'userExists', false);
        }
    }

    public function findUserByEmail(string $email): ?ZhylonIdUser
    {
        $email = $this->assertEmail($email);

        try {
            return $this->lookupByEmail($email);
        } catch (ZhylonIdUnavailableException $e) {
            return $this->handleFailure($e, 'findUserByEmail', null);
        }
    }

    public function getUser(string $zhylonId): ?ZhylonIdUser
    {
        $zhylonId = $this->assertZhylonId($zhylonId);

        try {
            $response = $this->send('get', 'api/internals/users/'.rawurlencode($zhylonId), [], accept: [404]);

            if (404 === $response->status()) {
                // Only a JSON:API not_found means "unknown user". Any other 404
                // (e.g. an older ZhylonID without this route) is a failure.
                if ($this->hasErrorCode($response, 'not_found')) {
                    return null;
                }

                throw ZhylonIdUnavailableException::unexpectedStatus(404);
            }

            return $this->parseUser($response);
        } catch (ZhylonIdUnavailableException $e) {
            return $this->handleFailure($e, 'getUser', null);
        }
    }

    public function changeEmail(string $zhylonId, string $newEmail): EmailChangeResult
    {
        $zhylonId = $this->assertZhylonId($zhylonId);

        $newEmail = $this->assertEmail($newEmail);

        try {
            // Contract: zhylon-id docs/internal-api-email.md (202 Accepted on success).
            $response = $this->send(
                'patch',
                'api/internals/users/'.rawurlencode($zhylonId).'/email',
                ['data' => ['type' => 'user-email-changes', 'attributes' => ['email' => $newEmail]]],
                accept: [422],
            );

            if (422 === $response->status()) {
                return $this->emailChangeRejected($response);
            }

            $data = $response->json('data');

            if (! is_array($data)
                || ! is_string($data['id'] ?? null)
                || 'pending_verification' !== ($data['attributes']['status'] ?? null)) {
                throw ZhylonIdUnavailableException::invalidResponse($response->status());
            }

            return EmailChangeResult::pending(
                $data['id'],
                (string) ($data['attributes']['new_email'] ?? $newEmail),
                $this->parseDate($data['attributes']['expires_at'] ?? null),
            );
        } catch (ZhylonIdUnavailableException $e) {
            return $this->handleFailure($e, 'changeEmail', EmailChangeResult::unavailable());
        }
    }

    public function get(string $endpoint, array $query = []): Response
    {
        return $this->client()->get(ltrim($endpoint, '/'), $query);
    }

    public function post(string $endpoint, array $data = []): Response
    {
        return $this->client()->post(ltrim($endpoint, '/'), $data);
    }

    public function getSyncUsers(array|Collection $usersToSync): ?array
    {
        try {
            $response = $this->send('post', 'api/internals/providers/sync-users', [
                'users'    => $usersToSync instanceof Collection ? $usersToSync->values()->all() : $usersToSync,
                'provider' => (string) config('zhylonid-service.sync.provider', config('app.name')),
            ]);

            $users = $response->json();

            if (! is_array($users) || ! array_is_list($users)) {
                throw ZhylonIdUnavailableException::invalidResponse($response->status());
            }

            return $users;
        } catch (ZhylonIdUnavailableException $e) {
            return $this->handleFailure($e, 'getSyncUsers', null);
        }
    }

    public function getOrganization(string $uuid): ?ZhylonIdOrganization
    {
        $uuid = $this->assertUuid($uuid);

        try {
            $response = $this->send('get', 'api/internals/organizations/'.$uuid, [], accept: [404]);

            if ($this->isNotFound($response)) {
                return null;
            }

            $data = $response->json('data');

            return $this->parseOrganization(is_array($data) ? $data : null, $response->status());
        } catch (ZhylonIdUnavailableException $e) {
            return $this->handleFailure($e, 'getOrganization', null);
        }
    }

    public function getUserOrganizations(string $zhylonId): ?Collection
    {
        $zhylonId = $this->assertZhylonId($zhylonId);

        try {
            $items = $this->fetchAllPages('api/internals/users/'.rawurlencode($zhylonId).'/organizations');

            return null === $items ? null : $items->map(
                fn (mixed $item) => $this->parseOrganization(is_array($item) ? $item : null, 200, withRole: true)
            );
        } catch (ZhylonIdUnavailableException $e) {
            return $this->handleFailure($e, 'getUserOrganizations', null);
        }
    }

    public function getOrganizationMembers(string $uuid): ?Collection
    {
        $uuid = $this->assertUuid($uuid);

        try {
            $items = $this->fetchAllPages('api/internals/organizations/'.$uuid.'/members');

            return null === $items ? null : $items->map(function (mixed $item) {
                $attributes = is_array($item) ? ($item['attributes'] ?? null) : null;

                if (! is_array($attributes)
                    || 'organization-members' !== ($item['type'] ?? null)
                    || ! is_string($attributes['zhylon_id'] ?? null)
                    || ! is_string($attributes['role'] ?? null)) {
                    throw ZhylonIdUnavailableException::invalidResponse(200);
                }

                return new OrganizationMember($attributes['zhylon_id'], $attributes['role']);
            });
        } catch (ZhylonIdUnavailableException $e) {
            return $this->handleFailure($e, 'getOrganizationMembers', null);
        }
    }

    public function syncOrganizations(array|Collection $organizations, bool $dryRun): ?OrganizationSyncReport
    {
        $organizations = $organizations instanceof Collection ? $organizations->values()->all() : array_values($organizations);

        if ([] === $organizations) {
            throw new InvalidArgumentException('At least one organization is required.');
        }

        $provider = (string) config('zhylonid-service.sync.provider', config('app.name'));

        $organizations = array_map(function (mixed $organization) use ($provider) {
            if (! is_array($organization)) {
                throw new InvalidArgumentException('Each organization must be an array.');
            }

            return $organization + ['provider' => $provider];
        }, $organizations);

        try {
            $response = $this->send('post', 'api/internals/organizations/sync', [
                'data' => [
                    'type'       => 'organization-syncs',
                    'attributes' => ['dry_run' => $dryRun, 'organizations' => $organizations],
                ],
            ], accept: [422]);

            if (422 === $response->status()) {
                $this->rejectInvalidDocument($response, 'organization sync');
            }

            return $this->parseSyncReport($response, $dryRun);
        } catch (ZhylonIdUnavailableException $e) {
            return $this->handleFailure($e, 'syncOrganizations', null);
        }
    }

    protected function client(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent)
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withToken($this->getToken())
            ->baseUrl($this->baseUrl.'/');
    }

    /**
     * Request that turns every "no trustworthy answer" case into ZhylonIdUnavailableException.
     *
     * @param  array<int, int>  $accept  Non-2xx statuses the caller handles itself.
     *
     * @throws ZhylonIdUnavailableException
     */
    private function send(string $method, string $endpoint, array $data, array $accept = []): Response
    {
        try {
            $response = $this->client()->{$method}(ltrim($endpoint, '/'), $data);
        } catch (ConnectionException $e) {
            throw $this->isTimeout($e)
                ? ZhylonIdUnavailableException::timeout($e)
                : ZhylonIdUnavailableException::connectionFailed($e);
        }

        $status = $response->status();

        if ($response->successful() || in_array($status, $accept, true)) {
            return $response;
        }

        if (401 === $status) {
            // The cached token was revoked or expired early. Drop it so the next
            // call fetches a fresh one instead of failing until the TTL runs out.
            Cache::forget($this->tokenCacheKey());
        }

        throw match (true) {
            401 === $status, 403 === $status => ZhylonIdUnavailableException::unauthorized($status),
            429 === $status                  => ZhylonIdUnavailableException::rateLimited(
                is_numeric($retry = $response->header('Retry-After')) ? (int) $retry : null
            ),
            $status >= 500 => ZhylonIdUnavailableException::serverError($status),
            default        => ZhylonIdUnavailableException::unexpectedStatus($status),
        };
    }

    /**
     * Apply zhylonid-service.on_failure: rethrow (block) or log and return $allowValue (allow).
     *
     * Only reason and status are logged — never bodies, secrets, tokens or emails.
     *
     * @template T
     *
     * @param  T  $allowValue
     *
     * @throws ZhylonIdUnavailableException
     *
     * @return T
     */
    private function handleFailure(ZhylonIdUnavailableException $e, string $operation, mixed $allowValue): mixed
    {
        if (OnFailure::Block === OnFailure::fromConfig(config('zhylonid-service.on_failure'))) {
            throw $e;
        }

        Log::warning('ZhylonID unavailable, continuing because on_failure=allow', [
            'operation' => $operation,
            'reason'    => $e->reason->value,
            'status'    => $e->status,
        ]);

        return $allowValue;
    }

    private function emailChangeRejected(Response $response): EmailChangeResult
    {
        $errors = $response->json('errors');

        if (! is_array($errors) || [] === $errors) {
            throw ZhylonIdUnavailableException::invalidResponse($response->status());
        }

        $codes = collect($errors)
            ->map(fn ($error) => is_array($error) ? ($error['code'] ?? null) : null)
            ->filter(fn ($code) => is_string($code) && 1 === preg_match('/^[a-z0-9_]{1,64}$/', $code))
            ->values();

        if ($codes->contains('email_taken')) {
            return EmailChangeResult::emailTaken();
        }

        // Only the machine-readable code goes into the message, never title/detail.
        throw new InvalidArgumentException(
            'ZhylonID rejected the email change ('.($codes->first() ?? 'validation_error').').'
        );
    }

    /**
     * POST /api/internals/users/lookup: 200 = user, 404 not_found = no user.
     * The email goes in the body so it never ends up in access logs.
     *
     * @throws ZhylonIdUnavailableException
     */
    private function lookupByEmail(string $email): ?ZhylonIdUser
    {
        $response = $this->send(
            'post',
            'api/internals/users/lookup',
            ['data' => ['type' => 'user-lookups', 'attributes' => ['email' => $email]]],
            accept: [404],
        );

        if (404 === $response->status()) {
            // Only a JSON:API not_found means "no such user". Any other 404
            // (e.g. an older ZhylonID without this route) is a failure.
            if ($this->hasErrorCode($response, 'not_found')) {
                return null;
            }

            throw ZhylonIdUnavailableException::unexpectedStatus(404);
        }

        return $this->parseUser($response);
    }

    /**
     * Parse a JSON:API "users" resource.
     *
     * @throws ZhylonIdUnavailableException
     */
    private function parseUser(Response $response): ZhylonIdUser
    {
        $data = $response->json('data');
        $attributes = is_array($data) ? ($data['attributes'] ?? null) : null;

        // email_verified must be a real boolean: never coerce anything into "verified".
        if (! is_array($attributes)
            || 'users' !== ($data['type'] ?? null)
            || ! is_string($data['id'] ?? null)
            || ! is_string($attributes['email'] ?? null)
            || ! is_bool($attributes['email_verified'] ?? null)) {
            throw ZhylonIdUnavailableException::invalidResponse($response->status());
        }

        $pending = $attributes['pending_email_change'] ?? null;

        return new ZhylonIdUser(
            id: $data['id'],
            name: (string) ($attributes['name'] ?? ''),
            email: $attributes['email'],
            emailVerified: $attributes['email_verified'],
            pendingEmailChange: is_array($pending) && is_string($pending['new_email'] ?? null)
                ? new PendingEmailChange($pending['new_email'], $this->parseDate($pending['expires_at'] ?? null))
                : null,
            personalOrgUuid: $this->parseUuid($attributes['personal_org_uuid'] ?? null),
            personalOrgSlug: $this->parseSlug($attributes['personal_org_slug'] ?? null),
        );
    }

    /** Older ZhylonID versions do not send the field; anything that is not a slug counts as missing. */
    private function parseSlug(mixed $value): ?string
    {
        return is_string($value) && 1 === preg_match('/^[a-z0-9-]{1,48}$/', $value) ? $value : null;
    }

    /** Older ZhylonID versions do not send the field; anything that is not a uuid counts as missing. */
    private function parseUuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }

    /**
     * GET a cursor-paginated JSON:API list and return the data items of all pages.
     *
     *
     * @throws ZhylonIdUnavailableException
     *
     * @return Collection<int, mixed>|null Null for a JSON:API not_found.
     */
    private function fetchAllPages(string $endpoint): ?Collection
    {
        $items = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['page' => array_filter(['size' => self::PAGE_SIZE, 'cursor' => $cursor])];
            $response = $this->send('get', $endpoint, $query, accept: [404]);

            if ($this->isNotFound($response)) {
                // A later page cannot be "not found": the record vanished mid-way.
                if (0 !== $page) {
                    throw ZhylonIdUnavailableException::unexpectedStatus(404);
                }

                return null;
            }

            $data = $response->json('data');

            if (! is_array($data) || ! array_is_list($data)) {
                throw ZhylonIdUnavailableException::invalidResponse($response->status());
            }

            array_push($items, ...$data);

            $cursor = $response->json('meta.next_cursor');

            if (null === $cursor || '' === $cursor) {
                return collect($items);
            }

            if (! is_string($cursor)) {
                throw ZhylonIdUnavailableException::invalidResponse($response->status());
            }
        }

        throw ZhylonIdUnavailableException::invalidResponse(200);
    }

    /**
     * 404 with JSON:API not_found → true. Any other 404 (route missing, older ZhylonID) throws.
     *
     * @throws ZhylonIdUnavailableException
     */
    private function isNotFound(Response $response): bool
    {
        if (404 !== $response->status()) {
            return false;
        }

        if ($this->hasErrorCode($response, 'not_found')) {
            return true;
        }

        throw ZhylonIdUnavailableException::unexpectedStatus(404);
    }

    /**
     * Parse a JSON:API "organizations" resource.
     *
     * @param  array<string, mixed>|null  $data
     *
     * @throws ZhylonIdUnavailableException
     */
    private function parseOrganization(?array $data, int $status, bool $withRole = false): ZhylonIdOrganization
    {
        $attributes = is_array($data) ? ($data['attributes'] ?? null) : null;
        $uuid = is_array($data) ? $this->parseUuid($data['id'] ?? null) : null;

        if (! is_array($attributes)
            || 'organizations' !== ($data['type'] ?? null)
            || null === $uuid
            || ! is_string($attributes['name'] ?? null)
            || ! is_string($attributes['owner_zhylon_id'] ?? null)
            || ($withRole && ! is_string($attributes['role'] ?? null))) {
            throw ZhylonIdUnavailableException::invalidResponse($status);
        }

        return new ZhylonIdOrganization(
            uuid: $uuid,
            name: $attributes['name'],
            slug: $this->parseSlug($attributes['slug'] ?? null),
            ownerZhylonId: $attributes['owner_zhylon_id'],
            role: $withRole ? $attributes['role'] : null,
            createdAt: $this->parseDate($attributes['created_at'] ?? null),
            updatedAt: $this->parseDate($attributes['updated_at'] ?? null),
        );
    }

    /**
     * @throws ZhylonIdUnavailableException
     */
    private function parseSyncReport(Response $response, bool $dryRun): OrganizationSyncReport
    {
        $data = $response->json('data');

        if (! is_array($data) || ! array_is_list($data) || $dryRun !== $response->json('meta.dry_run')) {
            throw ZhylonIdUnavailableException::invalidResponse($response->status());
        }

        $results = array_map(function (mixed $item) use ($response) {
            $attributes = is_array($item) ? ($item['attributes'] ?? null) : null;
            $status = is_array($attributes) && is_string($attributes['status'] ?? null)
                ? OrganizationSyncStatus::tryFrom($attributes['status'])
                : null;

            if (null === $status
                || 'organization-sync-results' !== ($item['type'] ?? null)
                || ! is_string($attributes['provider'] ?? null)
                || ! is_string($attributes['external_id'] ?? null)) {
                throw ZhylonIdUnavailableException::invalidResponse($response->status());
            }

            $members = array_map(function (mixed $member) use ($response) {
                if (! is_array($member)
                    || ! is_string($member['zhylon_id'] ?? null)
                    || ! is_string($member['role'] ?? null)
                    || ! is_string($member['status'] ?? null)) {
                    throw ZhylonIdUnavailableException::invalidResponse($response->status());
                }

                return new OrganizationSyncMemberResult(
                    $member['zhylon_id'],
                    $member['role'],
                    is_string($member['mapped_role'] ?? null) ? $member['mapped_role'] : null,
                    $member['status'],
                );
            }, array_values(is_array($attributes['members'] ?? null) ? $attributes['members'] : []));

            $issues = array_values(array_filter(array_map(
                fn (mixed $issue) => is_array($issue) ? ($issue['code'] ?? null) : $issue,
                is_array($attributes['issues'] ?? null) ? $attributes['issues'] : [],
            ), 'is_string'));

            return new OrganizationSyncResult(
                provider: $attributes['provider'],
                externalId: $attributes['external_id'],
                status: $status,
                organizationId: $this->parseUuid($attributes['organization_id'] ?? null),
                organizationSlug: $this->parseSlug($attributes['organization_slug'] ?? null),
                issues: $issues,
                members: $members,
            );
        }, $data);

        $summary = $response->json('meta.summary');

        return new OrganizationSyncReport(
            dryRun: $dryRun,
            results: $results,
            summary: is_array($summary) ? array_map('intval', array_filter($summary, 'is_numeric')) : [],
        );
    }

    /**
     * 422 from ZhylonID: only the codes and pointers go into the message, never title/detail.
     *
     * @throws ZhylonIdUnavailableException|InvalidArgumentException
     */
    private function rejectInvalidDocument(Response $response, string $operation): never
    {
        $errors = $response->json('errors');

        if (! is_array($errors) || [] === $errors) {
            throw ZhylonIdUnavailableException::invalidResponse($response->status());
        }

        $problems = collect($errors)
            ->filter(fn ($error) => is_array($error))
            ->map(function (array $error) {
                $code = is_string($error['code'] ?? null) && 1 === preg_match('/^[a-z0-9_]{1,64}$/', $error['code'])
                    ? $error['code'] : 'invalid';
                $pointer = $error['source']['pointer'] ?? null;

                return is_string($pointer) && 1 === preg_match('#^[A-Za-z0-9_/.-]{1,200}$#', $pointer)
                    ? $code.' at '.$pointer : $code;
            })
            ->unique()
            ->take(10)
            ->implode(', ');

        throw new InvalidArgumentException("ZhylonID rejected the {$operation} (".('' !== $problems ? $problems : 'validation_error').').');
    }

    private function assertUuid(string $uuid): string
    {
        $uuid = trim($uuid);

        if (! Str::isUuid($uuid)) {
            throw new InvalidArgumentException('A valid organization uuid is required.');
        }

        return strtolower($uuid);
    }

    private function assertZhylonId(string $zhylonId): string
    {
        $zhylonId = trim($zhylonId);

        if ('' === $zhylonId) {
            throw new InvalidArgumentException('A ZhylonID user id is required.');
        }

        return $zhylonId;
    }

    private function hasErrorCode(Response $response, string $code): bool
    {
        $errors = $response->json('errors');

        return is_array($errors) && collect($errors)->contains(
            fn ($error) => is_array($error) && $code === ($error['code'] ?? null)
        );
    }

    private function assertEmail(string $email): string
    {
        $email = trim($email);

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
            throw new InvalidArgumentException('A valid email address is required.');
        }

        return $email;
    }

    private function isTimeout(ConnectionException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'cURL error 28') || false !== stripos($message, 'timed out');
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function tokenCacheKey(): string
    {
        return 'zhylon_id_token_'.hash('sha256', $this->clientId);
    }

    /**
     * Fetch (and cache) a client-credentials access token.
     *
     * Cache key is namespaced per client id so multiple ZhylonID-connected
     * products sharing one Redis cache never collide or read each other's token.
     * A failed request throws inside the callback, so nothing is cached.
     *
     * @throws ZhylonIdUnavailableException|ConnectionException
     */
    private function getToken(): string
    {
        return Cache::remember($this->tokenCacheKey(), $this->tokenTtl, function () {
            // NOTE: kept as JSON body to match the original ZhylonID internal
            // implementation. If the /oauth/token endpoint is a strict RFC 6749
            // client-credentials grant, switch to Http::asForm() here instead.
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->post($this->baseUrl.'/oauth/token', [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope'         => $this->scope,
                ]);

            if (! $response->successful()) {
                // Deliberately do NOT include $response->body() here — the
                // response could echo back request data and end up in logs.
                Log::error('ZhylonID OAuth token request failed', [
                    'status' => $response->status(),
                ]);

                throw ZhylonIdUnavailableException::tokenRequestFailed($response->status());
            }

            $token = $response->json('access_token');

            if (! is_string($token) || '' === $token) {
                throw ZhylonIdUnavailableException::tokenMissing($response->status());
            }

            return $token;
        });
    }
}
