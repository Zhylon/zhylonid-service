<?php

use Illuminate\Support\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Zhylon\ZhylonIdService\Enums\FailureReason;
use Zhylon\ZhylonIdService\Data\OrganizationMember;
use Zhylon\ZhylonIdService\Facades\ZhylonIdService;
use Zhylon\ZhylonIdService\Data\ZhylonIdOrganization;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdOrganizationsContract;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

const ORG_UUID = '0192f4c1-7a2b-7c3d-9e8f-1a2b3c4d5e6f';
const ORG_URL = 'id.zhylon.test/api/internals/organizations/*';
const USER_ORGS_URL = 'id.zhylon.test/api/internals/users/*/organizations*';

function orgResource(array $attributes = [], string $id = ORG_UUID): array
{
    return [
        'id'         => $id,
        'type'       => 'organizations',
        'links'      => ['self' => ['href' => 'https://id.zhylon.test/api/internals/organizations/'.$id]],
        'attributes' => $attributes + [
            'name'            => 'Acme',
            'slug'            => 'acme',
            'owner_zhylon_id' => 'h:Xb9kPq',
            'created_at'      => '2026-10-26T10:00:00+00:00',
            'updated_at'      => '2026-10-27T10:00:00+00:00',
        ],
    ];
}

function listPage(array $data, ?string $nextCursor = null): array
{
    return [
        'data'  => $data,
        'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
        'meta'  => ['path' => '…', 'per_page' => 100, 'next_cursor' => $nextCursor, 'prev_cursor' => null],
    ];
}

function memberResource(string $zhylonId, string $role): array
{
    return ['id' => $zhylonId, 'type' => 'organization-members', 'attributes' => ['zhylon_id' => $zhylonId, 'role' => $role]];
}

function orgs(): ZhylonIdOrganizationsContract
{
    return app(ZhylonIdOrganizationsContract::class);
}

it('resolves the organizations contract to the same manager instance', function () {
    expect(app(ZhylonIdOrganizationsContract::class))->toBe(app(ZhylonIdServiceContract::class));
});

// getOrganization ------------------------------------------------------------------

it('returns an organization with slug', function () {
    Http::fake(fakeToken() + [ORG_URL => Http::response(['data' => orgResource()], 200)]);

    $org = orgs()->getOrganization(ORG_UUID);

    expect($org)->toBeInstanceOf(ZhylonIdOrganization::class)
        ->and($org->uuid)->toBe(ORG_UUID)
        ->and($org->name)->toBe('Acme')
        ->and($org->slug)->toBe('acme')
        ->and($org->ownerZhylonId)->toBe('h:Xb9kPq')
        ->and($org->role)->toBeNull()
        ->and($org->createdAt?->format(DATE_ATOM))->toBe('2026-10-26T10:00:00+00:00')
        ->and($org->updatedAt?->format(DATE_ATOM))->toBe('2026-10-27T10:00:00+00:00');

    Http::assertSent(fn (Request $request) => 'GET' === $request->method()
        && str_ends_with($request->url(), '/api/internals/organizations/'.ORG_UUID));
});

it('works through the facade', function () {
    Http::fake(fakeToken() + [ORG_URL => Http::response(['data' => orgResource()], 200)]);

    expect(ZhylonIdService::getOrganization(ORG_UUID)?->slug)->toBe('acme');
});

it('normalizes the uuid to lowercase', function () {
    Http::fake(fakeToken() + [ORG_URL => Http::response(['data' => orgResource()], 200)]);

    orgs()->getOrganization(strtoupper(ORG_UUID));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/organizations/'.ORG_UUID));
});

it('returns a null slug for a ZhylonID without T17 or an invalid slug', function (array $attributes) {
    Http::fake(fakeToken() + [ORG_URL => Http::response(['data' => orgResource($attributes)], 200)]);

    expect(orgs()->getOrganization(ORG_UUID)->slug)->toBeNull();
})->with([
    'null'      => [['slug' => null]],
    'uppercase' => [['slug' => 'Acme']],
    'not slug'  => [['slug' => 'a/b']],
]);

it('returns null for an unknown or personal organization', function () {
    Http::fake(fakeToken() + [ORG_URL => Http::response(notFoundBody(), 404)]);

    expect(orgs()->getOrganization(ORG_UUID))->toBeNull();
});

it('rejects an invalid uuid before sending', function (string $uuid) {
    Http::fake();

    expect(fn () => orgs()->getOrganization($uuid))->toThrow(InvalidArgumentException::class);
    expect(fn () => orgs()->getOrganizationMembers($uuid))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['empty' => [''], 'hash id' => ['h:Xb9kPq'], 'path' => ['../users/lookup']]);

it('throws for an invalid organization payload', function (array $data) {
    Http::fake(fakeToken() + [ORG_URL => Http::response(['data' => $data], 200)]);

    expect(fn () => orgs()->getOrganization(ORG_UUID))->toThrow(ZhylonIdUnavailableException::class);
})->with([
    'wrong type'    => [['type' => 'users'] + orgResource()],
    'id not a uuid' => [orgResource([], 'h:abc')],
    'missing name'  => [array_replace_recursive(orgResource(), ['attributes' => ['name' => null]])],
    'missing owner' => [array_replace_recursive(orgResource(), ['attributes' => ['owner_zhylon_id' => 7]])],
]);

dataset('organization failures', [
    'route missing (404 without not_found)' => [fn () => fakeToken() + [ORG_URL => Http::response('<html>Not Found', 404)], FailureReason::UnexpectedStatus],
    'connection error'                      => [fn () => fakeToken() + [ORG_URL => Http::failedConnection()], FailureReason::ConnectionFailed],
    '403'                                   => [fn () => fakeToken() + [ORG_URL => Http::response(['errors' => [['code' => 'forbidden']]], 403)], FailureReason::Unauthorized],
    '500'                                   => [fn () => fakeToken() + [ORG_URL => Http::response('SENSITIVE-BODY-MARKER', 500)], FailureReason::ServerError],
    'broken json'                           => [fn () => fakeToken() + [ORG_URL => Http::response('<html>', 200)], FailureReason::InvalidResponse],
]);

it('throws when ZhylonID is unavailable in block mode', function (Closure $fakes, FailureReason $reason) {
    Http::fake($fakes());

    foreach (['getOrganization', 'getOrganizationMembers'] as $method) {
        try {
            orgs()->{$method}(ORG_UUID);
            $this->fail("Expected ZhylonIdUnavailableException from {$method}.");
        } catch (ZhylonIdUnavailableException $e) {
            expect($e->reason)->toBe($reason)
                ->and($e->getMessage())->not->toContain('SENSITIVE-BODY-MARKER');
        }
    }
})->with('organization failures');

it('returns null and logs a warning in allow mode', function (Closure $fakes, FailureReason $reason) {
    config(['zhylonid-service.on_failure' => 'allow']);
    Http::fake($fakes());
    Log::spy();

    expect(orgs()->getOrganization(ORG_UUID))->toBeNull()
        ->and(orgs()->getOrganizationMembers(ORG_UUID))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => 'getOrganization' === $context['operation'] && $reason->value === $context['reason']);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => 'getOrganizationMembers' === $context['operation'] && $reason->value === $context['reason']);
})->with('organization failures');

// getUserOrganizations -------------------------------------------------------------

it('returns the organizations of a user with the role of that user', function () {
    Http::fake(fakeToken() + [USER_ORGS_URL => Http::response(listPage([orgResource(['role' => 'admin'])]), 200)]);

    $organizations = orgs()->getUserOrganizations('h:Lm3nRt');

    expect($organizations)->toBeInstanceOf(Collection::class)->toHaveCount(1)
        ->and($organizations->first()->uuid)->toBe(ORG_UUID)
        ->and($organizations->first()->slug)->toBe('acme')
        ->and($organizations->first()->role)->toBe('admin');

    Http::assertSent(fn (Request $request) => 'GET' === $request->method()
        && str_contains($request->url(), '/api/internals/users/h%3ALm3nRt/organizations')
        && '100' === (string) ($request->data()['page']['size'] ?? null));
});

it('returns an empty collection for a user without organizations', function () {
    Http::fake(fakeToken() + [USER_ORGS_URL => Http::response(listPage([]), 200)]);

    expect(orgs()->getUserOrganizations('h:Lm3nRt'))->toBeInstanceOf(Collection::class)->toBeEmpty();
});

it('follows the cursor over all pages', function () {
    $second = '0192f4c1-7a2b-7c3d-9e8f-000000000002';

    Http::fake(fakeToken() + [USER_ORGS_URL => Http::sequence()
        ->push(listPage([orgResource(['role' => 'owner'])], 'CURSOR-2'), 200)
        ->push(listPage([orgResource(['role' => 'member', 'slug' => 'beta'], $second)]), 200),
    ]);

    $organizations = orgs()->getUserOrganizations('h:Lm3nRt');

    expect($organizations->pluck('uuid')->all())->toBe([ORG_UUID, $second])
        ->and($organizations->pluck('role')->all())->toBe(['owner', 'member']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/organizations')
        && 'CURSOR-2' === ($request->data()['page']['cursor'] ?? null));
});

it('never returns a partial list when there are too many pages', function () {
    Http::fake(fakeToken() + [USER_ORGS_URL => Http::response(listPage([orgResource(['role' => 'member'])], 'AGAIN'), 200)]);

    expect(fn () => orgs()->getUserOrganizations('h:Lm3nRt'))->toThrow(ZhylonIdUnavailableException::class);
});

it('returns null for an unknown user', function () {
    Http::fake(fakeToken() + [USER_ORGS_URL => Http::response(notFoundBody(), 404)]);

    expect(orgs()->getUserOrganizations('h:zzz'))->toBeNull();
});

it('requires the role of the user in each organization', function () {
    Http::fake(fakeToken() + [USER_ORGS_URL => Http::response(listPage([orgResource()]), 200)]);

    expect(fn () => orgs()->getUserOrganizations('h:Lm3nRt'))->toThrow(ZhylonIdUnavailableException::class);
});

it('rejects an empty zhylon id before sending', function () {
    Http::fake();

    expect(fn () => orgs()->getUserOrganizations(' '))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

// getOrganizationMembers -----------------------------------------------------------

it('returns the members of an organization', function () {
    Http::fake(fakeToken() + [ORG_URL => Http::response(listPage([
        memberResource('h:Xb9kPq', 'owner'),
        memberResource('h:Lm3nRt', 'member'),
    ]), 200)]);

    $members = orgs()->getOrganizationMembers(ORG_UUID);

    expect($members)->toHaveCount(2)
        ->and($members->first())->toEqual(new OrganizationMember('h:Xb9kPq', 'owner'))
        ->and($members->last()->role)->toBe('member');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/internals/organizations/'.ORG_UUID.'/members'));
});

it('returns null for the members of an unknown organization', function () {
    Http::fake(fakeToken() + [ORG_URL => Http::response(notFoundBody(), 404)]);

    expect(orgs()->getOrganizationMembers(ORG_UUID))->toBeNull();
});

it('throws for an invalid member payload', function () {
    Http::fake(fakeToken() + [ORG_URL => Http::response(listPage([['id' => 'h:x', 'type' => 'users', 'attributes' => ['zhylon_id' => 'h:x', 'role' => 'member']]]), 200)]);

    expect(fn () => orgs()->getOrganizationMembers(ORG_UUID))->toThrow(ZhylonIdUnavailableException::class);
});
