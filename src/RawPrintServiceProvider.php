<?php

declare(strict_types=1);

namespace RawPrint\Laravel;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use RawPrint\Laravel\Agent\AgentClient;
use RawPrint\Laravel\View\Components\RawPrint as RawPrintComponent;

class RawPrintServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rawprint.php', 'rawprint');

        $this->app->scoped(
            RawPrintManager::class,
            fn ($app) => new RawPrintManager($app, $app['config']),
        );

        $this->app->bind(
            AgentClient::class,
            fn ($app) => $app->make(RawPrintManager::class)->client(),
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rawprint');

        Blade::component('rawprint', RawPrintComponent::class);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/rawprint.php' => config_path('rawprint.php'),
        ], 'rawprint-config');

        $this->publishes([
            __DIR__.'/../resources/js' => public_path('vendor/rawprint'),
        ], 'rawprint-assets');
    }
}
