# Spec: package-hardening-lote-2

## Domain: sql-normalization

### Requirement: Whitespace MUST be collapsed
The normalizer MUST collapse runs of whitespace into a single space and trim leading/trailing whitespace.

#### Scenario: Multiple spaces and newlines
- GIVEN input `select  *\n from\tusers where id = ?`
- WHEN normalized
- THEN result equals `select * from users where id = ?`

#### Scenario: Leading and trailing whitespace
- GIVEN input `   SELECT 1   `
- WHEN normalized
- THEN result equals `SELECT 1`

### Requirement: Placeholder-only IN lists MUST normalize to `IN (?+)`
The normalizer MUST rewrite `IN (...)` clauses containing only positional placeholders into `IN (?+)`.

#### Scenario: Standard IN list
- GIVEN `select * from users where id in (?, ?, ?)`
- WHEN normalized
- THEN result equals `select * from users where id in (?+)`

#### Scenario: Single placeholder IN list
- GIVEN `select * from t where x IN (?)`
- WHEN normalized
- THEN result equals `select * from t where x IN (?+)`

#### Scenario: Non-placeholder IN stays intact
- GIVEN `select * from t where status in ('a','b')`
- WHEN normalized
- THEN the IN clause is preserved

### Requirement: Normalizer MUST be pure and idempotent

#### Scenario: Empty string
- GIVEN input ``
- WHEN normalized
- THEN result equals `` and no exception is thrown

#### Scenario: Idempotence
- GIVEN any valid SQL string
- WHEN normalized twice
- THEN the second result equals the first

## Domain: query-recording

### Requirement: QueryRecorder MUST send normalized SQL
`QueryRecorder::record()` MUST normalize `$event->sql` before storing it in payload `sql`, for both sync and batched paths.

#### Scenario: Sync path uses normalized SQL
- GIVEN a fake transport and noisy SQL with `IN (?, ?, ?)`
- WHEN record is called with batching disabled
- THEN payload `sql` equals the normalized value

#### Scenario: Batched path uses normalized SQL
- GIVEN batching enabled and a fake buffer
- WHEN record is called with noisy SQL
- THEN the buffered payload contains normalized SQL

#### Scenario: Fail-silent contract is preserved
- GIVEN any normalization or send failure
- WHEN record is called
- THEN no exception escapes to the host app
