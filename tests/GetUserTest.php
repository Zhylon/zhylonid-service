<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Zhylon\ZhylonIdService\Data\ZhylonIdUser;
use Zhylon\ZhylonIdService\Enums\FailureReason;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

const ZHYLON_USER_URL = 'id.zhylon.test/api/internals/users/*';

function zhylonUserResponse(array $attributes = []): array
{
    return ['data' => [
        'id'         => 'h:abc123',
        'type'       => 'users',
        'links'      => ['self' => ['href' => 'https://id.zhylon.test/api/internals/users/h:abc123']],
        'attributes' => $attributes + [
            'name'                 => 'Jane Doe',
            'email'                => 'jane@example.com',
            'email_verified'       => true,
            'pending_email_change' => null,
        ],
    ]];
}

it('returns the ZhylonID user with email_verified', function (bool $verified) {
    Http::fake(fakeToken() + [ZHYLON_USER_URL => Http::response(zhylonUserResponse(['email_verified' => $verified]), 200)]);

    $user = app(ZhylonIdServiceContract::class)->getUser('h:abc123');

    expect($user)->toBeInstanceOf(ZhylonIdUser::class)
        ->and($user->id)->toBe('h:abc123')
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->email)->toBe('jane@example.com')
        ->and($user->emailVerified)->toBe($verified)
        ->and($user->pendingEmailChange)->toBeNull();

    Http::assertSent(fn (Request $request) => 'GET' === $request->method()
        && str_ends_with($request->url(), '/api/internals/users/h%3Aabc123'));
})->with(['verified' => [true], 'unverified' => [false]]);

it('returns an open email change', function () {
    Http::fake(fakeToken() + [ZHYLON_USER_URL => Http::response(zhylonUserResponse([
        'pending_email_change' => ['new_email' => 'new@example.com', 'expires_at' => '2026-10-26T21:30:00+00:00'],
    ]), 200)]);

    $pending = app(ZhylonIdServiceContract::class)->getUser('abc123')->pendingEmailChange;

    expect($pending->newEmail)->toBe('new@example.com')
        ->and($pending->expiresAt?->format(DATE_ATOM))->toBe('2026-10-26T21:30:00+00:00');
});

it('returns null for an unknown user (JSON:API not_found)', function () {
    Http::fake(fakeToken() + [ZHYLON_USER_URL => Http::response(['errors' => [['status' => '404', 'code' => 'not_found']]], 404)]);

    expect(app(ZhylonIdServiceContract::class)->getUser('h:zzz'))->toBeNull();
});

it('never treats a non-boolean email_verified as verified', function (mixed $value) {
    Http::fake(fakeToken() + [ZHYLON_USER_URL => Http::response(zhylonUserResponse(['email_verified' => $value]), 200)]);

    expect(fn () => app(ZhylonIdServiceContract::class)->getUser('abc123'))
        ->toThrow(ZhylonIdUnavailableException::class);
})->with(['string' => ['true'], 'int' => [1], 'null' => [null]]);

it('rejects an empty zhylon id before sending', function () {
    Http::fake();

    expect(fn () => app(ZhylonIdServiceContract::class)->getUser('  '))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

dataset('get user failures', [
    'route missing (404 without not_found)' => [fn () => fakeToken() + [ZHYLON_USER_URL => Http::response('<html>Not Found', 404)], FailureReason::UnexpectedStatus],
    'connection error'                      => [fn () => fakeToken() + [ZHYLON_USER_URL => Http::failedConnection()], FailureReason::ConnectionFailed],
    '403'                                   => [fn () => fakeToken() + [ZHYLON_USER_URL => Http::response(['errors' => [['code' => 'forbidden']]], 403)], FailureReason::Unauthorized],
    '500'                                   => [fn () => fakeToken() + [ZHYLON_USER_URL => Http::response('SENSITIVE-BODY-MARKER', 500)], FailureReason::ServerError],
    'broken json'                           => [fn () => fakeToken() + [ZHYLON_USER_URL => Http::response('<html>', 200)], FailureReason::InvalidResponse],
]);

it('throws when ZhylonID is unavailable in block mode', function (Closure $fakes, FailureReason $reason) {
    Http::fake($fakes());

    try {
        app(ZhylonIdServiceContract::class)->getUser('abc123');
        $this->fail('Expected ZhylonIdUnavailableException.');
    } catch (ZhylonIdUnavailableException $e) {
        expect($e->reason)->toBe($reason)
            ->and($e->getMessage())->not->toContain('SENSITIVE-BODY-MARKER');
    }
})->with('get user failures');

it('returns null and logs a warning in allow mode', function (Closure $fakes, FailureReason $reason) {
    config(['zhylonid-service.on_failure' => 'allow']);
    Http::fake($fakes());
    Log::spy();

    expect(app(ZhylonIdServiceContract::class)->getUser('abc123'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => 'getUser' === $context['operation']
            && $reason->value === $context['reason']);
})->with('get user failures');

it('returns personal_org_uuid when ZhylonID sends it (T1.1)', function () {
    Http::fake(fakeToken() + [ZHYLON_USER_URL => Http::response(zhylonUserResponse([
        'personal_org_uuid' => '01a1d0b6-25f6-73c4-8406-60715735bdb8',
    ]), 200)]);

    expect(app(ZhylonIdServiceContract::class)->getUser('h:abc123')->personalOrgUuid)
        ->toBe('01a1d0b6-25f6-73c4-8406-60715735bdb8');
});

it('returns null for a missing or invalid personal_org_uuid', function (array $attributes) {
    Http::fake(fakeToken() + [ZHYLON_USER_URL => Http::response(zhylonUserResponse($attributes), 200)]);

    expect(app(ZhylonIdServiceContract::class)->getUser('h:abc123')->personalOrgUuid)->toBeNull();
})->with([
    'missing (ZhylonID before T1.1)' => [[]],
    'not a uuid'                     => [['personal_org_uuid' => 'h:abc123']],
    'not a string'                   => [['personal_org_uuid' => 42]],
]);

it('returns personal_org_slug when ZhylonID sends it (T17)', function () {
    Http::fake(fakeToken() + [ZHYLON_USER_URL => Http::response(zhylonUserResponse([
        'personal_org_slug' => 'jane-doe-2',
    ]), 200)]);

    expect(app(ZhylonIdServiceContract::class)->getUser('h:abc123')->personalOrgSlug)->toBe('jane-doe-2');
});

it('returns null for a missing or invalid personal_org_slug', function (array $attributes) {
    Http::fake(fakeToken() + [ZHYLON_USER_URL => Http::response(zhylonUserResponse($attributes), 200)]);

    expect(app(ZhylonIdServiceContract::class)->getUser('h:abc123')->personalOrgSlug)->toBeNull();
})->with([
    'missing (ZhylonID before T17)' => [[]],
    'uppercase'                     => [['personal_org_slug' => 'Jane-Doe']],
    'path characters'               => [['personal_org_slug' => '../admin']],
    'too long'                      => [['personal_org_slug' => str_repeat('a', 49)]],
    'not a string'                  => [['personal_org_slug' => 42]],
]);
