# Disputes ↔ Escrow

**Status:** planned; not implemented by this archive.

## Contract to settle

Define which dispute states freeze escrow, who may decide release/refund, and how partial outcomes are represented. Disputes owns case decision; Escrow owns funds state.

## Minimum acceptance tests

Opening eligible dispute freezes releasable funds; duplicate dispute event does not double-freeze; unauthorized actor cannot resolve; full release, full refund, and partial split outcomes; invalid transitions rejected; concurrent resolution is serialized; duplicate resolution command is idempotent; escrow ledger balances before and after each outcome; closed dispute cannot be reopened without explicit appeal flow.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
