# Integration Test Matrix

## Existing executable baseline

The included PHPUnit suite covers the eight confirmed adapters in the v0.8.0 baseline. The suite is retained unchanged from the confirmed release archive. It is the only executable integration implementation represented as complete in this bundle.

## Planned workstreams: required acceptance coverage

The detailed test plan is split into one file per workstream under `docs/integration-workstreams/`. These are specifications, not PHPUnit tests. They are intentionally not placed in PHPUnit's discovery path and do not create false-green tests.

| # | Workstream | Minimum acceptance coverage |
|---:|---|---|
| 1 | Catalog ↔ Variants | canonical ID, create/update/delete ownership, variant option uniqueness, legacy duplicate detection, product deletion, rollback |
| 2 | Multitenancy | tenant isolation on reads/writes/jobs/events, missing tenant context, cross-tenant FK attempts, tenant switch, global-scope bypass audit |
| 3 | Stores ↔ Commerce | store-scoped product/cart/order, store change after cart creation, order snapshot immutability, cross-store access rejection |
| 4 | Suppliers ↔ Purchase Orders | supplier validation, inactive supplier, supplier snapshot, PO lifecycle, duplicate conversion, supplier deletion policy |
| 5 | Checkout orchestration | deterministic pricing→promotion→shipping→tax order, recalculation idempotency, stale quote, discount tax basis, rollback |
| 6 | Checkout ↔ Payments | authorize/capture, decline/retry, duplicate webhook, partial capture, over-capture, cancellation race, reconciliation |
| 7 | Orders ↔ Vendor allocation | line quantity conservation, multi-vendor split, unavailable vendor, partial cancellation/refund, repeated allocation |
| 8 | Commissions ↔ Payouts | commission basis, fee/tax handling, refund reversal, payout hold/release, duplicate posting, reconciliation |
| 9 | Tenders ↔ Procurement ↔ PO | award gate, rejected bid, idempotent conversion, approval gate, source snapshot, duplicate PO prevention |
| 10 | Quotes/Proposals ↔ Contracts | accepted version only, expired/revoked source, conversion snapshot, duplicate conversion, amended proposal |
| 11 | Verification ↔ Suppliers/Vendors | status mapping, approved/rejected/suspended, evidence audit, repeated event, re-verification |
| 12 | Compliance enforcement | policy allow/block, missing evidence, expiry, override authorization, audit trail, policy change after signing |
| 13 | Disputes ↔ Escrow | freeze, partial/full release, refund, invalid transitions, duplicate decision, concurrent resolution |
| 14 | Money precision | 0/2/3-decimal currencies, rounding modes, multiplication/division, aggregate sums, float rejection, serialization |
| 15 | CI compatibility | PHP/Laravel matrix, lowest/stable, optional package combinations, SQLite/MySQL differences, lockfile freshness |

## Matrix policy

- Run supported PHP and Laravel combinations declared by the package, not guessed combinations.
- Resolve dependencies independently in each matrix job; do not install an incompatible committed lockfile before matrix-specific resolution.
- Run both `--prefer-lowest` and `--prefer-stable` where the dependency contract promises both.
- Add MySQL coverage for behavior SQLite cannot faithfully represent (locking, JSON, collation, decimal behavior, FK semantics).
- Keep optional integration packages in separate dependency groups/jobs so one optional package does not silently become mandatory for every consumer.
- Record the exact Composer dependency graph and test output for release review.
