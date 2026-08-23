<?php

namespace Zhylon\ZhylonIdService\Console\Commands;

use Illuminate\Console\Command;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;

class SyncWithZhylonIdCommand extends Command
{
    protected $signature = 'zhylon-id:sync-users';

    protected $description = 'Sync local users without a ZhylonID against the ZhylonID identity API.';

    public function handle(ZhylonIdServiceContract $service): int
    {
        $modelClass = (string) config('zhylonid-service.sync.user_model');
        $idColumn = (string) config('zhylonid-service.sync.id_column', 'zhylon_id');

        if ($modelClass === '' || ! class_exists($modelClass)) {
            $this->error("Configured user model [{$modelClass}] does not exist. Set ZHYLONID_SERVICE_USER_MODEL.");

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

        $usersToSync = $users->map(fn ($user) => [
            'name' => $user->name,
            'email' => $user->email,
        ]);

        $synced = $service->getSyncUsers($usersToSync);

        if (! is_array($synced) || empty($synced)) {
            $this->error('Sync failed — no data returned from ZhylonID.');

            return self::FAILURE;
        }

        $updated = 0;

        foreach ($synced as $zhylonUser) {
            if (empty($zhylonUser['email']) || empty($zhylonUser['id'])) {
                continue;
            }

            $user = $modelClass::where('email', $zhylonUser['email'])->first();

            if (! $user || ! empty($user->{$idColumn})) {
                continue;
            }

            $user->{$idColumn} = $zhylonUser['id'];
            $user->save();
            $updated++;
        }

        $this->info("Sync complete. {$updated} user(s) updated.");

        return self::SUCCESS;
    }
}
