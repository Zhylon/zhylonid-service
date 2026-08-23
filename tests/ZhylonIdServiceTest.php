<?php

use Illuminate\Support\Facades\Http;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;

it('reports a user as existing when the API returns a non-ok status', function () {
    Http::fake([
        'id.zhylon.test/oauth/token' => Http::response(['access_token' => 'fake-token'], 200),
        'id.zhylon.test/api/internals/check-user' => Http::response(['status' => 'exists'], 200),
    ]);

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
});

it('returns null from getSyncUsers instead of throwing when the token request fails', function () {
    Http::fake([
        'id.zhylon.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    $service = app(ZhylonIdServiceContract::class);

    expect($service->getSyncUsers([['name' => 'Jane', 'email' => 'jane@example.com']]))->toBeNull();
});

it('throws a config exception naming the actual env vars when config is missing', function () {
    config(['zhylonid-service.endpoint' => null]);

    expect(fn () => app()->make(\Zhylon\ZhylonIdService\Services\ZhylonIdManager::class))
        ->toThrow(RuntimeException::class, 'Set ZHYLONID_SERVICE_ENDPOINT, ZHYLONID_SERVICE_CLIENT_ID and ZHYLONID_SERVICE_CLIENT_SECRET.');
});

it('refuses a non-https endpoint and names the actual env var to disable the check', function () {
    config(['zhylonid-service.endpoint' => 'http://id.zhylon.test']);

    expect(fn () => app()->make(\Zhylon\ZhylonIdService\Services\ZhylonIdManager::class))
        ->toThrow(RuntimeException::class, 'ZHYLONID_SERVICE_ENDPOINT must use https://. Set ZHYLONID_SERVICE_REQUIRE_HTTPS=false only for local development.');
});
