# Remaining ITMOSOFT Commerce Integration Workstreams

**Status:** planning and acceptance-contract bundle, based on the confirmed v0.8.0 adapter baseline. This document does not claim that the 15 workstreams below are implemented.

## Baseline included

This archive retains the existing `laravel-commerce-adapters` v0.8.0 code and tests: Checkout → Orders, Payments → Orders, Inventory → Orders, Cart → Pricing, Cart → Inventory, Checkout → Shipping, Checkout → Tax, and Checkout → Promotions.

The baseline was taken from the repository's `main` tree at commit `bbc868ba42d5896a26aefad76946a44aebc66135` (2026-11-18). The user previously confirmed the v0.8.0 tests passed, GitHub Actions was green, and the release was published.

## Important distinction

The 15 items below are integration workstreams, not 15 ready-made adapter classes. Several require a cross-package contract, a schema/source-of-truth decision, or lifecycle coordination. Creating thin classes before inspecting and agreeing those contracts would produce unsafe or misleading integrations. Each workstream has acceptance criteria in `integration-workstreams/` and a recommended implementation sequence below.

## Workstream register

| # | Workstream | Classification | Main decision / risk | Proposed output |
|---:|---|---|---|---|
| 1 | Product Catalog ↔ Product Variants | Source-of-truth integration | Eliminate duplicate variant identity and avoid dual writes | Canonical variant ownership contract, migration/compatibility plan, adapter only if public APIs support it |
| 2 | Multitenancy across commerce | Cross-cutting tenancy contract | Tenant scoping, global scopes, background jobs, and cross-tenant leakage | Tenant context contract and isolation test suite; likely changes in core packages as well as adapters |
| 3 | Stores ↔ Products, Carts, Orders | Domain association integration | Define store ownership and immutable order store snapshot | Store context resolver/adapter plus ownership and isolation tests |
| 4 | Suppliers ↔ Purchase Orders | Domain lifecycle integration | Supplier identity, supplier-product references, PO supplier snapshot | Supplier/PO adapter and lifecycle tests |
| 5 | Checkout Shipping + Tax + Promotions orchestration | Checkout orchestration | Recalculation order, shipping taxability, discount/tax bases, quote staleness | Orchestrator coordinating existing adapters; explicit deterministic calculation pipeline |
| 6 | Checkout ↔ Payments orchestration | Payment lifecycle integration | Authorize/capture timing, retries, idempotency, pending/failed checkout states | Checkout payment orchestrator and payment-state transition tests |
| 7 | Orders ↔ Marketplace vendor allocation | Order decomposition | Split quantities/lines by vendor, partial fulfillment, order totals and taxes | Allocation service/adapter and invariants for quantity conservation |
| 8 | Marketplace ↔ Commissions ↔ Payouts | Financial lifecycle integration | Commission basis, reversals, refunds, payout eligibility and idempotency | Commission/payout lifecycle coordinator and ledger reconciliation tests |
| 9 | Tenders ↔ Procurement ↔ Purchase Orders | Procurement workflow | Award/approval gates, bid-to-procurement conversion, PO duplication | Conversion workflow with immutable source references and idempotency |
| 10 | Quotes/Proposals ↔ Contracts | Commercial document lifecycle | Accepted version, scope/price snapshot, expiry and conversion idempotency | Accepted-document-to-contract conversion service and audit tests |
| 11 | Seller Verification ↔ Suppliers/Vendors | Identity/status synchronization | Verification status mapping, evidence, suspension and re-verification | Status synchronization adapter with explicit mapping table and event tests |
| 12 | Compliance ↔ Contracts/Suppliers | Policy enforcement | Which policies block creation, signing, renewal, or payment | Policy evaluation contract and enforcement hooks; avoid implicit side effects |
| 13 | Disputes ↔ Escrow | Funds-control lifecycle | Freeze/release/refund authority, partial outcomes, terminal states | Dispute escrow coordinator and transition matrix tests |
| 14 | Standardized money precision | Cross-cutting financial contract | Currency exponent, decimal scale, rounding, float contamination | Money value contract and compatibility tests; core package changes likely required |
| 15 | Integration CI and compatibility matrices | Build/release engineering | Dependency combinations, lowest/stable resolution, optional package combinations | Consistent CI matrix, package compatibility policy, integration test runner |

## Recommended implementation order

1. **Contracts first:** #1, #2, #14. These affect identity, isolation, and financial correctness. Do not build downstream adapters on unresolved contracts.
2. **Foundational domain links:** #3, #4, #11.
3. **Checkout and payment orchestration:** #5, #6.
4. **Marketplace financial lifecycle:** #7, #8, #13.
5. **Commercial/procurement lifecycle:** #9, #10, #12.
6. **CI hardening:** #15 continuously, not only at the end.

## Non-negotiable integration rules

- Use public package services/models/events; do not write directly to another package's tables.
- Keep core packages independently installable. Integrations remain optional unless a capability is genuinely inseparable.
- Require explicit tenant/store context where applicable; never infer it from an arbitrary global or current user without a documented contract.
- Use idempotency keys for conversions, payment actions, redemptions, reservations, allocations, and financial postings.
- Dispatch external side effects after the owning database transaction commits.
- Define lifecycle state transitions and terminal states before wiring listeners.
- Preserve source snapshots on durable records (orders, contracts, purchase orders, payouts) rather than relying on mutable source records.
- Represent money without binary floating-point arithmetic at integration boundaries. Respect each currency's exponent; do not assume every currency has two decimal places.
- Require shared-connection atomicity where a workflow claims to be transactional. If multiple connections are supported, use an outbox/saga design and document eventual consistency.
- Test duplicate events, retries, concurrent updates, rollback, stale data, and partial completion.

## Completion definition

A workstream is not complete merely because a class exists or PHP syntax passes. It is complete only when:

1. Current source APIs and dependency constraints have been inspected read-only.
2. The public contract and ownership of writes are documented.
3. Positive, negative, idempotency, concurrency, rollback, and compatibility tests exist.
4. PHPUnit passes against supported lowest and stable dependency strategies.
5. The full integration CI matrix is green.
6. Limitations and migration implications are documented.
