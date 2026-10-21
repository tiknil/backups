<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Tiknil\Backups\BackupsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            BackupsServiceProvider::class,
        ];
    }
}
