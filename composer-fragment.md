Add to `require`:

```json
"itmosoft/laravel-product-catalog": "^1.0",
"itmosoft/laravel-product-variants": "^1.0.1"
```

Add the two VCS repositories from `composer-repositories.json` to the existing `repositories` array. `^1.0.1` is intentional: the published v1.0.0 provider does not yet support migration opt-out.
