<?php

namespace Dorvianes\OpenWatch;

use Dorvianes\OpenWatch\Console\SendTestCommand;
use Dorvianes\OpenWatch\Middleware\RecordRequest;
use Dorvianes\OpenWatch\Recorders\ExceptionRecorder;
use Dorvianes\OpenWatch\Recorders\RequestRecorder;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class OpenWatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/openwatch.php',
            'openwatch'
        );

        $this->app->singleton('openwatch.transport', function ($app) {
            $cfg = $app['config']['openwatch'];

            return new HttpTransport(
                serverUrl: $cfg['server_url'] ?? '',
                token:     $cfg['token'] ?? '',
                timeout:   (float) ($cfg['timeout'] ?? 0.1),
            );
        });

        $this->app->singleton(RequestRecorder::class, function ($app) {
            return new RequestRecorder($app->make('openwatch.transport'));
        });

        $this->app->singleton(ExceptionRecorder::class, function ($app) {
            return new ExceptionRecorder($app->make('openwatch.transport'));
        });

        $this->app->singleton(SendTestCommand::class, function ($app) {
            return new SendTestCommand($app->make('openwatch.transport'));
        });
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/openwatch.php' => config_path('openwatch.php'),
        ], 'openwatch-config');

        // Register artisan command
        if ($this->app->runningInConsole()) {
            $this->commands([
                SendTestCommand::class,
            ]);
        }

        $this->bootRequestRecorder();
    }

    private function bootRequestRecorder(): void
    {
        $cfg = $this->app['config']['openwatch'];

        // Silently disable if not configured
        if (
            empty($cfg['server_url']) ||
            empty($cfg['token']) ||
            ! ($cfg['enabled'] ?? true)
        ) {
            return;
        }

        // Push terminating middleware — fires after response is sent to browser
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', RecordRequest::class);
        $router->pushMiddlewareToGroup('api', RecordRequest::class);
    }
}
