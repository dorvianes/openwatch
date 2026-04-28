<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Task 1.1 — Async config keys existence and defaults.
 *
 * Strategy: load the REAL config/openwatch.php by setting/unsetting env vars
 * around each evaluation. This validates the actual production config file
 * (key names, default values, type coercions via Laravel's env() helper)
 * instead of a local test-helper array.
 *
 * Prerequisites: illuminate/support + vlucas/phpdotenv in require-dev so that
 * the env() helper and PhpOption\Option are available in standalone PHPUnit.
 */
class AsyncConfigTest extends TestCase
{
    /**
     * Evaluate the real config/openwatch.php file with optional env overrides.
     *
     * Env vars are set before the require and unset immediately after, so
     * successive tests do not bleed state into each other.
     *
     * @param  array<string, string> $envOverrides
     * @return array<string, mixed>
     */
    private function loadConfig(array $envOverrides = []): array
    {
        foreach ($envOverrides as $key => $value) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }

        $config = require dirname(__DIR__, 2) . '/config/openwatch.php';

        foreach ($envOverrides as $key => $_) {
            unset($_ENV[$key]);
            putenv($key); // removes the variable
        }

        return $config;
    }

    // -------------------------------------------------------------------------
    // Scenario: Defaults apply — reading real config/openwatch.php
    // -------------------------------------------------------------------------

    public function test_real_config_has_async_key_under_batching(): void
    {
        $config = $this->loadConfig();

        $this->assertArrayHasKey('batching', $config, 'config must have a batching key');
        $this->assertArrayHasKey('async', $config['batching'], 'batching must have an async sub-key');
    }

    public function test_real_config_async_enabled_defaults_to_false(): void
    {
        $config = $this->loadConfig();

        $this->assertArrayHasKey('enabled', $config['batching']['async']);
        $this->assertFalse($config['batching']['async']['enabled']);
    }

    public function test_real_config_async_connection_defaults_to_null(): void
    {
        $config = $this->loadConfig();

        $this->assertArrayHasKey('connection', $config['batching']['async']);
        $this->assertNull($config['batching']['async']['connection']);
    }

    public function test_real_config_async_queue_defaults_to_null(): void
    {
        $config = $this->loadConfig();

        $this->assertArrayHasKey('queue', $config['batching']['async']);
        $this->assertNull($config['batching']['async']['queue']);
    }

    // -------------------------------------------------------------------------
    // Scenario: Env overrides apply — injecting env vars before loading file
    // -------------------------------------------------------------------------

    public function test_real_config_async_enabled_reads_env_override(): void
    {
        $config = $this->loadConfig(['OPENWATCH_ASYNC_ENABLED' => 'true']);

        $this->assertTrue($config['batching']['async']['enabled']);
    }

    public function test_real_config_async_connection_reads_env_override(): void
    {
        $config = $this->loadConfig(['OPENWATCH_ASYNC_CONNECTION' => 'redis']);

        $this->assertSame('redis', $config['batching']['async']['connection']);
    }

    public function test_real_config_async_queue_reads_env_override(): void
    {
        $config = $this->loadConfig(['OPENWATCH_ASYNC_QUEUE' => 'openwatch']);

        $this->assertSame('openwatch', $config['batching']['async']['queue']);
    }
}
