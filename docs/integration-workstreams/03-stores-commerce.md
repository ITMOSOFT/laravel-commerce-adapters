# Stores ↔ Products, Carts and Orders

**Status:** planned; not implemented by this archive.

## Contract to settle

Specify store ownership and context resolution for catalog items, carts, checkout sessions, and orders. Durable orders must snapshot store identity so later store changes do not rewrite history.

## Minimum acceptance tests

Store-scoped product lookup; reject adding a product from another store to a cart; reject checkout when cart store and selected store differ; changing store on a non-empty cart follows a documented reject/rebuild rule; order stores remain unchanged if store metadata changes later; verify guest and authenticated flows; test cross-store IDOR attempts.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
