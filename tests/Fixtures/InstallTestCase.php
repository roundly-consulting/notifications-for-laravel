<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures;

use Illuminate\Filesystem\Filesystem;
use RoundlyConsulting\Notifications\Tests\TestCase;

/**
 * `notifications:install` publishes into a per-test sandbox, never the shared Testbench app.
 */
abstract class InstallTestCase extends TestCase
{
    protected string $sandbox = '';

    protected function defineEnvironment($app): void
    {
        $this->sandbox = sys_get_temp_dir().'/notifications-install-'.bin2hex(random_bytes(6));
        (new Filesystem)->ensureDirectoryExists($this->sandbox.'/config');
        (new Filesystem)->ensureDirectoryExists($this->sandbox.'/database/migrations');

        $app->useConfigPath($this->sandbox.'/config');
        $app->useDatabasePath($this->sandbox.'/database');

        parent::defineEnvironment($app);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->sandbox);

        parent::tearDown();
    }
}
