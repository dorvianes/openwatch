<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Support\UserContextEnricher;
use PHPUnit\Framework\TestCase;

class UserContextEnricherTest extends TestCase
{
    /**
     * Build a testable enricher with explicit user and IP.
     */
    private function makeEnricher(?object $user = null, ?string $ip = null, bool $throwOnResolve = false): UserContextEnricher
    {
        return new class($user, $ip, $throwOnResolve) extends UserContextEnricher {
            public function __construct(
                private readonly ?object $user,
                private readonly ?string $ip,
                private readonly bool $throwOnResolve,
            ) {}

            protected function resolveUser(): ?object
            {
                if ($this->throwOnResolve) {
                    throw new \RuntimeException('auth facade exploded');
                }
                return $this->user;
            }

            protected function resolveIp(): ?string
            {
                if ($this->throwOnResolve) {
                    throw new \RuntimeException('request facade exploded');
                }
                return $this->ip;
            }
        };
    }

    private function makeAuthenticatedUser(string $id = '42', string $type = 'App\Models\User'): object
    {
        return new class($id, $type) {
            public string $id;
            public string $morphClass;

            public function __construct(string $id, string $morphClass)
            {
                $this->id = $id;
                $this->morphClass = $morphClass;
            }

            public function getAuthIdentifier(): string
            {
                return $this->id;
            }

            public function getMorphClass(): string
            {
                return $this->morphClass;
            }
        };
    }

    // -------------------------------------------------------------------------
    // User identity — authenticated
    // -------------------------------------------------------------------------

    public function test_authenticated_user_yields_correct_user_id(): void
    {
        $user     = $this->makeAuthenticatedUser('42', 'App\Models\User');
        $enricher = $this->makeEnricher(user: $user);

        $context = $enricher->getUserContext();

        $this->assertSame('42', $context['user_id']);
        $this->assertSame('App\Models\User', $context['user_type']);
    }

    public function test_authenticated_user_with_numeric_id_casts_to_string(): void
    {
        $user     = $this->makeAuthenticatedUser('789', 'App\Models\Admin');
        $enricher = $this->makeEnricher(user: $user);

        $context = $enricher->getUserContext();

        $this->assertSame('789', $context['user_id']);
        $this->assertIsString($context['user_id']);
    }

    // -------------------------------------------------------------------------
    // User identity — unauthenticated
    // -------------------------------------------------------------------------

    public function test_unauthenticated_yields_null_user_id_and_user_type(): void
    {
        $enricher = $this->makeEnricher(user: null);

        $context = $enricher->getUserContext();

        $this->assertNull($context['user_id']);
        $this->assertNull($context['user_type']);
    }

    // -------------------------------------------------------------------------
    // IP resolution — HTTP context
    // -------------------------------------------------------------------------

    public function test_http_context_yields_client_ip(): void
    {
        $enricher = $this->makeEnricher(user: null, ip: '192.168.1.100');

        $context = $enricher->getUserContext();

        $this->assertSame('192.168.1.100', $context['ip']);
    }

    public function test_cli_or_queued_context_yields_null_ip(): void
    {
        $enricher = $this->makeEnricher(user: null, ip: null);

        $context = $enricher->getUserContext();

        $this->assertNull($context['ip']);
    }

    // -------------------------------------------------------------------------
    // Resilience — facade failures
    // -------------------------------------------------------------------------

    public function test_facade_exception_yields_null_user_fields(): void
    {
        $enricher = $this->makeEnricher(throwOnResolve: true);

        $context = $enricher->getUserContext();

        $this->assertNull($context['user_id']);
        $this->assertNull($context['user_type']);
        $this->assertNull($context['ip']);
    }

    public function test_user_context_never_throws(): void
    {
        $enricher = $this->makeEnricher(throwOnResolve: true);

        // Must not throw — resilience contract
        $this->expectNotToPerformAssertions();
        $enricher->getUserContext();
    }

    // -------------------------------------------------------------------------
    // enrich() — merge behavior
    // -------------------------------------------------------------------------

    public function test_enrich_merges_user_context_into_meta(): void
    {
        $user     = $this->makeAuthenticatedUser('99', 'App\Models\Member');
        $enricher = $this->makeEnricher(user: $user, ip: '10.0.0.1');

        $meta = [
            'app_name' => 'OpenWatch',
            'app_env'  => 'production',
        ];

        $enriched = $enricher->enrich($meta);

        $this->assertSame('OpenWatch', $enriched['app_name']);
        $this->assertSame('production', $enriched['app_env']);
        $this->assertSame('99', $enriched['user_id']);
        $this->assertSame('App\Models\Member', $enriched['user_type']);
        $this->assertSame('10.0.0.1', $enriched['ip']);
    }

    public function test_enrich_does_not_overwrite_existing_meta_keys(): void
    {
        $user     = $this->makeAuthenticatedUser('55', 'App\Models\User');
        $enricher = $this->makeEnricher(user: $user, ip: '127.0.0.1');

        $meta = [
            'app_name' => 'MyApp',
            'app_env'  => 'staging',
        ];

        $enriched = $enricher->enrich($meta);

        // Existing keys must be preserved
        $this->assertSame('MyApp', $enriched['app_name']);
        $this->assertSame('staging', $enriched['app_env']);
        // New keys must be present
        $this->assertSame('55', $enriched['user_id']);
        $this->assertSame('App\Models\User', $enriched['user_type']);
        $this->assertSame('127.0.0.1', $enriched['ip']);
    }

    // -------------------------------------------------------------------------
    // Context structure completeness
    // -------------------------------------------------------------------------

    public function test_getUserContext_returns_expected_keys(): void
    {
        $enricher = $this->makeEnricher(user: null, ip: null);

        $context = $enricher->getUserContext();

        $this->assertIsArray($context);
        $this->assertArrayHasKey('user_id', $context);
        $this->assertArrayHasKey('user_type', $context);
        $this->assertArrayHasKey('ip', $context);
        $this->assertCount(3, $context);
    }
}
