<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Zhylon\ZhylonIdService\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'database.default'                 => 'testing',
        'database.connections.testing'     => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'zhylonid-service.sync.user_model' => User::class,
        'zhylonid-service.sync.id_column'  => 'zhylon_id',
    ]);

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('zhylon_id')->nullable();
        $table->timestamps();
    });

    User::create(['name' => 'New Nora', 'email' => 'new@example.com']);
    User::create(['name' => 'Conflict Carl', 'email' => 'taken@example.com']);
    User::create(['name' => 'Linked Lea', 'email' => 'linked@example.com', 'zhylon_id' => 'zid_existing']);
});

/**
 * Lookup: taken@example.com exists in ZhylonID, everything else does not.
 */
function fakeZhylonIdForSync(): void
{
    Http::fake(fakeToken() + [
        ZHYLON_LOOKUP_URL => fn (Request $request) => 'taken@example.com' === $request['data']['attributes']['email']
            ? lookupFound('taken@example.com', false)
            : lookupNotFound(),
        ZHYLON_SYNC_URL => fn (Request $request) => Http::response(
            collect($request['users'])->map(fn ($u) => [
                'id'      => 'zid_'.md5($u['email']),
                'name'    => $u['name'],
                'email'   => $u['email'],
                'created' => true,
            ])->values()->all(),
            200,
        ),
    ]);
}

function zhylonIds(): array
{
    return User::query()->orderBy('id')->pluck('zhylon_id', 'email')->all();
}

it('fails fast and names the actual env var when no valid user model is configured', function () {
    config(['zhylonid-service.sync.user_model' => 'App\\Models\\DoesNotExist']);

    $this->artisan('zhylon-id:sync-users')
        ->expectsOutputToContain('Set ZHYLONID_SERVICE_USER_MODEL.')
        ->assertExitCode(1);
});

it('lists would-be-created accounts and conflicts in dry-run without writing anything', function () {
    fakeZhylonIdForSync();
    $before = zhylonIds();

    $this->artisan('zhylon-id:sync-users', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('Would be created in ZhylonID: 1')
        ->expectsOutputToContain('new@example.com')
        ->expectsOutputToContain('Conflicts (email already exists in ZhylonID, will not be linked): 1')
        ->expectsOutputToContain('taken@example.com')
        ->assertExitCode(0);

    expect(zhylonIds())->toBe($before);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'sync-users'));
});

it('pushes only new accounts and never links conflicts', function () {
    fakeZhylonIdForSync();

    $this->artisan('zhylon-id:sync-users')
        ->expectsOutputToContain('taken@example.com')
        ->expectsOutputToContain('1 user(s) created and linked, 1 conflict(s) skipped')
        ->assertExitCode(0);

    expect(zhylonIds())->toBe([
        'new@example.com'    => 'zid_'.md5('new@example.com'),
        'taken@example.com'  => null,
        'linked@example.com' => 'zid_existing',
    ]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sync-users')
        && ['new@example.com'] === collect($request['users'])->pluck('email')->all());
});

it('does not link a user the server reports as not newly created', function () {
    Http::fake(fakeToken() + [
        ZHYLON_LOOKUP_URL => lookupNotFound(),
        ZHYLON_SYNC_URL   => Http::response([
            ['id' => 'zid_race', 'name' => 'New Nora', 'email' => 'new@example.com', 'created' => false],
            ['id' => 'zid_carl', 'name' => 'Conflict Carl', 'email' => 'taken@example.com', 'created' => true],
        ], 200),
    ]);

    $this->artisan('zhylon-id:sync-users')->assertExitCode(0);

    expect(zhylonIds()['new@example.com'])->toBeNull()
        ->and(zhylonIds()['taken@example.com'])->toBe('zid_carl');
});

it('fails and writes nothing when ZhylonID is unavailable', function (bool $dryRun) {
    Http::fake(fakeToken() + [ZHYLON_LOOKUP_URL => Http::response([], 503)]);
    $before = zhylonIds();

    $this->artisan('zhylon-id:sync-users', $dryRun ? ['--dry-run' => true] : [])
        ->expectsOutputToContain('ZhylonID is unavailable')
        ->assertExitCode(1);

    expect(zhylonIds())->toBe($before);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'sync-users'));
})->with(['dry-run' => true, 'real run' => false]);

it('refuses to run when on_failure=allow, because a bulk sync must fail closed', function () {
    config(['zhylonid-service.on_failure' => 'allow']);
    Http::fake();

    $this->artisan('zhylon-id:sync-users')
        ->expectsOutputToContain('on_failure=allow')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('reports when no users need syncing', function () {
    User::query()->update(['zhylon_id' => 'zid_x']);
    Http::fake();

    $this->artisan('zhylon-id:sync-users')
        ->expectsOutputToContain('No users without a ZhylonID found.')
        ->assertExitCode(0);
});

it('does not link a user when the server omits the created flag', function () {
    Http::fake(fakeToken() + [
        ZHYLON_LOOKUP_URL => lookupNotFound(),
        ZHYLON_SYNC_URL   => Http::response([
            ['id' => 'zid_nora', 'name' => 'New Nora', 'email' => 'new@example.com'],
        ], 200),
    ]);

    $this->artisan('zhylon-id:sync-users')
        ->expectsOutputToContain('0 user(s) created and linked')
        ->assertExitCode(0);

    expect(zhylonIds()['new@example.com'])->toBeNull();
});
