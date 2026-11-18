# Known gaps and operational notes

1. **Synchronous OrderCreated event:** the Orders package dispatches the event before its own transaction returns. An outer adapter transaction cannot make synchronous listeners after-commit. External side effects must be deferred by listeners.
2. **Default database connection:** atomicity requires Checkout and Orders models and service transactions to use the same default connection.
3. **Customer morph:** checkout stores only `user_id`, not a polymorphic customer. The adapter uses a configured morph type and never guesses an application user model.
4. **Money representation:** current upstream Orders service uses floats internally. The adapter passes through checkout decimal values but does not solve the upstream precision issue.
5. **Payment and inventory:** order creation does not imply payment capture or inventory reservation.
6. **Shipping/tax calculation:** this adapter transfers selected method and calculated totals; it does not calculate rates or taxes.
7. **SQLite concurrency:** SQLite tests prove behavior and mapping, not production row-lock semantics. Validate concurrency on the production database engine.
8. **Custom model configuration:** underlying packages may configure custom model classes. The adapter reads the configured Orders model for idempotent lookup; the Checkout session is reloaded using the passed model class.
