<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Log\Events\MessageLogged;
use Zhylon\ZhylonIdService\Enums\FailureReason;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

it('reports an existing user when the lookup answers 200', function () {
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => lookupFound()]);

    expect(app(ZhylonIdServiceContract::class)->userExists('jane@example.com'))->toBeTrue();
});

it('reports a missing user when the lookup answers 404 not_found', function () {
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => lookupNotFound()]);

    expect(app(ZhylonIdServiceContract::class)->userExists('jane@example.com'))->toBeFalse();
});

dataset('failures', [
    '404 (wrong route)' => [
        fn () => fakeToken() + [ZHYLON_LOOKUP_URL => Http::response(['message' => 'SENSITIVE-BODY-MARKER'], 404)],
        FailureReason::UnexpectedStatus, 404,
    ],
    '404 route_not_found (older ZhylonID)' => [
        fn () => fakeToken() + [ZHYLON_LOOKUP_URL => Http::response(['errors' => [['status' => '404', 'code' => 'route_not_found']]], 404)],
        FailureReason::UnexpectedStatus, 404,
    ],
    'timeout' => [
        fn () => fakeToken() + [ZHYLON_LOOKUP_URL => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds')],
        FailureReason::Timeout, null,
    ],
    'connection error' => [
        fn () => fakeToken() + [ZHYLON_LOOKUP_URL => Http::failedConnection()],
        FailureReason::ConnectionFailed, null,
    ],
    '401' => [
        fn () => fakeToken() + [ZHYLON_LOOKUP_URL => Http::response(['message' => 'SENSITIVE-BODY-MARKER'], 401)],
        FailureReason::Unauthorized, 401,
    ],
    '403' => [
        fn () => fakeToken() + [ZHYLON_LOOKUP_URL => Http::response(['message' => 'SENSITIVE-BODY-MARKER'], 403)],
        FailureReason::Unauthorized, 403,
    ],
    '500' => [
        fn () => fakeToken() + [ZHYLON_LOOKUP_URL => Http::response('SENSITIVE-BODY-MARKER', 500)],
        FailureReason::ServerError, 500,
    ],
    'broken json' => [
        fn () => fakeToken() + [ZHYLON_LOOKUP_URL => Http::response('<html>SENSITIVE-BODY-MARKER', 200)],
        FailureReason::InvalidResponse, 200,
    ],
    '200 without a user resource' => [
        fn () => fakeToken() + [ZHYLON_LOOKUP_URL => Http::response(['status' => 'failed'], 200)],
        FailureReason::InvalidResponse, 200,
    ],
    'token endpoint 500' => [
        fn () => [ZHYLON_TOKEN_URL => Http::response(['secret_hint' => 'SENSITIVE-BODY-MARKER'], 500)],
        FailureReason::TokenRequestFailed, 500,
    ],
    'token endpoint timeout' => [
        fn () => [ZHYLON_TOKEN_URL => Http::failedConnection('cURL error 28: Connection timed out')],
        FailureReason::Timeout, null,
    ],
]);

it('throws ZhylonIdUnavailableException in block mode', function (Closure $fakes, FailureReason $reason, ?int $status) {
    config(['zhylonid-service.on_failure' => 'block']);
    Http::fake($fakes());

    try {
        app(ZhylonIdServiceContract::class)->userExists('jane@example.com');
        $this->fail('Expected ZhylonIdUnavailableException.');
    } catch (ZhylonIdUnavailableException $e) {
        expect($e->reason)->toBe($reason)
            ->and($e->status)->toBe($status)
            ->and($e->getMessage())->not->toContain('SENSITIVE-BODY-MARKER')
            ->and($e->getMessage())->not->toContain('jane@example.com')
            ->and($e->getMessage())->not->toContain('test-secret')
            ->and($e->getMessage())->not->toContain('fake-token');
    }
})->with('failures');

it('blocks by default when on_failure is not configured', function () {
    config(['zhylonid-service.on_failure' => null]);
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => Http::response([], 500)]);

    app(ZhylonIdServiceContract::class)->userExists('jane@example.com');
})->throws(ZhylonIdUnavailableException::class);

it('treats an unknown on_failure value as block', function () {
    config(['zhylonid-service.on_failure' => 'yolo']);
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => Http::response([], 500)]);

    app(ZhylonIdServiceContract::class)->userExists('jane@example.com');
})->throws(ZhylonIdUnavailableException::class);

it('returns false and logs a warning in allow mode', function (Closure $fakes, FailureReason $reason, ?int $status) {
    config(['zhylonid-service.on_failure' => 'allow']);
    Http::fake($fakes());
    Log::spy();

    expect(app(ZhylonIdServiceContract::class)->userExists('jane@example.com'))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'on_failure=allow')
            && 'userExists' === $context['operation']
            && $reason->value === $context['reason']
            && $status === $context['status']);
})->with('failures');

it('never logs secrets, tokens, emails or response bodies', function (Closure $fakes) {
    config(['zhylonid-service.on_failure' => 'allow']);
    Http::fake($fakes());

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    app(ZhylonIdServiceContract::class)->userExists('jane@example.com');

    $all = implode("\n", $logged);
    expect($logged)->not->toBeEmpty()
        ->and($all)->not->toContain('SENSITIVE-BODY-MARKER')
        ->and($all)->not->toContain('test-secret')
        ->and($all)->not->toContain('fake-token')
        ->and($all)->not->toContain('jane@example.com');
})->with('failures');

it('never caches a failure result', function () {
    Http::fake(fakeToken() + [
        ZHYLON_LOOKUP_URL => Http::sequence()
            ->push([], 500)
            ->push(zhylonUserBody(), 200),
    ]);

    $service = app(ZhylonIdServiceContract::class);

    expect(fn () => $service->userExists('jane@example.com'))->toThrow(ZhylonIdUnavailableException::class)
        ->and($service->userExists('jane@example.com'))->toBeTrue();
});

it('forgets the cached token after a 401 so the next call fetches a fresh one', function () {
    Http::fake([
        ZHYLON_TOKEN_URL => Http::sequence()
            ->push(['access_token' => 'stale-token'], 200)
            ->push(['access_token' => 'fresh-token'], 200),
        ZHYLON_LOOKUP_URL => Http::sequence()
            ->push([], 401)
            ->push(notFoundBody(), 404),
    ]);

    $service = app(ZhylonIdServiceContract::class);

    expect(fn () => $service->userExists('jane@example.com'))->toThrow(ZhylonIdUnavailableException::class)
        ->and($service->userExists('jane@example.com'))->toBeFalse();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'users/lookup')
        && $request->hasHeader('Authorization', 'Bearer fresh-token'));
});

it('keeps the cached token on other failures', function () {
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => Http::response([], 500)]);

    expect(fn () => app(ZhylonIdServiceContract::class)->userExists('jane@example.com'))
        ->toThrow(ZhylonIdUnavailableException::class);

    expect(Cache::get('zhylon_id_token_'.hash('sha256', 'test-client')))->toBe('fake-token');
});

it('rejects an empty or invalid email before sending any request', function (string $email) {
    Http::fake();

    expect(fn () => app(ZhylonIdServiceContract::class)->userExists($email))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['', '   ', 'not-an-email']);

it('applies the configured timeouts to requests', function () {
    config([
        'zhylonid-service.http_timeout'    => 3,
        'zhylonid-service.connect_timeout' => 1,
    ]);
    $options = [];
    Http::fake(function ($request, array $requestOptions) use (&$options) {
        $options[] = $requestOptions;

        return str_contains($request->url(), 'oauth/token')
            ? Http::response(['access_token' => 'fake-token'], 200)
            : lookupNotFound();
    });

    app(ZhylonIdServiceContract::class)->userExists('jane@example.com');

    expect($options)->toHaveCount(2);
    foreach ($options as $o) {
        expect($o['timeout'])->toBe(3)->and($o['connect_timeout'])->toBe(1);
    }
});

it('uses a short default timeout', function () {
    expect(config('zhylonid-service.http_timeout'))->toBe(5)
        ->and(config('zhylonid-service.connect_timeout'))->toBe(2)
        ->and(config('zhylonid-service.on_failure'))->toBe('block');
});

it('sends the email in the JSON:API body, never in the URL', function () {
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => lookupNotFound()]);

    app(ZhylonIdServiceContract::class)->userExists('jane@example.com');

    Http::assertSent(fn ($request) => 'POST' === $request->method()
        && str_ends_with($request->url(), '/api/internals/users/lookup')
        && ! str_contains($request->url(), 'jane')
        && $request->data() === ['data' => ['type' => 'user-lookups', 'attributes' => ['email' => 'jane@example.com']]]);
});

it('returns the ZhylonID user with email_verified from findUserByEmail', function () {
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => lookupFound('jane@example.com', false)]);

    $user = app(ZhylonIdServiceContract::class)->findUserByEmail('jane@example.com');

    expect($user->id)->toBe('h:abc123')
        ->and($user->email)->toBe('jane@example.com')
        ->and($user->emailVerified)->toBeFalse();
});

it('returns null from findUserByEmail for an unknown email', function () {
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => lookupNotFound()]);

    expect(app(ZhylonIdServiceContract::class)->findUserByEmail('jane@example.com'))->toBeNull();
});

it('returns null from findUserByEmail and logs a warning in allow mode', function () {
    config(['zhylonid-service.on_failure' => 'allow']);
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => Http::response([], 500)]);
    Log::spy();

    expect(app(ZhylonIdServiceContract::class)->findUserByEmail('jane@example.com'))->toBeNull();

    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn (string $message, array $context) => 'findUserByEmail' === $context['operation']);
});

it('does not call the legacy check-user endpoint anymore', function () {
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => lookupNotFound()]);

    app(ZhylonIdServiceContract::class)->userExists('jane@example.com');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'check-user'));
});
