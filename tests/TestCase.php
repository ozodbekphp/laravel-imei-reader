<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Ozodbek\LaravelImeiReader\Facades\ImeiReader;
use Ozodbek\LaravelImeiReader\ImeiReaderServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ImeiReaderServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'ImeiReader' => ImeiReader::class,
        ];
    }
}
