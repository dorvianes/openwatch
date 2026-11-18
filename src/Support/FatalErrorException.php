<?php

namespace Dorvianes\OpenWatch\Support;

use ErrorException;

/**
 * Throwable representation of a PHP fatal error captured at shutdown.
 *
 * Fatal errors (memory exhaustion, `max_execution_time`, parse errors) never
 * reach Laravel's `report()` path as a Throwable, so the shutdown handler
 * synthesises one from `error_get_last()`. The class name is what the OpenWatch
 * server displays, which is why it is deliberately distinct from a regular
 * exception: an operator must be able to tell "this killed the process" apart
 * from "this was caught and reported".
 */
final class FatalErrorException extends ErrorException
{
    /**
     * @param array{type?: int, message?: string, file?: string, line?: int} $error
     */
    public function __construct(array $error)
    {
        parent::__construct(
            (string) ($error['message'] ?? 'Fatal error'),
            0,
            (int) ($error['type'] ?? E_ERROR),
            (string) ($error['file'] ?? 'unknown'),
            (int) ($error['line'] ?? 0),
        );
    }
}
