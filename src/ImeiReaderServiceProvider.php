<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Ozodbek\LaravelImeiReader\Commands\ReadImeiCommand;
use Ozodbek\LaravelImeiReader\Support\LuhnValidator;

class ImeiReaderServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/imei-reader.php',
            'imei-reader'
        );

        $this->app->singleton('imei.reader', function ($app) {
            $config = $app['config']->get('imei-reader', []);
            return new ImeiReaderManager($config);
        });

        $this->app->alias('imei.reader', ImeiReaderManager::class);
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/imei-reader.php' => config_path('imei-reader.php'),
            ], 'imei-reader-config');

            $this->commands([
                ReadImeiCommand::class,
            ]);
        }

        // Register custom validation rule: 'imei' and 'imei_strict'
        Validator::extend('imei', function ($attribute, $value, $parameters, $validator) {
            if (!is_string($value) && !is_numeric($value)) {
                return false;
            }
            $clean = LuhnValidator::sanitize((string) $value);
            return LuhnValidator::isValidStructure($clean);
        }, 'The :attribute must be a valid 15-digit IMEI number.');

        Validator::extend('imei_strict', function ($attribute, $value, $parameters, $validator) {
            if (!is_string($value) && !is_numeric($value)) {
                return false;
            }
            $clean = LuhnValidator::sanitize((string) $value);
            return LuhnValidator::isValidStructure($clean) && LuhnValidator::validate($clean);
        }, 'The :attribute must be a valid 15-digit IMEI with a correct Luhn check digit.');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            'imei.reader',
            ImeiReaderManager::class,
        ];
    }
}
