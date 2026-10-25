<?php

namespace Zhylon\ZhylonIdService\Console\Commands;

use InvalidArgumentException;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Zhylon\ZhylonIdService\Enums\OnFailure;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

/**
 * Creates ZhylonID accounts for local users that have none yet.
 *
 * Emails that already exist in ZhylonID are conflicts: they are listed and never
 * linked automatically (linking needs proof of ownership, see zhylon-auth v3.0).
 */
class SyncWithZhylonIdCommand extends Command
{
    protected $signature = 'zhylon-id:sync-users
        {--dry-run : Only list accounts that would be created and conflicts. Writes nothing.}';

    protected $description = 'Create ZhylonID accounts for local users without a ZhylonID. Existing ZhylonID emails are reported as conflicts, never linked.';

    public function handle(ZhylonIdServiceContract $service): int
    {
        $modelClass = (string) config('zhylonid-service.sync.user_model');
        $idColumn = (string) config('zhylonid-service.sync.id_column', 'zhylon_id');
        $dryRun = (bool) $this->option('dry-run');

        if ('' === $modelClass || ! class_exists($modelClass)) {
            $this->error("Configured user model [{$modelClass}] does not exist. Set ZHYLONID_SERVICE_USER_MODEL.");

            return self::FAILURE;
        }

        if (OnFailure::Allow === OnFailure::fromConfig(config('zhylonid-service.on_failure'))) {
            $this->error('Refusing to sync while on_failure=allow is set: a bulk sync must fail closed. Run it with ZHYLONID_SERVICE_ON_FAILURE=block.');

            return self::FAILURE;
        }

        $users = $modelClass::query()
            ->whereNull($idColumn)
            ->select(['id', 'name', 'email'])
            ->get();

        if ($users->isEmpty()) {
            $this->info('No users without a ZhylonID found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing will be written.');
        }

        try {
            [$toCreate, $conflicts, $invalid] = $this->classify($service, $users);
        } catch (ZhylonIdUnavailableException $e) {
            return $this->unavailable($e);
        }

        $this->report($toCreate, $conflicts, $invalid, $dryRun);

        if ($dryRun) {
            return self::SUCCESS;
        }

        if ($toCreate->isEmpty()) {
            $this->info("Sync complete. 0 user(s) created and linked, {$conflicts->count()} conflict(s) skipped.");

            return self::SUCCESS;
        }

        try {
            $synced = $service->getSyncUsers($toCreate->map(fn ($user) => [
                'name'  => $user->name,
                'email' => $user->email,
            ])->values());
        } catch (ZhylonIdUnavailableException $e) {
            return $this->unavailable($e);
        }

        if (! is_array($synced)) {
            $this->error('Sync failed — no data returned from ZhylonID. Nothing was written.');

            return self::FAILURE;
        }

        // Only users we checked as "new" may be linked, matched by email.
        $pushed = $toCreate->keyBy(fn ($user) => mb_strtolower((string) $user->email));
        $updated = 0;

        foreach ($synced as $zhylonUser) {
            if (! is_array($zhylonUser) || empty($zhylonUser['email']) || empty($zhylonUser['id'])) {
                continue;
            }

            // Only link accounts this request created. "created: false" means the
            // account already existed (e.g. created between our check and the push).
            // A missing flag counts as "not created": fail closed.
            if (true !== ($zhylonUser['created'] ?? null)) {
                $this->warn("Not linked, account already existed in ZhylonID: {$zhylonUser['email']}");

                continue;
            }

            $local = $pushed->get(mb_strtolower((string) $zhylonUser['email']));

            if (! $local) {
                continue;
            }

            $user = $modelClass::query()->whereKey($local->getKey())->first();

            if (! $user || ! empty($user->{$idColumn})) {
                continue;
            }

            $user->{$idColumn} = $zhylonUser['id'];
            $user->save();
            $updated++;
        }

        $this->info("Sync complete. {$updated} user(s) created and linked, {$conflicts->count()} conflict(s) skipped.");

        return self::SUCCESS;
    }

    /**
     * @throws ZhylonIdUnavailableException
     *
     * @return array{0: Collection, 1: Collection, 2: Collection} [toCreate, conflicts, invalid]
     */
    private function classify(ZhylonIdServiceContract $service, Collection $users): array
    {
        $toCreate = collect();
        $conflicts = collect();
        $invalid = collect();

        foreach ($users as $user) {
            try {
                $service->userExists((string) $user->email)
                    ? $conflicts->push($user)
                    : $toCreate->push($user);
            } catch (InvalidArgumentException) {
                $invalid->push($user);
            }
        }

        return [$toCreate, $conflicts, $invalid];
    }

    private function report(Collection $toCreate, Collection $conflicts, Collection $invalid, bool $dryRun): void
    {
        $this->info(($dryRun ? 'Would be created in ZhylonID: ' : 'To be created in ZhylonID: ').$toCreate->count());
        $this->rows($toCreate);

        $this->warn('Conflicts (email already exists in ZhylonID, will not be linked): '.$conflicts->count());
        $this->rows($conflicts);

        if ($invalid->isNotEmpty()) {
            $this->warn('Skipped (invalid email address): '.$invalid->count());
            $this->rows($invalid);
        }
    }

    private function rows(Collection $users): void
    {
        if ($users->isEmpty()) {
            return;
        }

        $this->table(['ID', 'Name', 'Email'], $users->map(fn ($user) => [
            $user->getKey(),
            $user->name,
            $user->email,
        ])->all());
    }

    private function unavailable(ZhylonIdUnavailableException $e): int
    {
        $status = null === $e->status ? '' : ", HTTP {$e->status}";

        $this->error("ZhylonID is unavailable ({$e->reason->value}{$status}). Nothing was written.");

        return self::FAILURE;
    }
}
