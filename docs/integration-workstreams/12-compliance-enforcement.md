# Compliance ↔ Contracts/Suppliers

**Status:** planned; not implemented by this archive.

## Contract to settle

Define policy evaluation points and blocking behavior for supplier onboarding, contract creation/signing/renewal, and procurement. Enforcement must be explicit and auditable; avoid hidden mutation from read operations.

## Minimum acceptance tests

Compliant record passes; missing/expired evidence blocks configured action; policy result includes reason and policy version; authorized override requires actor/reason and audit event; read-only evaluation has no side effects; policy changes do not silently rewrite signed contracts; renewal re-evaluates current requirements; test fail-closed behavior when compliance service unavailable.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
