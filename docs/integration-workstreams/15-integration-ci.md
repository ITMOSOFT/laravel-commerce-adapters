# Integration CI and compatibility matrices

**Status:** planned; not implemented by this archive.

## Contract to settle

Standardize how optional package dependencies are resolved and tested. Matrix jobs must independently resolve their dependency graph and exercise supported PHP/Laravel versions and database engines.

## Minimum acceptance tests

CI covers every declared PHP/Laravel support pair; lowest and stable dependency jobs; each optional integration has a job with required packages installed and a base job without optional packages; Composer validate; static analysis/style checks if adopted; SQLite plus MySQL for locking/JSON/decimal behavior; cache-safe clean install; no stale lockfile assumptions; release blocked on any failed matrix job.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
