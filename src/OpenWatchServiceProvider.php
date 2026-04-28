<?php

namespace Dorvianes\OpenWatch;

use Dorvianes\OpenWatch\Console\SendTestCommand;
use Dorvianes\OpenWatch\Middleware\RecordRequest;
use Dorvianes\OpenWatch\Buffer\EventBuffer;
use Dorvianes\OpenWatch\Recorders\ExceptionRecorder;
use Dorvianes\OpenWatch\Recorders\OutgoingRequestRecorder;
use Dorvianes\OpenWatch\Recorders\QueryRecorder;
use Dorvianes\OpenWatch\Recorders\RequestRecorder;
use Dorvianes\OpenWatch\Support\BatchFlusher;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
                serverUrl:      $cfg['server_url'] ?? '',
                token:          $cfg['token'] ?? '',
                timeout:        (float) ($cfg['timeout'] ?? 0.1),
                connectTimeout: isset($cfg['connect_timeout']) ? (float) $cfg['connect_timeout'] : null,
            );
        });

        $this->app->singleton(EventBuffer::class, function ($app) {
            $cfg = $app['config']['openwatch'];
            $max = (int) ($cfg['batching']['max_events'] ?? 1000);
            return new EventBuffer(maxEvents: $max);
        });

        $this->app->singleton(BatchFlusher::class, function ($app) {
            $cfg             = $app['config']['openwatch'];
            $batchingEnabled = (bool) ($cfg['batching']['enabled'] ?? false);
            return new BatchFlusher(
                $app->make('openwatch.transport'),
                $app->make(EventBuffer::class),
                batchingEnabled: $batchingEnabled,
            );
        });

        $this->app->singleton(RequestRecorder::class, function ($app) {
            return new RequestRecorder($app->make('openwatch.transport'));
        });

        $this->app->singleton(ExceptionRecorder::class, function ($app) {
            return new ExceptionRecorder($app->make('openwatch.transport'));
        });

        $this->app->singleton(QueryRecorder::class, function ($app) {
            $cfg             = $app['config']['openwatch'];
            $batchingEnabled = (bool) ($cfg['batching']['enabled'] ?? false);
            return new QueryRecorder(
                $app->make('openwatch.transport'),
                buffer:          $batchingEnabled ? $app->make(EventBuffer::class) : null,
                batchingEnabled: $batchingEnabled,
            );
        });

        $this->app->singleton(OutgoingRequestRecorder::class, function ($app) {
            $cfg             = $app['config']['openwatch'];
            $ignoredHosts    = $cfg['ignored_hosts'] ?? [];
            $batchingEnabled = (bool) ($cfg['batching']['enabled'] ?? false);
            return new OutgoingRequestRecorder(
                $app->make('openwatch.transport'),
                ignoredHosts:    $ignoredHosts,
                buffer:          $batchingEnabled ? $app->make(EventBuffer::class) : null,
                batchingEnabled: $batchingEnabled,
            );
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

        $this->detectMisconfiguration();
        $this->bootRequestRecorder();
        $this->bootQueryRecorder();
        $this->bootOutgoingRequestRecorder();
        $this->bootCliFlush();
    }

    /**
     * Emit a single debug-level log entry when OpenWatch is enabled but
     * one or more required config values are missing. This surfaces
     * misconfiguration early without throwing or breaking the host app.
     */
    private function detectMisconfiguration(): void
    {
        $cfg = $this->app['config']['openwatch'];

        // Only warn when explicitly enabled; silently-disabled is not a misconfiguration.
        if (! ($cfg['enabled'] ?? true)) {
            return;
        }

        $missing = [];
        if (empty($cfg['server_url'])) {
            $missing[] = 'OPENWATCH_SERVER_URL';
        }
        if (empty($cfg['token'])) {
            $missing[] = 'OPENWATCH_TOKEN';
        }

        if (! empty($missing)) {
            try {
                Log::debug('[OpenWatch] Disabled — missing required config: ' . implode(', ', $missing) . '. '
                    . 'Set these in your .env and run `php artisan openwatch:send-test` to verify.');
            } catch (\Throwable) {
                // Log not available this early (e.g. unit tests) — silent.
            }
        }
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

        // Bind RecordRequest with the BatchFlusher so terminate() triggers flush
        $this->app->singleton(RecordRequest::class, function ($app) {
            return new RecordRequest(
                $app->make(RequestRecorder::class),
                $app->make(BatchFlusher::class),
            );
        });
    }

    private function bootQueryRecorder(): void
    {
        $cfg = $this->app['config']['openwatch'];

        // Silently disable if not configured or query capture is off
        if (
            empty($cfg['server_url']) ||
            empty($cfg['token']) ||
            ! ($cfg['enabled'] ?? true) ||
            ! ($cfg['capture_queries'] ?? true)
        ) {
            return;
        }

        $thresholdMs = isset($cfg['slow_query_threshold_ms'])
            ? (float) $cfg['slow_query_threshold_ms']
            : null;

        DB::listen(function ($queryEvent) use ($thresholdMs) {
            /** @var QueryRecorder $recorder */
            $recorder = $this->app->make(QueryRecorder::class);
            $recorder->record($queryEvent, $thresholdMs);
        });
    }

    private function bootOutgoingRequestRecorder(): void
    {
        $cfg = $this->app['config']['openwatch'];

        // Silently disable if not configured or outgoing capture is off
        if (
            empty($cfg['server_url']) ||
            empty($cfg['token']) ||
            ! ($cfg['enabled'] ?? true) ||
            ! ($cfg['capture_outgoing_requests'] ?? true)
        ) {
            return;
        }

        $thresholdMs = isset($cfg['slow_outgoing_request_threshold_ms'])
            ? (float) $cfg['slow_outgoing_request_threshold_ms']
            : null;

        // Laravel 10+ supports Http::globalResponseMiddleware() which is the
        // cleanest framework-supported hook. It receives a PSR-7-compatible
        // response with start/end timing injected by Guzzle's HandlerStack.
        // We use beforeSending + middleware to measure elapsed time reliably.
        Http::globalMiddleware(function (callable $handler) use ($thresholdMs) {
            return function ($request, array $options) use ($handler, $thresholdMs) {
                $start = microtime(true);

                $promise = $handler($request, $options);

                return $promise->then(function ($response) use ($request, $start, $thresholdMs) {
                    $durationMs = (microtime(true) - $start) * 1000;

                    /** @var OutgoingRequestRecorder $recorder */
                    $recorder = $this->app->make(OutgoingRequestRecorder::class);
                    $recorder->record(
                        method:      $request->getMethod(),
                        url:         (string) $request->getUri(),
                        status:      $response->getStatusCode(),
                        durationMs:  $durationMs,
                        thresholdMs: $thresholdMs,
                        startTime:   $start,
                    );

                    return $response;
                });
            };
        });
    }

    /**
     * Register a terminating callback for CLI commands and queued jobs.
     * When batching is enabled, this ensures events are flushed at the end
     * of any non-HTTP execution (artisan commands, queue workers per-job).
     */
    private function bootCliFlush(): void
    {
        $cfg = $this->app['config']['openwatch'];

        if (! ($cfg['batching']['enabled'] ?? false)) {
            return;
        }

        $this->app->terminating(function () {
            try {
                $this->app->make(BatchFlusher::class)->flush();
            } catch (\Throwable) {
                // Silent failure — never break the host application
            }
        });
    }
}
