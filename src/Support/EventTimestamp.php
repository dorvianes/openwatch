<?php

namespace Dorvianes\OpenWatch\Support;

/**
 * Centralizes ISO-8601 timestamp generation for all package recorders.
 *
 * All event timestamps MUST be captured at the moment the event occurs,
 * NOT at the moment of HTTP transmission. Callers should pass a pre-captured
 * microtime or DateTimeImmutable, defaulting to "now" when not provided.
 *
 * Format: ISO-8601 with timezone offset — e.g. 2026-04-27T14:15:22+00:00
 */
final class EventTimestamp
{
    /**
     * Format a timestamp as ISO-8601 with timezone offset.
     *
     * @param  \DateTimeInterface|float|null $capturedAt
     *   - \DateTimeInterface: formatted directly.
     *   - float (microtime result): converted via DateTimeImmutable.
     *   - null: current time is used.
     */
    public static function format(\DateTimeInterface|float|null $capturedAt = null): string
    {
        if ($capturedAt instanceof \DateTimeInterface) {
            return $capturedAt->format(\DateTimeInterface::ATOM);
        }

        if (is_float($capturedAt)) {
            $intPart  = (int) $capturedAt;
            $fracPart = (int) round(($capturedAt - $intPart) * 1_000_000);
            $dt       = new \DateTimeImmutable('@' . $intPart, new \DateTimeZone('UTC'));
            $dt       = $dt->setMicrosecond($fracPart);

            return $dt->format(\DateTimeInterface::ATOM);
        }

        // Default: now with timezone
        return (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get() ?: 'UTC')))
            ->format(\DateTimeInterface::ATOM);
    }

    /**
     * Capture the current time as a float (microtime) for immediate recording.
     * Call this at the moment the event starts — before any processing.
     */
    public static function now(): float
    {
        return microtime(true);
    }
}
