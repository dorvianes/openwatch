# User Context Enrichment Specification

## Purpose

This capability provides centralized resolution of the active user's identity and request IP, injecting them into the metadata of all recorded telemetry events.

## Requirements

### Requirement: Inject user identity

The `UserContextEnricher` MUST extract the authenticated user's ID and type from the default authentication guard, resolving to `null` when unauthenticated.

#### Scenario: User is authenticated

- GIVEN the application is processing a request
- AND a user is authenticated in the default guard
- WHEN the enricher resolves the context
- THEN `user_id` MUST be the authenticated user's ID
- AND `user_type` MUST be the authenticated user's class name

#### Scenario: User is NOT authenticated

- GIVEN the application is processing a request
- AND no user is authenticated
- WHEN the enricher resolves the context
- THEN `user_id` MUST be `null`
- AND `user_type` MUST be `null`

### Requirement: Inject client IP

The `UserContextEnricher` MUST extract the client IP address from the current request, resolving to `null` when not in an HTTP request context.

#### Scenario: Request is present

- GIVEN the application is processing an HTTP request
- WHEN the enricher resolves the context
- THEN `ip` MUST be the client's IP address

#### Scenario: No request context

- GIVEN the application is running in a CLI or queued job context
- WHEN the enricher resolves the context
- THEN `ip` MUST be `null`

### Requirement: Resilient resolution

Enrichment MUST be idempotent, never throw, and resolve to null on failure.

#### Scenario: Facades are unavailable or throw

- GIVEN the `auth` or `request` facades throw an exception during resolution
- WHEN the enricher attempts to resolve context
- THEN the exception MUST be caught
- AND `user_id`, `user_type`, and `ip` MUST resolve to `null`
- AND the application MUST NOT crash
