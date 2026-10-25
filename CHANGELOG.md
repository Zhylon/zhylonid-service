# Changelog

All notable changes to this package are documented here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/), versioning is semver.

## [2.0.0] - 2026-10-25

ZhylonID unavailability is no longer reported as "user does not exist". The package now
decides this consistently for all apps (DECISION-011). See [UPGRADE.md](UPGRADE.md).

### Breaking
- `userExists()`, `findUserByEmail()`, `getUser()`, `changeEmail()` and `getSyncUsers()` throw
  `Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException` on timeout, connection
  error, 401/403, 404, 5xx, a failed token request or an unparseable response (new default
  `on_failure=block`). In 1.x `userExists()` returned `false` in all of these cases.
- `userExists()` no longer calls the legacy `POST /api/internals/check-user`. It uses the new
  JSON:API endpoint `POST /api/internals/users/lookup`: `200` = exists, `404` with code
  `not_found` = does not exist. A 404 without `not_found` (route missing, older ZhylonID)
  counts as a failure. **Requires a ZhylonID release with the lookup endpoint.**
- `userExists()` and `changeEmail()` throw `InvalidArgumentException` for an empty or invalid
  email, before any request is sent.
- `ZhylonIdServiceContract` has new methods `findUserByEmail()`, `getUser()` and `changeEmail()`. Custom
  implementations and test doubles must add them.
- Token request failures throw `ZhylonIdUnavailableException` (a `RuntimeException`
  subclass, so existing `catch (RuntimeException)` blocks still match).
- `zhylon-id:sync-users` no longer links local users to ZhylonID accounts that already exist
  for the same email. They are listed as conflicts and skipped. The command refuses to run
  while `on_failure=allow` is set.
- Default `http_timeout` lowered from 10 s to 5 s.

### Added
- Config `on_failure` (`ZHYLONID_SERVICE_ON_FAILURE`): `block` (default) or `allow`
  (returns the negative result and logs a warning). Unknown values count as `block`.
- Config `connect_timeout` (`ZHYLONID_SERVICE_CONNECT_TIMEOUT`, default 2 s).
- `findUserByEmail(string $email): ?ZhylonIdUser`: like `userExists()`, but returns the user
  with `emailVerified`.
- `getUser(string $zhylonId): ?ZhylonIdUser` with `emailVerified` and `pendingEmailChange`,
  via `GET /api/internals/users/{zhylon_id}`. `null` for an unknown user (JSON:API
  `not_found`). A non-boolean `email_verified` counts as an invalid response, never as verified.
- `changeEmail(string $zhylonId, string $newEmail): EmailChangeResult` with status
  `pending_verification`, `email_taken` or `unavailable` (allow mode only). Talks to
  `PATCH /api/internals/users/{zhylon_id}/email` (ZhylonID DECISION-017,
  `docs/internal-api-email.md`). Requires a ZhylonID release that includes this endpoint.
- Failure reason `rate_limited` (HTTP 429) with `ZhylonIdUnavailableException::$retryAfter`
  from the `Retry-After` header.
- `zhylon-id:sync-users --dry-run`: lists accounts that would be created and conflicts,
  writes nothing.
- Enums `FailureReason`, `OnFailure`, `EmailChangeStatus` and DTOs `EmailChangeResult`,
  `ZhylonIdUser`, `PendingEmailChange`.

### Changed
- A 401 from the internal API removes the cached access token, so the next call fetches a
  fresh one. Token caching itself is unchanged. Failure results are never cached.
- The sync command only links users that ZhylonID reports as created by this request
  (`created: true` in the sync response). `created: false` or a missing flag is never
  linked, which also covers an account created between the check and the push.

### Security
- Failure logs contain only `operation`, `reason` and HTTP `status`. No response bodies,
  secrets, tokens, email addresses or transport error messages.

## [1.0.1] - 2026-08-23

### Fixed
- Service name error.

## [1.0.0] - 2026-08-23

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
