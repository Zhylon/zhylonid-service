<?php

use Illuminate\Support\Facades\Http;
use Zhylon\ZhylonIdService\Services\ZhylonIdManager;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

it('reports a user as existing when the lookup returns the user', function () {
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => lookupFound()]);

    $service = app(ZhylonIdServiceContract::class);

    expect($service->userExists('someone@example.com'))->toBeTrue();
});

it('never leaks the token endpoint response body on failure', function () {
    Http::fake([
        'id.zhylon.test/oauth/token' => Http::response(['error' => 'invalid_client', 'secret_hint' => 'nope'], 401),
    ]);

    $service = app(ZhylonIdServiceContract::class);

    expect(fn () => $service->post('api/internals/check-user', ['email' => 'x@example.com']))
        ->toThrow(RuntimeException::class, 'ZhylonID OAuth token request failed (HTTP 401).');

    expect(fn () => $service->post('api/internals/check-user', ['email' => 'x@example.com']))
        ->toThrow(ZhylonIdUnavailableException::class);
});

it('throws from getSyncUsers when the token request fails in block mode', function () {
    Http::fake([
        'id.zhylon.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    $service = app(ZhylonIdServiceContract::class);

    expect(fn () => $service->getSyncUsers([['name' => 'Jane', 'email' => 'jane@example.com']]))
        ->toThrow(ZhylonIdUnavailableException::class);
});

it('returns null from getSyncUsers when the token request fails in allow mode', function () {
    config(['zhylonid-service.on_failure' => 'allow']);
    Http::fake([
        'id.zhylon.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    $service = app(ZhylonIdServiceContract::class);

    expect($service->getSyncUsers([['name' => 'Jane', 'email' => 'jane@example.com']]))->toBeNull();
});

it('throws from getSyncUsers when the response is not a list', function () {
    Http::fake([
        'id.zhylon.test/oauth/token'                        => Http::response(['access_token' => 'fake-token'], 200),
        'id.zhylon.test/api/internals/providers/sync-users' => Http::response('<html>', 200),
    ]);

    expect(fn () => app(ZhylonIdServiceContract::class)->getSyncUsers([['name' => 'Jane', 'email' => 'jane@example.com']]))
        ->toThrow(ZhylonIdUnavailableException::class);
});

it('throws a config exception naming the actual env vars when config is missing', function () {
    config(['zhylonid-service.endpoint' => null]);

    expect(fn () => app()->make(ZhylonIdManager::class))
        ->toThrow(RuntimeException::class, 'Set ZHYLONID_SERVICE_ENDPOINT, ZHYLONID_SERVICE_CLIENT_ID and ZHYLONID_SERVICE_CLIENT_SECRET.');
});

it('refuses a non-https endpoint and names the actual env var to disable the check', function () {
    config(['zhylonid-service.endpoint' => 'http://id.zhylon.test']);

    expect(fn () => app()->make(ZhylonIdManager::class))
        ->toThrow(RuntimeException::class, 'ZHYLONID_SERVICE_ENDPOINT must use https://. Set ZHYLONID_SERVICE_REQUIRE_HTTPS=false only for local development.');
});
