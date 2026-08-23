<?php

it('declares laravel package aliases that resolve to real, existing classes', function () {
    $composerJson = json_decode(
        file_get_contents(__DIR__.'/../composer.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    $aliases = $composerJson['extra']['laravel']['aliases'] ?? [];

    expect($aliases)->not->toBeEmpty();

    foreach ($aliases as $alias => $class) {
        expect(class_exists($class))
            ->toBeTrue("Alias [{$alias}] points to [{$class}], but that class does not exist.");
    }
});
