<?php

namespace InformaticadosLago\Translations;

use Illuminate\Support\ServiceProvider;
use InformaticadosLago\Translations\Commands\ExtractCommand;
use InformaticadosLago\Translations\Commands\TranslateCommand;

class TranslationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/translations.php', 'translations');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExtractCommand::class,
                TranslateCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/translations.php' => config_path('translations.php'),
            ], 'translations-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'translations-migrations');
        }

        // Permite usar la migración sin publicarla.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
