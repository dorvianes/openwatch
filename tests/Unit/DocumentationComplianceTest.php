<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tasks 4.1 & 4.2 — Documentation compliance tests.
 *
 * These tests verify that the mandatory async-worker documentation content
 * exists in the package README and the cliente-demo artifacts.
 *
 * Rationale: under Strict TDD the spec requires documentation scenarios to be
 * proven. Because documentation lives in Markdown/env files (not executable
 * code), the lightest compliant evidence is asserting on file content.
 * These tests are intentionally documentary — they fail immediately if the
 * documented content is removed or misnamed.
 */
class DocumentationComplianceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Package README — async section presence
    // -------------------------------------------------------------------------

    public function test_package_readme_mentions_async_batching_section(): void
    {
        $readme = $this->readPackageReadme();

        $this->assertStringContainsString(
            'Async batching',
            $readme,
            'README must contain an "Async batching" section header',
        );
    }

    public function test_package_readme_documents_openwatch_async_enabled_env_var(): void
    {
        $readme = $this->readPackageReadme();

        $this->assertStringContainsString(
            'OPENWATCH_ASYNC_ENABLED',
            $readme,
            'README must document the OPENWATCH_ASYNC_ENABLED env var',
        );
    }

    public function test_package_readme_documents_queue_worker_requirement(): void
    {
        $readme = $this->readPackageReadme();

        $this->assertStringContainsString(
            'queue:work',
            $readme,
            'README must mention that a queue worker (queue:work) is required',
        );
    }

    public function test_package_readme_documents_fallback_behavior(): void
    {
        $readme = $this->readPackageReadme();

        $this->assertStringContainsString(
            'fallback',
            strtolower($readme),
            'README must describe the sync fallback behaviour on dispatch failure',
        );
    }

    // -------------------------------------------------------------------------
    // cliente-demo .env.example — async config exposure
    // -------------------------------------------------------------------------

    public function test_cliente_demo_env_example_exposes_async_enabled_var(): void
    {
        $env = $this->readClienteDemoEnvExample();

        $this->assertStringContainsString(
            'OPENWATCH_ASYNC_ENABLED',
            $env,
            'cliente-demo .env.example must expose OPENWATCH_ASYNC_ENABLED',
        );
    }

    public function test_cliente_demo_env_example_exposes_async_connection_var(): void
    {
        $env = $this->readClienteDemoEnvExample();

        $this->assertStringContainsString(
            'OPENWATCH_ASYNC_CONNECTION',
            $env,
            'cliente-demo .env.example must expose OPENWATCH_ASYNC_CONNECTION',
        );
    }

    public function test_cliente_demo_env_example_exposes_async_queue_var(): void
    {
        $env = $this->readClienteDemoEnvExample();

        $this->assertStringContainsString(
            'OPENWATCH_ASYNC_QUEUE',
            $env,
            'cliente-demo .env.example must expose OPENWATCH_ASYNC_QUEUE',
        );
    }

    public function test_cliente_demo_env_example_mentions_queue_worker(): void
    {
        $env = $this->readClienteDemoEnvExample();

        $this->assertStringContainsString(
            'queue worker',
            strtolower($env),
            'cliente-demo .env.example must mention that a queue worker is required',
        );
    }

    // -------------------------------------------------------------------------
    // cliente-demo README — local async testing instructions
    // -------------------------------------------------------------------------

    public function test_cliente_demo_readme_mentions_async_setup(): void
    {
        $readme = $this->readClienteDemoReadme();

        $this->assertStringContainsString(
            'OPENWATCH_ASYNC',
            $readme,
            'cliente-demo README must reference OPENWATCH_ASYNC config',
        );
    }

    public function test_cliente_demo_readme_mentions_queue_work_command(): void
    {
        $readme = $this->readClienteDemoReadme();

        $this->assertStringContainsString(
            'queue:work',
            $readme,
            'cliente-demo README must show the queue:work command for local testing',
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function readPackageReadme(): string
    {
        $path = dirname(__DIR__, 2) . '/README.md';
        $this->assertFileExists($path, 'Package README.md must exist');
        return file_get_contents($path);
    }

    private function readClienteDemoEnvExample(): string
    {
        $path = dirname(__DIR__, 3) . '/cliente-demo/.env.example';
        $this->assertFileExists($path, 'cliente-demo/.env.example must exist');
        return file_get_contents($path);
    }

    private function readClienteDemoReadme(): string
    {
        $path = dirname(__DIR__, 3) . '/cliente-demo/README.md';
        $this->assertFileExists($path, 'cliente-demo/README.md must exist');
        return file_get_contents($path);
    }
}
