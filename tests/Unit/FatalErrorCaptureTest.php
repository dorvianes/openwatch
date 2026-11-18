<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Recorders\ExceptionRecorder;
use Dorvianes\OpenWatch\Support\FatalErrorCapture;
use Dorvianes\OpenWatch\Support\FatalErrorException;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Unit tests for fatal-error capture (backlog A1).
 *
 * OOM, max_execution_time and parse errors never reach Laravel's report() path
 * with a Throwable the package can see, so they used to vanish. The capture is a
 * register_shutdown_function handler that inspects error_get_last().
 *
 * Strategy: handleShutdown() accepts the error array explicitly (null = real
 * error_get_last()), which keeps the handler testable without killing the test
 * process, and the recorder is injected through the register() resolver.
 */
class FatalErrorCaptureTest extends TestCase
{
    protected function tearDown(): void
    {
        FatalErrorCapture::reset();
    }

    /**
     * @return array{0: ExceptionRecorder, 1: object}
     */
    private function makeCaptureRecorder(): array
    {
        $capture = new class {
            /** @var list<array{exception: Throwable, request: ?Request, includeContext: bool}> */
            public array $records = [];
        };

        $recorder = new class($capture) extends ExceptionRecorder {
            public function __construct(private readonly object $capture)
            {
                // No parent call — record() is fully overridden.
            }

            public function record(Throwable $exception, ?Request $request = null, bool $includeContext = true): void
            {
                $this->capture->records[] = [
                    'exception'      => $exception,
                    'request'        => $request,
                    'includeContext' => $includeContext,
                ];
            }
        };

        return [$recorder, $capture];
    }

    /** @return array{type: int, message: string, file: string, line: int} */
    private function fatalError(array $overrides = []): array
    {
        return $overrides + [
            'type'    => E_ERROR,
            'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
            'file'    => '/var/www/app/Http/Controllers/ReportController.php',
            'line'    => 88,
        ];
    }

    // -------------------------------------------------------------------------
    // Capture
    // -------------------------------------------------------------------------

    public function test_fatal_error_at_shutdown_is_captured(): void
    {
        [$recorder, $capture] = $this->makeCaptureRecorder();
        FatalErrorCapture::register(fn (): ExceptionRecorder => $recorder);

        FatalErrorCapture::handleShutdown($this->fatalError());

        $this->assertCount(1, $capture->records);
        $exception = $capture->records[0]['exception'];
        $this->assertInstanceOf(FatalErrorException::class, $exception);
        $this->assertSame(
            'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
            $exception->getMessage(),
        );
        $this->assertSame('/var/www/app/Http/Controllers/ReportController.php', $exception->getFile());
        $this->assertSame(88, $exception->getLine());
    }

    public function test_capture_is_inert_when_not_registered(): void
    {
        [$recorder, $capture] = $this->makeCaptureRecorder();
        // No register() call — e.g. OpenWatch disabled or not configured.

        FatalErrorCapture::handleShutdown($this->fatalError());

        $this->assertCount(0, $capture->records);
    }

    public function test_recorder_failure_never_escapes_the_shutdown_handler(): void
    {
        FatalErrorCapture::register(static function (): ExceptionRecorder {
            throw new RuntimeException('container unavailable at shutdown');
        });

        $this->expectNotToPerformAssertions();

        try {
            FatalErrorCapture::handleShutdown($this->fatalError());
        } catch (Throwable $e) {
            $this->fail('The shutdown handler must never throw — got: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Guards: what must NOT be captured
    // -------------------------------------------------------------------------

    public function test_non_fatal_errors_are_ignored(): void
    {
        [$recorder, $capture] = $this->makeCaptureRecorder();
        FatalErrorCapture::register(fn (): ExceptionRecorder => $recorder);

        foreach ([E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING, E_USER_NOTICE] as $type) {
            FatalErrorCapture::handleShutdown($this->fatalError(['type' => $type]));
        }

        $this->assertCount(0, $capture->records);
    }

    public function test_error_already_reported_is_not_sent_twice(): void
    {
        [$recorder, $capture] = $this->makeCaptureRecorder();
        FatalErrorCapture::register(fn (): ExceptionRecorder => $recorder);

        // Laravel's own shutdown handler converts the fatal into a Throwable and
        // reports it first; the package marks whatever it reported.
        FatalErrorCapture::markReported(new \ErrorException(
            'Allowed memory size of 134217728 bytes exhausted',
            0,
            E_ERROR,
            '/var/www/app/Http/Controllers/ReportController.php',
            88,
        ));

        FatalErrorCapture::handleShutdown($this->fatalError());

        $this->assertCount(0, $capture->records);
    }

    public function test_a_different_location_is_still_captured_after_another_report(): void
    {
        [$recorder, $capture] = $this->makeCaptureRecorder();
        FatalErrorCapture::register(fn (): ExceptionRecorder => $recorder);

        FatalErrorCapture::markReported(new \ErrorException('other', 0, E_ERROR, '/other/file.php', 10));

        FatalErrorCapture::handleShutdown($this->fatalError());

        $this->assertCount(1, $capture->records);
    }

    // -------------------------------------------------------------------------
    // Memory guard
    // -------------------------------------------------------------------------

    public function test_memory_limit_is_parsed_into_bytes(): void
    {
        $this->assertSame(128 * 1024 * 1024, FatalErrorCapture::memoryLimitBytes('128M'));
        $this->assertSame(512 * 1024 * 1024, FatalErrorCapture::memoryLimitBytes('512m'));
        $this->assertSame(1024 * 1024 * 1024, FatalErrorCapture::memoryLimitBytes('1G'));
        $this->assertSame(256 * 1024, FatalErrorCapture::memoryLimitBytes('256K'));
        $this->assertSame(134217728, FatalErrorCapture::memoryLimitBytes('134217728'));
        $this->assertNull(FatalErrorCapture::memoryLimitBytes('-1'));
        $this->assertNull(FatalErrorCapture::memoryLimitBytes(''));
    }

    public function test_headroom_requires_enough_free_memory_for_the_payload(): void
    {
        $mib = 1024 * 1024;

        // Unlimited memory: always room for the full payload.
        $this->assertTrue(FatalErrorCapture::hasHeadroom(null, 1024 * $mib));
        // 128 MiB limit with 100 MiB in use → 28 MiB free.
        $this->assertTrue(FatalErrorCapture::hasHeadroom(128 * $mib, 100 * $mib));
        // 128 MiB limit with 126 MiB in use → 2 MiB free: degrade, do not allocate.
        $this->assertFalse(FatalErrorCapture::hasHeadroom(128 * $mib, 126 * $mib));
    }

    public function test_capture_can_skip_context_when_memory_is_low(): void
    {
        [$recorder, $capture] = $this->makeCaptureRecorder();
        FatalErrorCapture::register(fn (): ExceptionRecorder => $recorder);

        FatalErrorCapture::capture($this->fatalError(), includeContext: false);

        $this->assertCount(1, $capture->records);
        $this->assertFalse($capture->records[0]['includeContext']);
    }

    // -------------------------------------------------------------------------
    // Real payload through the real recorder
    // -------------------------------------------------------------------------

    /**
     * @return array{0: ExceptionRecorder, 1: object}
     */
    private function makeRealRecorder(): array
    {
        $capture = new class {
            /** @var list<array<string, mixed>> */
            public array $payloads = [];
        };

        $transport = new class($capture) extends \Dorvianes\OpenWatch\Transport\HttpTransport {
            public function __construct(private readonly object $capture)
            {
                // No parent call — send() is fully overridden.
            }

            public function send(array $payload): bool
            {
                $this->capture->payloads[] = $payload;
                return true;
            }
        };

        $enricher = new class extends \Dorvianes\OpenWatch\Support\UserContextEnricher {
            public function getUserContext(): array
            {
                return ['user_id' => null, 'user_type' => null, 'ip' => null];
            }
        };

        return [new ExceptionRecorder($transport, $enricher), $capture];
    }

    public function test_fatal_is_sent_as_an_exception_event_without_a_meaningless_trace(): void
    {
        [$recorder, $capture] = $this->makeRealRecorder();
        FatalErrorCapture::register(fn (): ExceptionRecorder => $recorder);

        FatalErrorCapture::handleShutdown($this->fatalError());

        $this->assertCount(1, $capture->payloads);
        $payload = $capture->payloads[0];

        $this->assertSame('exception', $payload['type']);
        $this->assertSame(FatalErrorException::class, $payload['class']);
        $this->assertSame(
            'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
            $payload['message'],
        );
        $this->assertSame('/var/www/app/Http/Controllers/ReportController.php', $payload['file']);
        $this->assertSame(88, $payload['line']);
        // A trace built inside a shutdown handler describes the handler, not the failure.
        $this->assertSame([], $payload['trace']);
        $this->assertArrayHasKey('occurred_at', $payload);
    }

    public function test_degraded_payload_drops_the_code_snippet(): void
    {
        [$recorder, $capture] = $this->makeRealRecorder();
        FatalErrorCapture::register(fn (): ExceptionRecorder => $recorder);

        // The file does not exist, so the only way snippet can be non-null is the
        // context path being taken. Degraded capture must not attempt it at all.
        FatalErrorCapture::capture($this->fatalError(), includeContext: false);

        $payload = $capture->payloads[0];
        $this->assertNull($payload['snippet']);
        $this->assertSame('exception', $payload['type']);
    }
}
