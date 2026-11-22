<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Zhylon\ZhylonIdService\Enums\FailureReason;
use Zhylon\ZhylonIdService\Data\OrganizationSyncReport;
use Zhylon\ZhylonIdService\Enums\OrganizationSyncStatus;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdOrganizationsContract;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

const ORG_SYNC_URL = 'id.zhylon.test/api/internals/organizations/sync';
const SYNCED_ORG_UUID = '0192f4c1-7a2b-7c3d-9e8f-1a2b3c4d5e6f';

function syncEntry(array $override = []): array
{
    return $override + [
        'external_id'     => 42,
        'name'            => 'Acme',
        'owner_zhylon_id' => 'h:Xb9kPq',
        'members'         => [['zhylon_id' => 'h:Lm3nRt', 'role' => 'admin']],
    ];
}

function syncResult(array $attributes = []): array
{
    $attributes += [
        'provider'          => 'sitealarm',
        'external_id'       => '42',
        'status'            => 'created',
        'organization_id'   => SYNCED_ORG_UUID,
        'organization_slug' => 'acme',
        'issues'            => [],
        'members'           => [['zhylon_id' => 'h:Lm3nRt', 'role' => 'admin', 'mapped_role' => 'admin', 'status' => 'added']],
    ];

    return [
        'id'         => $attributes['provider'].':'.$attributes['external_id'],
        'type'       => 'organization-sync-results',
        'attributes' => $attributes,
    ];
}

function syncBody(array $results, bool $dryRun = false): array
{
    return ['data' => $results, 'meta' => ['dry_run' => $dryRun, 'summary' => [
        'created' => 1, 'would_create' => 0, 'exists' => 0, 'conflict' => 0, 'skipped' => 0, 'unknown_user' => 0, 'unknown_role' => 0,
    ]]];
}

function orgSync(): ZhylonIdOrganizationsContract
{
    return app(ZhylonIdOrganizationsContract::class);
}

beforeEach(fn () => config(['zhylonid-service.sync.provider' => 'sitealarm']));

it('sends a JSON:API organization-syncs document with dry_run and the provider fallback', function (bool $dryRun) {
    Http::fake(fakeToken() + [ORG_SYNC_URL => Http::response(syncBody([syncResult()], $dryRun), 200)]);

    orgSync()->syncOrganizations([syncEntry(), syncEntry(['provider' => 'captcha', 'external_id' => '7'])], $dryRun);

    Http::assertSent(function (Request $request) use ($dryRun) {
        $attributes = $request->data()['data']['attributes'] ?? [];

        return 'POST' === $request->method()
            && str_ends_with($request->url(), '/api/internals/organizations/sync')
            && 'organization-syncs' === ($request->data()['data']['type'] ?? null)
            && $dryRun === $attributes['dry_run']
            && 'sitealarm' === $attributes['organizations'][0]['provider']
            && 42 === $attributes['organizations'][0]['external_id']
            && 'captcha' === $attributes['organizations'][1]['provider'];
    });
})->with(['dry run' => [true], 'real run' => [false]]);

it('parses the report including organization_slug', function () {
    Http::fake(fakeToken() + [ORG_SYNC_URL => Http::response(syncBody([syncResult()]), 200)]);

    $report = orgSync()->syncOrganizations(collect([syncEntry()]), false);

    expect($report)->toBeInstanceOf(OrganizationSyncReport::class)
        ->and($report->dryRun)->toBeFalse()
        ->and($report->summary['created'])->toBe(1)
        ->and($report->isClean())->toBeTrue()
        ->and($report->organizationIdFor('sitealarm', 42))->toBe(SYNCED_ORG_UUID)
        ->and($report->organizationIdFor('sitealarm', 'other'))->toBeNull();

    $result = $report->resultFor('sitealarm', '42');

    expect($result->status)->toBe(OrganizationSyncStatus::Created)
        ->and($result->organizationSlug)->toBe('acme')
        ->and($result->members[0]->mappedRole)->toBe('admin')
        ->and($result->members[0]->status)->toBe('added');
});

it('reports issues and is not clean for conflicts, skipped entries and unknown members', function (array $attributes, string $issue) {
    Http::fake(fakeToken() + [ORG_SYNC_URL => Http::response(syncBody([syncResult($attributes)], true), 200)]);

    $report = orgSync()->syncOrganizations([syncEntry()], true);

    expect($report->isClean())->toBeFalse()
        ->and($report->results[0]->hasIssue($issue) || 'unknown_role' === $report->results[0]->members[0]->status)->toBeTrue();
})->with([
    'owner mismatch' => [['status' => 'conflict', 'issues' => [['code' => 'owner_mismatch']]], 'owner_mismatch'],
    'unknown owner'  => [['status' => 'skipped', 'organization_id' => null, 'organization_slug' => null, 'issues' => [['code' => 'unknown_owner']]], 'unknown_owner'],
    'unknown role'   => [['status' => 'would_create', 'organization_id' => null, 'organization_slug' => null, 'members' => [
        ['zhylon_id' => 'Pq7sVw', 'role' => 'Editor', 'mapped_role' => null, 'status' => 'unknown_role'],
    ]], 'none'],
]);

it('stays clean when only the name differs', function () {
    Http::fake(fakeToken() + [ORG_SYNC_URL => Http::response(syncBody([syncResult([
        'status' => 'exists', 'issues' => [['code' => 'name_differs']], 'members' => [],
    ])]), 200)]);

    $report = orgSync()->syncOrganizations([syncEntry()], false);

    expect($report->isClean())->toBeTrue()
        ->and($report->results[0]->hasIssue('name_differs'))->toBeTrue();
});

it('rejects an empty list before sending', function () {
    Http::fake();

    expect(fn () => orgSync()->syncOrganizations([], true))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('turns a 422 into InvalidArgumentException with codes and pointers only', function () {
    Http::fake(fakeToken() + [ORG_SYNC_URL => Http::response(['errors' => [[
        'status' => '422', 'code' => 'validation_error', 'title' => 'SECRET-TITLE', 'detail' => 'SECRET-DETAIL',
        'source' => ['pointer' => '/data/attributes/organizations/0/name'],
    ]]], 422)]);

    try {
        orgSync()->syncOrganizations([syncEntry()], true);
        $this->fail('Expected InvalidArgumentException.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('validation_error at /data/attributes/organizations/0/name')
            ->not->toContain('SECRET');
    }
});

it('throws for an invalid report', function (array $body) {
    Http::fake(fakeToken() + [ORG_SYNC_URL => Http::response($body, 200)]);

    expect(fn () => orgSync()->syncOrganizations([syncEntry()], true))->toThrow(ZhylonIdUnavailableException::class);
})->with([
    'dry_run differs' => [syncBody([syncResult(['status' => 'would_create'])], false)],
    'unknown status'  => [syncBody([syncResult(['status' => 'merged'])], true)],
    'data not a list' => [['data' => ['x' => 1], 'meta' => ['dry_run' => true]]],
    'wrong type'      => [syncBody([['type' => 'users'] + syncResult()], true)],
]);

it('throws in block mode and returns null in allow mode when ZhylonID is down', function () {
    Http::fake(fakeToken() + [ORG_SYNC_URL => Http::response('down', 503)]);

    expect(fn () => orgSync()->syncOrganizations([syncEntry()], true))
        ->toThrow(fn (ZhylonIdUnavailableException $e) => expect($e->reason)->toBe(FailureReason::ServerError));

    config(['zhylonid-service.on_failure' => 'allow']);
    Log::spy();

    expect(orgSync()->syncOrganizations([syncEntry()], true))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => 'syncOrganizations' === $context['operation']);
});
