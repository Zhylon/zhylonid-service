<?php

namespace Zhylon\ZhylonIdService;

use Illuminate\Support\ServiceProvider;
use Zhylon\ZhylonIdService\Console\Commands\SyncWithZhylonIdCommand;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;
use Zhylon\ZhylonIdService\Services\ZhylonIdManager;

class ZhylonIdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/zhylonid-service.php', 'zhylonid-service');

        $this->app->singleton(ZhylonIdServiceContract::class, ZhylonIdManager::class);
        $this->app->alias(ZhylonIdServiceContract::class, 'zhylonid-service');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/zhylonid-service.php' => config_path('zhylonid-service.php'),
        ], 'zhylonid-service-config');

        $this->commands([
            SyncWithZhylonIdCommand::class,
        ]);
    }
}
