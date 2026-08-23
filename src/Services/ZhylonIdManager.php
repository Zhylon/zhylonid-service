<?php

namespace Zhylon\ZhylonIdService\Services;

use RuntimeException;
use Throwable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;

class ZhylonIdManager implements ZhylonIdServiceContract
{
    private readonly string $baseUrl;

    private readonly string $clientId;

    private readonly string $clientSecret;

    private readonly string $scope;

    private readonly string $userAgent;

    private readonly int $timeout;

    private readonly int $tokenTtl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('zhylonid-service.endpoint'), '/');
        $this->clientId = (string) config('zhylonid-service.client_id');
        $this->clientSecret = (string) config('zhylonid-service.client_secret');
        $this->scope = (string) config('zhylonid-service.scope', 'internal.read internal.write');
        $this->userAgent = (string) config('zhylonid-service.user_agent', config('app.name').'/1.0');
        $this->timeout = (int) config('zhylonid-service.http_timeout', 10);
        $this->tokenTtl = (int) config('zhylonid-service.token_cache_ttl', 3500);

        if ($this->baseUrl === '' || $this->clientId === '' || $this->clientSecret === '') {
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
        try {
            $response = $this->post('api/internals/check-user', [
                'email' => $email,
            ]);
        } catch (Throwable $e) {
            Log::warning('ZhylonID userExists check failed', ['message' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        return 'ok' !== $response->json('status');
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
            $response = $this->post('api/internals/providers/sync-users', [
                'users' => $usersToSync,
                'provider' => (string) config('zhylonid-service.sync.provider', config('app.name')),
            ]);
        } catch (Throwable $e) {
            Log::warning('ZhylonID user sync failed', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $users = $response->json();

        return is_array($users) ? $users : null;
    }

    protected function client(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent)
            ->acceptJson()
            ->timeout($this->timeout)
            ->withToken($this->getToken())
            ->baseUrl($this->baseUrl.'/');
    }

    /**
     * Fetch (and cache) a client-credentials access token.
     *
     * Cache key is namespaced per client id so multiple ZhylonID-connected
     * products sharing one Redis cache never collide or read each other's token.
     */
    private function getToken(): string
    {
        $cacheKey = 'zhylon_id_token_'.hash('sha256', $this->clientId);

        return Cache::remember($cacheKey, $this->tokenTtl, function () {
            // NOTE: kept as JSON body to match the original ZhylonID internal
            // implementation. If the /oauth/token endpoint is a strict RFC 6749
            // client-credentials grant, switch to Http::asForm() here instead.
            $response = Http::timeout($this->timeout)->post($this->baseUrl.'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => $this->scope,
            ]);

            if (! $response->successful()) {
                // Deliberately do NOT include $response->body() here — the
                // response could echo back request data and end up in logs.
                Log::error('ZhylonID OAuth token request failed', [
                    'status' => $response->status(),
                ]);

                throw new RuntimeException(
                    'ZhylonID OAuth token request failed (HTTP '.$response->status().').'
                );
            }

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('ZhylonID OAuth response did not contain an access token.');
            }

            return $token;
        });
    }
}
