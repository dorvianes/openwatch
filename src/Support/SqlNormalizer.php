<?php

namespace Dorvianes\OpenWatch\Support;

class SqlNormalizer
{
    /**
     * Normalize a raw SQL string for safe storage and grouping.
     *
     * Rules applied (deliberately conservative):
     *   1. Trim leading/trailing whitespace.
     *   2. Collapse internal whitespace runs (spaces, tabs, newlines) to a single space.
     *   3. Rewrite `IN (?, ?, …)` — placeholder-only lists — to `IN (?+)`.
     *
     * Anything else (string literals, mixed IN lists, SQL keywords) is left intact.
     * Bindings are never accessed — this method is pure.
     */
    public static function normalize(string $sql): string
    {
        if ($sql === '') {
            return '';
        }

        // Step 1 & 2: collapse whitespace
        $sql = trim(preg_replace('/\s+/', ' ', $sql));

        // Step 3: normalize placeholder-only IN lists — case-insensitive
        // Matches: IN ( <one or more "?" separated by ", "> )
        // Does NOT match mixed lists like IN (?, 1, ?) or string literals
        $sql = preg_replace_callback(
            '/\b(IN)\s*\(\s*\?\s*(?:,\s*\?\s*)*\)/i',
            static fn (array $m): string => $m[1] . ' (?+)',
            $sql,
        );

        return $sql;
    }
}
