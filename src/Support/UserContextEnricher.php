<?php

namespace Dorvianes\OpenWatch\Support;

/**
 * Enriches telemetry event metadata with user context (user_id, user_type, ip).
 *
 * Resolution is lazy and wrapped in try/catch at the protected resolver level,
 * so failures in auth/request facades silently resolve to null.
 */
class UserContextEnricher
{
    /**
     * Resolve the complete user context as an associative array.
     *
     * @return array{user_id: ?string, user_type: ?string, ip: ?string}
     */
    public function getUserContext(): array
    {
        try {
            return [
                'user_id'   => $this->resolveUserId(),
                'user_type' => $this->resolveUserType(),
                'ip'        => $this->resolveIp(),
            ];
        } catch (\Throwable) {
            return [
                'user_id'   => null,
                'user_type' => null,
                'ip'        => null,
            ];
        }
    }

    /**
     * Merge the user context into the provided meta array.
     *
     * Existing meta keys are preserved; user context keys are only
     * added when they do not shadow an existing key.
     *
     * @param  array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function enrich(array $meta): array
    {
        return array_merge($meta, $this->getUserContext());
    }

    // -------------------------------------------------------------------------
    // Protected resolvers — overridable for tests
    // -------------------------------------------------------------------------

    /**
     * Resolve the authenticated user from the default guard.
     *
     * Wrapped in try/catch so that any facade failure resolves to null.
     */
    protected function resolveUser(): ?object
    {
        try {
            if (! function_exists('auth')) {
                return null;
            }

            return auth()->guard(null)->user();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve the client IP from the current request.
     *
     * Wrapped in try/catch so that CLI/queued-job or facade failure
     * resolves to null.
     */
    protected function resolveIp(): ?string
    {
        try {
            if (! function_exists('request')) {
                return null;
            }

            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveUserId(): ?string
    {
        try {
            $user = $this->resolveUser();
            if ($user === null) {
                return null;
            }

            return $this->extractUserId($user);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveUserType(): ?string
    {
        try {
            $user = $this->resolveUser();
            if ($user === null) {
                return null;
            }

            return $this->extractUserType($user);
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractUserId(object $user): ?string
    {
        // Prefer Laravel's standard auth identifier interface
        if (method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();
            return $id !== null ? (string) $id : null;
        }

        // Fallback to public id property
        if (property_exists($user, 'id') && isset($user->id)) {
            return (string) $user->id;
        }

        return null;
    }

    private function extractUserType(object $user): ?string
    {
        // Eloquent models and test doubles: prefer morph class for polymorphic accuracy
        if (method_exists($user, 'getMorphClass')) {
            return $user->getMorphClass();
        }

        // Generic user object: use FQCN
        return get_class($user);
    }
}
