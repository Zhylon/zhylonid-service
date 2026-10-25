<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Zhylon\ZhylonIdService\Enums\FailureReason;
use Zhylon\ZhylonIdService\Data\EmailChangeResult;
use Zhylon\ZhylonIdService\Enums\EmailChangeStatus;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

const ZHYLON_EMAIL_CHANGE_URL = 'id.zhylon.test/api/internals/users/*/email';

function emailChangeCreated(): array
{
    return [
        'data' => [
            'id'         => 'h:abc123',
            'type'       => 'user-email-changes',
            'attributes' => [
                'status'     => 'pending_verification',
                'new_email'  => 'new@example.com',
                'expires_at' => '2026-10-26T12:00:00+00:00',
            ],
        ],
    ];
}

it('starts a pending email change and returns pending_verification', function () {
    Http::fake(fakeToken() + [ZHYLON_EMAIL_CHANGE_URL => Http::response(emailChangeCreated(), 202)]);

    $result = app(ZhylonIdServiceContract::class)->changeEmail('abc123', 'new@example.com');

    expect($result)->toBeInstanceOf(EmailChangeResult::class)
        ->and($result->status)->toBe(EmailChangeStatus::PendingVerification)
        ->and($result->isPending())->toBeTrue()
        ->and($result->changeId)->toBe('h:abc123')
        ->and($result->email)->toBe('new@example.com')
        ->and($result->expiresAt?->format(DATE_ATOM))->toBe('2026-10-26T12:00:00+00:00');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/internals/users/abc123/email')
        && 'PATCH' === $request->method()
        && $request->data() === ['data' => ['type' => 'user-email-changes', 'attributes' => ['email' => 'new@example.com']]]);
});

it('url-encodes the zhylon id in the path', function () {
    Http::fake(fakeToken() + ['id.zhylon.test/api/internals/users/*' => Http::response(emailChangeCreated(), 202)]);

    app(ZhylonIdServiceContract::class)->changeEmail('h:abc123', 'new@example.com');
    app(ZhylonIdServiceContract::class)->changeEmail('a/b', 'new@example.com');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/users/h%3Aabc123/email'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/users/a%2Fb/email'));
});

it('returns email_taken when ZhylonID reports the address as already in use', function () {
    Http::fake(fakeToken() + [ZHYLON_EMAIL_CHANGE_URL => Http::response(['errors' => [[
        'status' => '422',
        'code'   => 'email_taken',
        'title'  => 'Unprocessable Entity',
        'detail' => 'The email has already been taken.',
        'source' => ['pointer' => '/data/attributes/email'],
    ]]], 422)]);

    $result = app(ZhylonIdServiceContract::class)->changeEmail('abc123', 'taken@example.com');

    expect($result->status)->toBe(EmailChangeStatus::EmailTaken)
        ->and($result->isPending())->toBeFalse();
});

it('throws InvalidArgumentException for other validation errors without leaking the body', function () {
    Http::fake(fakeToken() + [ZHYLON_EMAIL_CHANGE_URL => Http::response(['errors' => [[
        'status' => '422',
        'code'   => 'email_unchanged',
        'title'  => 'Unprocessable Entity',
        'detail' => 'SENSITIVE-BODY-MARKER',
    ]]], 422)]);

    try {
        app(ZhylonIdServiceContract::class)->changeEmail('abc123', 'same@example.com');
        $this->fail('Expected InvalidArgumentException.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('email_unchanged')
            ->and($e->getMessage())->not->toContain('SENSITIVE-BODY-MARKER');
    }
});

it('rejects an invalid new email or empty zhylon id before sending', function (string $id, string $email) {
    Http::fake();

    expect(fn () => app(ZhylonIdServiceContract::class)->changeEmail($id, $email))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    ['abc123', 'not-an-email'],
    ['abc123', ''],
    ['', 'new@example.com'],
]);

dataset('email change failures', [
    'connection error' => [fn () => fakeToken() + [ZHYLON_EMAIL_CHANGE_URL => Http::failedConnection()], FailureReason::ConnectionFailed],
    'timeout'          => [fn () => fakeToken() + [ZHYLON_EMAIL_CHANGE_URL => Http::failedConnection('cURL error 28: Operation timed out')], FailureReason::Timeout],
    '500'              => [fn () => fakeToken() + [ZHYLON_EMAIL_CHANGE_URL => Http::response('SENSITIVE-BODY-MARKER', 500)], FailureReason::ServerError],
    '404'              => [fn () => fakeToken() + [ZHYLON_EMAIL_CHANGE_URL => Http::response(['errors' => [['status' => '404', 'code' => 'not_found']]], 404)], FailureReason::UnexpectedStatus],
    'broken json'      => [fn () => fakeToken() + [ZHYLON_EMAIL_CHANGE_URL => Http::response('<html>', 202)], FailureReason::InvalidResponse],
    '429'              => [fn () => fakeToken() + [ZHYLON_EMAIL_CHANGE_URL => Http::response(['errors' => [['status' => '429', 'code' => 'rate_limited']]], 429, ['Retry-After' => '120'])], FailureReason::RateLimited],
]);

it('throws when ZhylonID is unavailable in block mode', function (Closure $fakes, FailureReason $reason) {
    config(['zhylonid-service.on_failure' => 'block']);
    Http::fake($fakes());

    try {
        app(ZhylonIdServiceContract::class)->changeEmail('abc123', 'new@example.com');
        $this->fail('Expected ZhylonIdUnavailableException.');
    } catch (ZhylonIdUnavailableException $e) {
        expect($e->reason)->toBe($reason)
            ->and($e->getMessage())->not->toContain('SENSITIVE-BODY-MARKER');
    }
})->with('email change failures');

it('returns unavailable and logs a warning in allow mode', function (Closure $fakes, FailureReason $reason) {
    config(['zhylonid-service.on_failure' => 'allow']);
    Http::fake($fakes());
    Log::spy();

    $result = app(ZhylonIdServiceContract::class)->changeEmail('abc123', 'new@example.com');

    expect($result->status)->toBe(EmailChangeStatus::Unavailable)
        ->and($result->isPending())->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => 'changeEmail' === $context['operation']
            && $reason->value === $context['reason']
            && ! str_contains(json_encode($context), 'new@example.com'));
})->with('email change failures');

it('exposes Retry-After when ZhylonID rate-limits the email change', function () {
    Http::fake(fakeToken() + [ZHYLON_EMAIL_CHANGE_URL => Http::response(
        ['errors' => [['status' => '429', 'code' => 'rate_limited']]], 429, ['Retry-After' => '120'],
    )]);

    try {
        app(ZhylonIdServiceContract::class)->changeEmail('abc123', 'new@example.com');
        $this->fail('Expected ZhylonIdUnavailableException.');
    } catch (ZhylonIdUnavailableException $e) {
        expect($e->reason)->toBe(FailureReason::RateLimited)
            ->and($e->status)->toBe(429)
            ->and($e->retryAfter)->toBe(120);
    }
});

it('reports the server validation code for an unchanged address', function () {
    Http::fake(fakeToken() + [ZHYLON_EMAIL_CHANGE_URL => Http::response(['errors' => [[
        'status' => '422', 'code' => 'invalid', 'detail' => 'SENSITIVE-BODY-MARKER', 'source' => ['pointer' => '/data/attributes/email'],
    ]]], 422)]);

    expect(fn () => app(ZhylonIdServiceContract::class)->changeEmail('abc123', 'new@example.com'))
        ->toThrow(InvalidArgumentException::class, 'ZhylonID rejected the email change (invalid).');
});
