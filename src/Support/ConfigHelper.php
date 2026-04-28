<?php

namespace Dorvianes\OpenWatch\Support;

/**
 * Pure helper functions for OpenWatch configuration resolution.
 *
 * Extracted to keep config/openwatch.php readable and the logic unit-testable.
 */
class ConfigHelper
{
    /**
     * Resolve the list of hosts to ignore in outgoing telemetry.
     *
     * Resolution order:
     *   1. If $override is not null → parse it as a comma-separated list (trims whitespace).
     *      An empty string override returns [].
     *   2. If $override is null → auto-derive from $serverUrl.
     *      Returns [host] when URL is valid; [] when empty or malformed.
     *
     * @param  string      $serverUrl  The configured OPENWATCH_SERVER_URL value.
     * @param  string|null $override   The raw OPENWATCH_IGNORED_HOSTS value, or null if not set.
     * @return string[]
     */
    public static function deriveIgnoredHosts(string $serverUrl, ?string $override): array
    {
        // Explicit override always wins — no merging with derived value.
        if ($override !== null) {
            if ($override === '') {
                return [];
            }

            return array_values(array_filter(
                array_map('trim', explode(',', $override)),
                fn (string $h) => $h !== '',
            ));
        }

        // Auto-derive from server_url.
        if ($serverUrl === '') {
            return [];
        }

        $parts = parse_url($serverUrl);

        if ($parts === false || empty($parts['host'])) {
            return [];
        }

        $host = $parts['host'];

        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        return [$host];
    }
}
