<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Log\Events\MessageLogged;
use Zhylon\ZhylonIdService\Data\ZhylonIdUser;
use Zhylon\ZhylonIdService\Enums\FailureReason;
use Zhylon\ZhylonIdService\Data\GithubResolution;
use Zhylon\ZhylonIdService\Facades\ZhylonIdService;
use Zhylon\ZhylonIdService\Enums\GithubResolutionStatus;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdGithubContract;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;
use Zhylon\ZhylonIdService\Exceptions\GithubResolveException;
use Zhylon\ZhylonIdService\Exceptions\GithubUnavailableException;
use Zhylon\ZhylonIdService\Exceptions\GithubTokenInvalidException;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;
use Zhylon\ZhylonIdService\Exceptions\GithubEmailUnverifiedException;

const GITHUB_RESOLVE_URL = 'id.zhylon.test/api/internals/users/github/resolve';
const GITHUB_TOKEN = 'gho_SECRET-GITHUB-TOKEN-MARKER';

function githubResolved(string $status, bool $created = false, array $attributes = []): array
{
    return [
        'data' => [
            'id'         => 'h:Xb9kPq',
            'type'       => 'users',
            'links'      => ['self' => ['href' => 'https://id.zhylon.test/api/internals/users/h:Xb9kPq']],
            'attributes' => $attributes + [
                'name'                 => 'Octo Cat',
                'email'                => 'octo@example.com',
                'email_verified'       => true,
                'personal_org_uuid'    => '01a1d0b6-25f6-73c4-8406-60715735bdb8',
                'personal_org_slug'    => 'octo-cat',
                'pending_email_change' => null,
            ],
        ],
        'meta' => ['status' => $status, 'created' => $created],
    ];
}

function githubError(int $status, string $code): array
{
    return [GITHUB_RESOLVE_URL => Http::response(['errors' => [[
        'status' => (string) $status,
        'code'   => $code,
        'title'  => 'SENSITIVE-BODY-MARKER',
        'source' => ['pointer' => '/data/attributes/access_token'],
    ]]], $status)];
}

function resolveGithub(string $token = GITHUB_TOKEN): ?GithubResolution
{
    return app(ZhylonIdGithubContract::class)->resolveGithubUser($token);
}

it('resolves a user for existing, linked and created', function (string $value, bool $created, GithubResolutionStatus $status) {
    Http::fake(fakeToken() + [GITHUB_RESOLVE_URL => Http::response(githubResolved($value, $created), 200)]);

    $resolution = resolveGithub();

    expect($resolution)->toBeInstanceOf(GithubResolution::class)
        ->and($resolution->status)->toBe($status)
        ->and($resolution->created)->toBe($created)
        ->and($resolution->canSignIn())->toBeTrue()
        ->and($resolution->requiresLogin())->toBeFalse()
        ->and($resolution->user)->toBeInstanceOf(ZhylonIdUser::class)
        ->and($resolution->user->id)->toBe('h:Xb9kPq')
        ->and($resolution->user->name)->toBe('Octo Cat')
        ->and($resolution->user->email)->toBe('octo@example.com')
        ->and($resolution->user->emailVerified)->toBeTrue()
        ->and($resolution->user->personalOrgUuid)->toBe('01a1d0b6-25f6-73c4-8406-60715735bdb8')
        ->and($resolution->user->personalOrgSlug)->toBe('octo-cat')
        ->and($resolution->user->pendingEmailChange)->toBeNull();
})->with([
    'existing' => ['existing', false, GithubResolutionStatus::Existing],
    'linked'   => ['linked', false, GithubResolutionStatus::Linked],
    'created'  => ['created', true, GithubResolutionStatus::Created],
]);

it('returns requires_login without a user', function () {
    Http::fake(fakeToken() + [GITHUB_RESOLVE_URL => Http::response(['data' => null, 'meta' => ['status' => 'requires_login', 'created' => false]], 200)]);

    $resolution = resolveGithub();

    expect($resolution->status)->toBe(GithubResolutionStatus::RequiresLogin)
        ->and($resolution->created)->toBeFalse()
        ->and($resolution->user)->toBeNull()
        ->and($resolution->canSignIn())->toBeFalse()
        ->and($resolution->requiresLogin())->toBeTrue();
});

it('sends only the access token as a JSON:API document', function () {
    Http::fake(fakeToken() + [GITHUB_RESOLVE_URL => Http::response(githubResolved('existing'), 200)]);

    resolveGithub('  '.GITHUB_TOKEN.' ');

    Http::assertSent(fn (Request $request) => 'POST' === $request->method()
        && 'https://id.zhylon.test/api/internals/users/github/resolve' === $request->url()
        && $request->hasHeader('Authorization', 'Bearer fake-token')
        && $request->data() === ['data' => ['type' => 'github-resolutions', 'attributes' => ['access_token' => GITHUB_TOKEN]]]);
});

it('is available on the facade and as the same instance', function () {
    Http::fake(fakeToken() + [GITHUB_RESOLVE_URL => Http::response(githubResolved('linked'), 200)]);

    expect(ZhylonIdService::resolveGithubUser(GITHUB_TOKEN)->status)->toBe(GithubResolutionStatus::Linked)
        ->and(app(ZhylonIdGithubContract::class))->toBe(app(ZhylonIdServiceContract::class));
});

it('rejects an empty token before sending', function () {
    Http::fake();

    expect(fn () => resolveGithub('   '))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

dataset('github errors', [
    'github_token_invalid (422)'    => [fn () => githubError(422, 'github_token_invalid'), GithubTokenInvalidException::class, 'github_token_invalid'],
    'github_email_unverified (422)' => [fn () => githubError(422, 'github_email_unverified'), GithubEmailUnverifiedException::class, 'github_email_unverified'],
    'github_unavailable (503)'      => [fn () => githubError(503, 'github_unavailable'), GithubUnavailableException::class, 'github_unavailable'],
]);

it('maps the GitHub error codes to their own exceptions', function (Closure $fakes, string $exception, string $code) {
    Http::fake(fakeToken() + $fakes());

    try {
        resolveGithub();
        $this->fail("Expected {$exception}.");
    } catch (GithubResolveException $e) {
        expect($e)->toBeInstanceOf($exception)
            ->and($e)->not->toBeInstanceOf(ZhylonIdUnavailableException::class)
            ->and($e->errorCode())->toBe($code)
            ->and($e->getMessage())->not->toContain('SENSITIVE-BODY-MARKER')
            ->and($e->getMessage())->not->toContain(GITHUB_TOKEN);
    }
})->with('github errors');

it('throws the GitHub exceptions with on_failure=allow too', function (Closure $fakes, string $exception) {
    config(['zhylonid-service.on_failure' => 'allow']);
    Http::fake(fakeToken() + $fakes());

    expect(fn () => resolveGithub())->toThrow($exception);
})->with('github errors');

dataset('github resolve failures', [
    'connection error'                     => [fn () => [GITHUB_RESOLVE_URL => Http::failedConnection()], FailureReason::ConnectionFailed, null],
    'timeout'                              => [fn () => [GITHUB_RESOLVE_URL => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds')], FailureReason::Timeout, null],
    '401'                                  => [fn () => [GITHUB_RESOLVE_URL => Http::response(['errors' => [['code' => 'unauthenticated']]], 401)], FailureReason::Unauthorized, 401],
    '403'                                  => [fn () => [GITHUB_RESOLVE_URL => Http::response(['errors' => [['code' => 'forbidden']]], 403)], FailureReason::Unauthorized, 403],
    '429'                                  => [fn () => [GITHUB_RESOLVE_URL => Http::response(['errors' => [['code' => 'rate_limited']]], 429)], FailureReason::RateLimited, 429],
    '500'                                  => [fn () => [GITHUB_RESOLVE_URL => Http::response('SENSITIVE-BODY-MARKER', 500)], FailureReason::ServerError, 500],
    '503 without github_unavailable'       => [fn () => [GITHUB_RESOLVE_URL => Http::response('<html>Maintenance', 503)], FailureReason::ServerError, 503],
    '404 route_not_found (older ZhylonID)' => [fn () => [GITHUB_RESOLVE_URL => Http::response(['errors' => [['code' => 'route_not_found']]], 404)], FailureReason::UnexpectedStatus, 404],
    '422 validation_error'                 => [fn () => githubError(422, 'validation_error'), FailureReason::UnexpectedStatus, 422],
    '422 unknown code'                     => [fn () => githubError(422, 'something_new'), FailureReason::UnexpectedStatus, 422],
    '422 without errors'                   => [fn () => [GITHUB_RESOLVE_URL => Http::response('<html>', 422)], FailureReason::UnexpectedStatus, 422],
    '400'                                  => [fn () => [GITHUB_RESOLVE_URL => Http::response([], 400)], FailureReason::UnexpectedStatus, 400],
    'broken json'                          => [fn () => [GITHUB_RESOLVE_URL => Http::response('<html>SENSITIVE-BODY-MARKER', 200)], FailureReason::InvalidResponse, 200],
    'unknown status'                       => [fn () => [GITHUB_RESOLVE_URL => Http::response(githubResolved('merged'), 200)], FailureReason::InvalidResponse, 200],
    'missing meta'                         => [fn () => [GITHUB_RESOLVE_URL => Http::response(['data' => githubResolved('existing')['data']], 200)], FailureReason::InvalidResponse, 200],
    'created flag not a bool'              => [fn () => [GITHUB_RESOLVE_URL => Http::response(['meta' => ['status' => 'created', 'created' => 1]] + githubResolved('created', true), 200)], FailureReason::InvalidResponse, 200],
    'created without created flag'         => [fn () => [GITHUB_RESOLVE_URL => Http::response(githubResolved('created', false), 200)], FailureReason::InvalidResponse, 200],
    'existing with created flag'           => [fn () => [GITHUB_RESOLVE_URL => Http::response(githubResolved('existing', true), 200)], FailureReason::InvalidResponse, 200],
    'linked without user'                  => [fn () => [GITHUB_RESOLVE_URL => Http::response(['data' => null, 'meta' => ['status' => 'linked', 'created' => false]], 200)], FailureReason::InvalidResponse, 200],
    'user with non-bool verified'          => [fn () => [GITHUB_RESOLVE_URL => Http::response(githubResolved('existing', false, ['email_verified' => 'true']), 200)], FailureReason::InvalidResponse, 200],
    'requires_login with user'             => [fn () => [GITHUB_RESOLVE_URL => Http::response(['meta' => ['status' => 'requires_login', 'created' => false]] + githubResolved('requires_login'), 200)], FailureReason::InvalidResponse, 200],
    'token endpoint 500'                   => [fn () => [ZHYLON_TOKEN_URL => Http::response('SENSITIVE-BODY-MARKER', 500)], FailureReason::TokenRequestFailed, 500],
]);

it('throws ZhylonIdUnavailableException in block mode', function (Closure $fakes, FailureReason $reason, ?int $status) {
    config(['zhylonid-service.on_failure' => 'block']);
    Http::fake($fakes() + fakeToken());

    try {
        resolveGithub();
        $this->fail('Expected ZhylonIdUnavailableException.');
    } catch (ZhylonIdUnavailableException $e) {
        expect($e->reason)->toBe($reason)
            ->and($e->status)->toBe($status)
            ->and($e->getMessage())->not->toContain('SENSITIVE-BODY-MARKER')
            ->and($e->getMessage())->not->toContain(GITHUB_TOKEN);
    }
})->with('github resolve failures');

it('returns null and logs a warning without the token in allow mode', function (Closure $fakes, FailureReason $reason, ?int $status) {
    config(['zhylonid-service.on_failure' => 'allow']);
    Http::fake($fakes() + fakeToken());

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    expect(resolveGithub())->toBeNull();

    expect(implode("\n", $logged))
        ->toContain('resolveGithubUser')
        ->toContain($reason->value)
        ->not->toContain(GITHUB_TOKEN)
        ->not->toContain('SENSITIVE-BODY-MARKER');
})->with('github resolve failures');

it('never logs on success', function () {
    Log::spy();
    Http::fake(fakeToken() + [GITHUB_RESOLVE_URL => Http::response(githubResolved('existing'), 200)]);

    resolveGithub();

    Log::shouldNotHaveReceived('warning');
});
