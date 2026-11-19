# Quotes/Proposals ↔ Contracts

**Status:** planned; not implemented by this archive.

## Contract to settle

Define which accepted quote/proposal revision is authoritative and snapshot commercial terms into a contract. Do not convert drafts or expired versions.

## Minimum acceptance tests

Only accepted and unexpired revision converts; acceptance tied to exact revision; repeated conversion returns same contract; proposal edits after acceptance do not mutate contract; contract records source document ID/version and pricing/scope snapshot; revoked or superseded proposal behavior explicit; verify currency and line totals.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
