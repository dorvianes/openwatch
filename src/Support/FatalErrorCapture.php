<?php

namespace Dorvianes\OpenWatch\Support;

use Closure;
use Dorvianes\OpenWatch\Recorders\ExceptionRecorder;
use Illuminate\Http\Request;
use Throwable;

/**
 * Captures PHP fatal errors that never reach Laravel's report() hook.
 *
 * Memory exhaustion, `max_execution_time` and parse errors terminate the script
 * without producing a Throwable, so the `$exceptions->report()` callback never
 * fires and those failures — a large share of real 500s — were invisible.
 *
 * Design constraints, in order of priority:
 *
 *  1. Never break the host app. Everything is wrapped, nothing is thrown, and
 *     the package stays silent when disabled or unconfigured.
 *  2. Never report the same error twice. Laravel's own shutdown handler may
 *     already convert the fatal into a Throwable and report it; every reported
 *     error is marked by file:line and the handler skips what was already sent.
 *  3. Never allocate blindly after an OOM. A small reserve is held from boot and
 *     released at shutdown, and the code snippet is only read when the remaining
 *     memory budget allows it.
 *
 * Known limitation: in long-lived workers (Octane, queue workers) a fatal inside
 * a job fails the job without terminating the process, so no shutdown handler
 * runs. Capturing those needs per-job hooks — see backlog item A4.
 */
final class FatalErrorCapture
{
    /** Error types that terminate the script. */
    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /** Reserve held from boot so the handler can allocate after an OOM. */
    private const MEMORY_RESERVE_BYTES = 65536; // 64 KiB

    /** Free memory required before the handler dares to read the code snippet. */
    private const MIN_HEADROOM_BYTES = 8388608; // 8 MiB

    private static bool $registered = false;

    private static ?string $memoryReserve = null;

    /** @var (Closure(): ExceptionRecorder)|null */
    private static ?Closure $recorderResolver = null;

    /** @var array<string, true> file:line keys already reported in this process. */
    private static array $reported = [];

    /**
     * Register the shutdown handler.
     *
     * @param (callable(): ExceptionRecorder)|null $recorderResolver
     *        Optional resolver, used by tests to inject a recorder without a
     *        booted container. Production resolves ExceptionRecorder lazily.
     */
    public static function register(?callable $recorderResolver = null): void
    {
        if ($recorderResolver !== null) {
            self::$recorderResolver = $recorderResolver(...);
        }

        if (self::$registered) {
            return;
        }

        self::$registered = true;

        // Held until the handler runs: after an OOM the payload still needs memory.
        self::$memoryReserve = str_repeat('.', self::MEMORY_RESERVE_BYTES);

        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Shutdown entry point.
     *
     * @param array{type?: int, message?: string, file?: string, line?: int}|null $error
     *        Explicit error array, or null to read error_get_last().
     */
    public static function handleShutdown(?array $error = null): void
    {
        if (! self::$registered) {
            return;
        }

        // Release the reserve before anything else so the capture can allocate.
        self::$memoryReserve = null;

        $error ??= error_get_last();

        if (! is_array($error) || ! self::isFatalError($error) || self::wasReported($error)) {
            return;
        }

        try {
            self::capture($error, self::hasHeadroom(
                self::memoryLimitBytes((string) ini_get('memory_limit')),
                memory_get_usage(true),
            ));
        } catch (Throwable) {
            // Never let telemetry break the exit path of the host app.
        }
    }

    /**
     * Send a fatal error as an exception event.
     *
     * @param array{type?: int, message?: string, file?: string, line?: int} $error
     * @param bool $includeContext Read the code snippet around the failing line.
     *                             Pass false when memory headroom is low.
     */
    public static function capture(array $error, bool $includeContext = true): void
    {
        $exception = new FatalErrorException($error);

        // Mark before sending: if the transport explodes we must not retry from
        // another shutdown hook and duplicate the event.
        self::markReported($exception);

        self::resolveRecorder()->record($exception, self::currentRequest(), includeContext: $includeContext);
    }

    /**
     * Record that a Throwable has already been reported, so the shutdown handler
     * does not send the same failure a second time.
     */
    public static function markReported(Throwable $exception): void
    {
        self::$reported[self::location($exception->getFile(), $exception->getLine())] = true;
    }

    /**
     * @param array{type?: int} $error
     */
    public static function isFatalError(array $error): bool
    {
        return in_array((int) ($error['type'] ?? 0), self::FATAL_TYPES, true);
    }

    /**
     * Parse an ini memory_limit value into bytes.
     * Returns null for unlimited (-1) or an empty value.
     */
    public static function memoryLimitBytes(string $raw): ?int
    {
        $raw = trim($raw);

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g'     => $value * 1024 * 1024 * 1024,
            'm'     => $value * 1024 * 1024,
            'k'     => $value * 1024,
            default => $value,
        };
    }

    /**
     * Whether there is enough free memory left to build a full payload.
     * A null limit means unlimited memory, so there is always room.
     */
    public static function hasHeadroom(?int $limitBytes, int $usedBytes): bool
    {
        return $limitBytes === null || ($limitBytes - $usedBytes) >= self::MIN_HEADROOM_BYTES;
    }

    /**
     * Clear all state. Test seam only — the package registers once per process.
     */
    public static function reset(): void
    {
        self::$registered = false;
        self::$memoryReserve = null;
        self::$recorderResolver = null;
        self::$reported = [];
    }

    // -------------------------------------------------------------------------

    /**
     * @param array{file?: string, line?: int} $error
     */
    private static function wasReported(array $error): bool
    {
        return isset(self::$reported[self::location((string) ($error['file'] ?? ''), (int) ($error['line'] ?? 0))]);
    }

    private static function location(string $file, int $line): string
    {
        return $file . ':' . $line;
    }

    private static function resolveRecorder(): ExceptionRecorder
    {
        $resolver = self::$recorderResolver;

        if ($resolver !== null) {
            return $resolver();
        }

        return app(ExceptionRecorder::class);
    }

    private static function currentRequest(): ?Request
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $candidate = app(Request::class);

            return $candidate instanceof Request ? $candidate : null;
        } catch (Throwable) {
            return null;
        }
    }
}
