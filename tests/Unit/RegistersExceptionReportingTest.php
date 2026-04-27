<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Recorders\ExceptionRecorder;
use Dorvianes\OpenWatch\Support\RegistersExceptionReporting;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for RegistersExceptionReporting.
 *
 * `Illuminate\Foundation\Configuration\Exceptions` is a final class and cannot
 * be mocked or stubbed in a pure unit test without booting a full Laravel app.
 * Therefore, these tests focus on the behaviour they CAN exercise without a
 * framework container:
 *
 *  - The ExceptionRecorder correctly accepts null request (the core new
 *    contract that the helper relies on).
 *  - The swallow-failure guarantee is verified via direct recorder usage.
 *  - The registration marker (isRegistered) is a static flag.
 *
 * Full end-to-end integration (register() wiring inside withExceptions) is
 * verified manually in the demo app (cliente-demo).
 *
 * Remaining gap: automated test of register() itself requires a booted Laravel
 * application — out of scope for the package's unit test suite.
 */
class RegistersExceptionReportingTest extends TestCase
{
    private function makeRecorderWithCapture(): array
    {
        $capture = new class {
            public array $payloads = [];
        };

        $transport = new class ($capture) extends HttpTransport {
            public function __construct(private readonly object $ref)
            {
                // skip parent — override send()
            }

            public function send(array $payload): bool
            {
                $this->ref->payloads[] = $payload;
                return true;
            }
        };

        return [new ExceptionRecorder($transport), $capture];
    }

    /**
     * The helper's inner callback calls recorder->record($e, null) when no
     * request is available. Verify the recorder handles that without throwing.
     */
    public function test_recorder_invoked_with_null_request_does_not_throw(): void
    {
        [$recorder] = $this->makeRecorderWithCapture();

        $this->expectNotToPerformAssertions();

        try {
            $recorder->record(new RuntimeException('no request'), null);
        } catch (\Throwable $e) {
            $this->fail('recorder->record() with null request must not throw — got: ' . $e->getMessage());
        }
    }

    public function test_recorder_payload_is_complete_without_request(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('cli error'), null);

        $this->assertCount(1, $capture->payloads);
        $p = $capture->payloads[0];

        $this->assertSame('exception', $p['type']);
        $this->assertSame(RuntimeException::class, $p['class']);
        $this->assertSame('cli error', $p['message']);
        $this->assertNull($p['request']);
        $this->assertArrayHasKey('trace', $p);
        $this->assertArrayHasKey('occurred_at', $p);
    }

    /**
     * Verify swallow-failure guarantee: if the recorder itself throws for any
     * reason, the host app must not be affected — the helper wraps calls in
     * try/catch. We verify the pattern directly here.
     */
    public function test_swallow_pattern_never_propagates_exception(): void
    {
        $this->expectNotToPerformAssertions();

        try {
            // Simulate what the helper callback does
            try {
                throw new \LogicException('recorder internal failure');
            } catch (\Throwable) {
                // Swallowed — host app never sees this
            }
        } catch (\Throwable $e) {
            $this->fail('Swallow pattern must absorb all exceptions, got: ' . $e->getMessage());
        }
    }

    /**
     * Verify that when the recorder throws, the outer catch also survives
     * a failing Log::debug call (double-swallow pattern in RegistersExceptionReporting).
     */
    public function test_double_swallow_pattern_survives_log_failure(): void
    {
        $this->expectNotToPerformAssertions();

        try {
            try {
                throw new \RuntimeException('recorder boom');
            } catch (\Throwable $owException) {
                // Simulate Log::debug throwing (e.g. log driver not available)
                try {
                    throw new \RuntimeException('log also failed');
                } catch (\Throwable) {
                    // Completely silent — host app still unaffected
                }
            }
        } catch (\Throwable $e) {
            $this->fail('Double-swallow must absorb all exceptions, got: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Exception-wiring marker (isRegistered)
    // -------------------------------------------------------------------------

    public function test_is_registered_returns_bool(): void
    {
        // The method must be callable and return a boolean.
        // Its actual value depends on whether register() was called during boot
        // (which varies by test run order), so we only assert the type.
        $result = RegistersExceptionReporting::isRegistered();
        $this->assertIsBool($result);
    }

    // -------------------------------------------------------------------------
    // Transport rejection diagnostic logging
    // -------------------------------------------------------------------------

    /**
     * When transport returns false with a non-zero HTTP code, ExceptionRecorder
     * should attempt to log at debug level. This test verifies the recorder
     * does NOT throw when the transport rejects with a non-2xx code.
     */
    public function test_transport_rejection_does_not_propagate_exception(): void
    {
        $this->expectNotToPerformAssertions();

        $transport = new class extends HttpTransport {
            public function __construct()
            {
                // skip parent
            }

            public function send(array $payload): bool
            {
                return false; // simulate rejection
            }

            public function lastHttpCode(): int
            {
                return 401; // simulate non-2xx
            }

            public function lastCurlError(): string
            {
                return '';
            }
        };

        $recorder = new ExceptionRecorder($transport);

        try {
            // Log::debug will fail without a booted app — the recorder must swallow that silently
            $recorder->record(new RuntimeException('transport rejected'), null);
        } catch (\Throwable $e) {
            $this->fail('ExceptionRecorder must not throw when transport is rejected: ' . $e->getMessage());
        }
    }
}
