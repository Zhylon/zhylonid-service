# Changelog

All notable changes to this package are documented here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/), versioning is semver.

## [1.0.0] - Unreleased

### Added
- Initial extraction from Sitealarm's internal `ZhylonIdService` into a standalone,
  config-driven package.
- `ZhylonIdServiceContract` for DI/testing against an interface (TECH_STACK.md pattern).
- `zhylon-id:sync-users` Artisan command, generalized to any configurable user model.
- `ZhylonId` facade.

### Changed
- Config is fully `env()`-driven; no product-specific defaults (e.g. Sitealarm) baked in.
- OAuth token cache key is namespaced per client id (`sha256`), safe for a shared cache
  across multiple ZhylonID-connected products.
- Failed OAuth token requests no longer include the raw response body in exceptions/logs.
- HTTPS is enforced for the ZhylonID endpoint by default.

### Removed
- The Fortify-specific `RegisterController` was intentionally **not** included — it's
  app-specific glue code, documented instead in the README as an integration recipe.
