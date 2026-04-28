<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ignored_hosts derivation in config/openwatch.php.
 *
 * The config file uses PHP's env() helper, which in test context may not be available.
 * We test the derivation logic via a helper function extracted from the config resolution.
 *
 * Design: config/openwatch.php resolves ignored_hosts from:
 *   1. Explicit OPENWATCH_IGNORED_HOSTS override (comma-separated) → replaces derived value
 *   2. Auto-derived from OPENWATCH_SERVER_URL → [host] (without port unless non-standard)
 *   3. Empty/malformed URL → []
 */
class OpenWatchConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Tests for the pure helper function deriveIgnoredHosts()
    // -------------------------------------------------------------------------

    public function test_derives_host_from_valid_https_url(): void
    {
        $hosts = \Dorvianes\OpenWatch\Support\ConfigHelper::deriveIgnoredHosts(
            serverUrl: 'https://openwatch.example.com',
            override: null,
        );

        $this->assertSame(['openwatch.example.com'], $hosts);
    }

    public function test_derives_host_from_http_url(): void
    {
        $hosts = \Dorvianes\OpenWatch\Support\ConfigHelper::deriveIgnoredHosts(
            serverUrl: 'http://monitor.internal',
            override: null,
        );

        $this->assertSame(['monitor.internal'], $hosts);
    }

    public function test_empty_server_url_returns_empty_array(): void
    {
        $hosts = \Dorvianes\OpenWatch\Support\ConfigHelper::deriveIgnoredHosts(
            serverUrl: '',
            override: null,
        );

        $this->assertSame([], $hosts);
    }

    public function test_malformed_url_returns_empty_array(): void
    {
        $hosts = \Dorvianes\OpenWatch\Support\ConfigHelper::deriveIgnoredHosts(
            serverUrl: 'not-a-url',
            override: null,
        );

        $this->assertSame([], $hosts);
    }

    public function test_explicit_override_replaces_derived_value(): void
    {
        $hosts = \Dorvianes\OpenWatch\Support\ConfigHelper::deriveIgnoredHosts(
            serverUrl: 'https://openwatch.example.com',
            override: 'custom.host.com,other.host.com',
        );

        $this->assertSame(['custom.host.com', 'other.host.com'], $hosts);
    }

    public function test_explicit_override_with_empty_string_returns_empty_array(): void
    {
        // An empty string override means "ignore nothing" explicitly
        $hosts = \Dorvianes\OpenWatch\Support\ConfigHelper::deriveIgnoredHosts(
            serverUrl: 'https://openwatch.example.com',
            override: '',
        );

        $this->assertSame([], $hosts);
    }

    public function test_override_trims_whitespace_from_hosts(): void
    {
        $hosts = \Dorvianes\OpenWatch\Support\ConfigHelper::deriveIgnoredHosts(
            serverUrl: 'https://openwatch.example.com',
            override: ' host-a.com , host-b.com ',
        );

        $this->assertSame(['host-a.com', 'host-b.com'], $hosts);
    }

    public function test_url_with_path_only_extracts_host(): void
    {
        $hosts = \Dorvianes\OpenWatch\Support\ConfigHelper::deriveIgnoredHosts(
            serverUrl: 'https://openwatch.example.com/api/v1',
            override: null,
        );

        $this->assertSame(['openwatch.example.com'], $hosts);
    }

    public function test_url_with_port_includes_port_in_host(): void
    {
        $hosts = \Dorvianes\OpenWatch\Support\ConfigHelper::deriveIgnoredHosts(
            serverUrl: 'https://openwatch.example.com:8080',
            override: null,
        );

        $this->assertSame(['openwatch.example.com:8080'], $hosts);
    }
}
