<?php

it('fails fast and names the actual env var when no valid user model is configured', function () {
    config(['zhylonid-service.sync.user_model' => 'App\\Models\\DoesNotExist']);

    $this->artisan('zhylon-id:sync-users')
        ->expectsOutputToContain('Set ZHYLONID_SERVICE_USER_MODEL.')
        ->assertExitCode(1);
});
