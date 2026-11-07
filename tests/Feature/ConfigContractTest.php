<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

/**
 * The config contract, both directions: every key the code reads is shipped, and every shipped
 * leaf is read. The interop map/suppress lists and the types list are host-filled and read whole.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/notifications.php')->toSatisfyConfigContract(
        [__DIR__.'/../../src', __DIR__.'/../../database', __DIR__.'/../../routes'],
    );
});

it('never claims a config handle Laravel ships itself', function (): void {
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    expect($framework.'/config/app.php')->toBeFile()
        ->and($framework.'/config/notifications.php')->not->toBeFile();
});
