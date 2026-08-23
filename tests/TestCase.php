<?php

namespace Zhylon\ZhylonIdService\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zhylon\ZhylonIdService\ZhylonIdServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ZhylonIdServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('zhylonid-service.endpoint', 'https://id.zhylon.test');
        $app['config']->set('zhylonid-service.client_id', 'test-client');
        $app['config']->set('zhylonid-service.client_secret', 'test-secret');
    }
}
