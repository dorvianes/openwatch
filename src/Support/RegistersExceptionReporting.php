<?php

namespace Dorvianes\OpenWatch\Support;

use Dorvianes\OpenWatch\Recorders\ExceptionRecorder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Official integration helper for wiring OpenWatch exception capture
 * into a Laravel application via the bootstrap/app.php exception configurator.
 *
 * Usage in bootstrap/app.php:
 *
 *   ->withExceptions(function (Exceptions $exceptions): void {
 *       \Dorvianes\OpenWatch\Support\RegistersExceptionReporting::register($exceptions);
 *   })
 */
final class RegistersExceptionReporting
{
    /**
     * Static marker set to true when register() has been called successfully.
     * Checked by SendTestCommand to detect missing bootstrap wiring.
     */
    private static bool $registered = false;

    /**
     * Returns true if register() was called during bootstrap.
     * Used by SendTestCommand to warn when exception reporting is not wired.
     */
    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    /**
     * Register an OpenWatch exception-reporting callback with Laravel's exception configurator.
     *
     * The callback:
     * - Resolves ExceptionRecorder from the container.
     * - Resolves the current Request if available, otherwise passes null.
     * - Swallows any OpenWatch-side failure so the host app is never broken.
     * - Emits a debug-level log entry when OpenWatch itself fails (misconfiguration, transport error).
     * - Does NOT stop Laravel's normal reporting flow (no return value).
     */
    public static function register(Exceptions $exceptions): void
    {
        self::$registered = true;

        $exceptions->report(static function (\Throwable $e): void {
            try {
                /** @var ExceptionRecorder $recorder */
                $recorder = app(ExceptionRecorder::class);

                // Request may not be bound yet (e.g. CLI, early boot errors)
                $request = null;
                try {
                    $candidate = app(Request::class);
                    if ($candidate instanceof Request) {
                        $request = $candidate;
                    }
                } catch (\Throwable) {
                    // Not available — proceed without request context
                }

                $recorder->record($e, $request);
            } catch (\Throwable $owException) {
                // Swallow every OpenWatch failure — the host app must never break.
                // Emit a debug log so developers can diagnose misconfiguration
                // without noise in production logs.
                try {
                    Log::debug('[OpenWatch] Exception reporting failed: ' . $owException->getMessage(), [
                        'openwatch_exception' => get_class($owException),
                    ]);
                } catch (\Throwable) {
                    // Even logging failed — completely silent fallback.
                }
            }
        });
    }
}
