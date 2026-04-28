<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Buffer\EventBuffer;
use Dorvianes\OpenWatch\OpenWatchServiceProvider;
use Dorvianes\OpenWatch\Support\BatchFlusher;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * Wiring test: OpenWatchServiceProvider::bootCliFlush()
 *
 * Design requirement (design.md §3 Lifecycle hooks):
 *   CLI/jobs: flush al terminar via app()->terminating() — ONLY when batching.enabled=true.
 *
 * Goal: verify that the ServiceProvider itself calls $app->terminating() with a
 * callback when batching is enabled, and does NOT call it when batching is disabled.
 *
 * Approach: we use a minimal stub container that records terminating() calls and
 * stubs the config + make() dependencies. No full Laravel application is booted.
 */
class ServiceProviderBootCliFlushTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Minimal config stub — supports get/set/offsetGet used by ServiceProvider
    // -------------------------------------------------------------------------

    private function makeConfigStub(array $openwatch): object
    {
        return new class($openwatch) implements \ArrayAccess {
            private array $data;

            public function __construct(array $openwatch)
            {
                $this->data = ['openwatch' => $openwatch];
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function offsetGet(mixed $key): mixed { return $this->data[$key] ?? null; }
            public function offsetExists(mixed $key): bool { return isset($this->data[$key]); }
            public function offsetSet(mixed $key, mixed $v): void { $this->data[$key] = $v; }
            public function offsetUnset(mixed $key): void { unset($this->data[$key]); }
        };
    }

    // -------------------------------------------------------------------------
    // Minimal app stub — records terminating() registrations
    // -------------------------------------------------------------------------

    /**
     * @param  BatchFlusher|null  $flusher  inject a pre-built flusher for the
     *                                       flush-callback test; null = noop.
     */
    private function makeAppStub(array $openwatch, ?BatchFlusher $flusher = null): object
    {
        $config = $this->makeConfigStub($openwatch);

        return new class($config, $flusher) implements CachesConfiguration, \ArrayAccess {
            /** @var list<\Closure> */
            public array $terminatingCallbacks = [];

            public function __construct(
                private readonly object $config,
                private readonly ?BatchFlusher $flusher,
            ) {}

            // CachesConfiguration — makes ServiceProvider::mergeConfigFrom() skip
            // the require of config/openwatch.php (which needs PhpOption\Option).
            public function configurationIsCached(): bool { return true; }
            public function getCachedConfigPath(): string { return ''; }
            public function getCachedServicesPath(): string { return ''; }

            // ---- Container interface used by OpenWatchServiceProvider --------

            public function terminating(callable $callback): static
            {
                $this->terminatingCallbacks[] = $callback;
                return $this;
            }

            public function make(string $abstract): mixed
            {
                if ($abstract === 'config') {
                    return $this->config;
                }
                if ($abstract === BatchFlusher::class && $this->flusher !== null) {
                    return $this->flusher;
                }
                // Return a noop BatchFlusher for cases where we don't care about flush behavior
                if ($abstract === BatchFlusher::class) {
                    $noopTransport = new class extends HttpTransport {
                        public function __construct() {}
                        public function sendBatch(array $events): bool { return true; }
                    };
                    return new BatchFlusher($noopTransport, new EventBuffer(), batchingEnabled: true);
                }
                return null;
            }

            // ArrayAccess — used by $this->app['config'] in the provider
            public function offsetGet(mixed $key): mixed
            {
                if ($key === 'config') return $this->config;
                return null;
            }
            public function offsetExists(mixed $key): bool { return true; }
            public function offsetSet(mixed $key, mixed $v): void {}
            public function offsetUnset(mixed $key): void {}

            // Misc stubs required by ServiceProvider base or boot() branches
            public function singleton(string $a, \Closure $f): void {}
            public function runningInConsole(): bool { return false; }
            public function bound(string $a): bool { return false; }
            public function resolved(string $a): bool { return false; }
            public function afterResolving(string $a, \Closure $c): void {}
            public function resolving(string $a, \Closure $c): void {}
        };
    }

    /** Boot the provider with the given batching flag and return the app stub. */
    private function bootProviderWith(bool $batchingEnabled, ?BatchFlusher $flusher = null): object
    {
        $openwatch = [
            'enabled'    => false, // disable HTTP wiring; focus only on CLI flush
            'server_url' => '',
            'token'      => '',
            'batching'   => [
                'enabled'    => $batchingEnabled,
                'max_events' => 100,
            ],
        ];

        $app      = $this->makeAppStub($openwatch, $flusher);
        $provider = new OpenWatchServiceProvider($app);
        $provider->register();
        $provider->boot();

        return $app;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * When batching.enabled = true, boot() MUST register exactly one
     * terminating callback via $app->terminating().
     */
    public function test_provider_registers_terminating_callback_when_batching_enabled(): void
    {
        $app = $this->bootProviderWith(batchingEnabled: true);

        $this->assertCount(
            1,
            $app->terminatingCallbacks,
            'ServiceProvider must register exactly one terminating callback when batching.enabled=true',
        );
    }

    /**
     * When batching.enabled = false, boot() must NOT register any
     * terminating callback — the guard in bootCliFlush() must prevent it.
     */
    public function test_provider_does_not_register_terminating_callback_when_batching_disabled(): void
    {
        $app = $this->bootProviderWith(batchingEnabled: false);

        $this->assertCount(
            0,
            $app->terminatingCallbacks,
            'ServiceProvider must NOT register a terminating callback when batching.enabled=false',
        );
    }

    /**
     * The registered terminating callback must actually invoke BatchFlusher::flush()
     * — i.e., it is a real flush callback, not a no-op.
     *
     * We verify this by confirming the callback drains a buffer that was seeded
     * before invocation, and that the transport receives the batch.
     */
    public function test_registered_terminating_callback_flushes_the_buffer(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query', 'sql' => 'select 1']);

        $batchesSent  = [];
        $spyTransport = new class($batchesSent) extends HttpTransport {
            public function __construct(private array &$batchesSent) {}
            public function sendBatch(array $events): bool
            {
                $this->batchesSent[] = $events;
                return true;
            }
        };

        $flusher = new BatchFlusher($spyTransport, $buffer, batchingEnabled: true);
        $app     = $this->bootProviderWith(batchingEnabled: true, flusher: $flusher);

        $this->assertCount(1, $app->terminatingCallbacks, 'Pre-condition: one callback must be registered');

        // Simulate Laravel calling registered terminating callbacks at process end
        ($app->terminatingCallbacks[0])();

        $this->assertTrue($buffer->isEmpty(), 'Buffer must be drained after the terminating callback fires');
        $this->assertCount(1, $batchesSent, 'Transport must have received exactly one batch');
        $this->assertCount(1, $batchesSent[0], 'Batch must contain the seeded event');
    }
}
