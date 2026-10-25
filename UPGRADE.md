# Upgrade Guide

## 1.x → 2.0

v2 stops apps from quietly registering users or changing emails while ZhylonID is
unreachable. The package now decides this for every app (DECISION-011). Default: **block**.

```bash
composer require zhylon/zhylonid-service:^2.0
```

**Order:** deploy a ZhylonID release with `POST /api/internals/users/lookup`,
`GET /api/internals/users/{zhylon_id}` and `PATCH /api/internals/users/{zhylon_id}/email`
first. Against an older ZhylonID, v2 blocks every registration (`unexpected_status`, 404).

### Checklist for every app (Sitealarm, Zhylon Captcha, Zhylon Realtime)

#### 1. Registration: catch `ZhylonIdUnavailableException`

`userExists()` used to return `false` on any error, and the app then created a local user.
Now it throws.

```php
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

try {
    $exists = $zhylonId->userExists($email);
} catch (ZhylonIdUnavailableException $e) {
    // Show "Registration is not possible right now, please try again in a few minutes."
    // No local fallback. Optionally report($e) — the message contains no secrets or bodies.
    return back()->withInput()->withErrors(['email' => __('auth.registration_unavailable')]);
}
```

- [ ] Wrap every `userExists()` call (registration controller, Fortify `CreateNewUser`,
      API registration endpoints).
- [ ] Add the "registration currently not possible" message (and translations).
- [ ] Do not catch the exception just to continue the registration anyway.
- [ ] `userExists()` now throws `InvalidArgumentException` for an empty or invalid email:
      validate the request first (`'email' => ['required', 'email']`).

#### 2. Email changes only via `changeEmail()`

- [ ] Remove every code path that writes a new `email` for a user with a `zhylon_id`
      (profile form, Fortify `UpdateUserProfileInformation`, admin panels, API).
- [ ] Call `changeEmail($user->zhylon_id, $newEmail)` instead, and handle the result:
  - `PendingVerification` → show "We sent a confirmation link to your new address."
  - `EmailTaken` → validation error "This email address is already in use."
  - `ZhylonIdUnavailableException` (block mode) → "Email changes are not possible right now."
- [ ] **Do not change the local email** at that point. ZhylonID swaps it only after
      confirmation. The app picks up the new address from ZhylonID later (e.g. on the next
      login via zhylon-auth).
- [ ] A 429 throws `ZhylonIdUnavailableException` with reason `rate_limited`: show
      "Too many attempts, please try again later" (`$e->retryAfter` has the seconds).
- [ ] Requires a ZhylonID release with `PATCH /api/internals/users/{zhylon_id}/email`
      (DECISION-017). Against an older ZhylonID, `changeEmail()` fails with
      `unexpected_status` (404). Deploy ZhylonID first, then the apps.

#### 3. Read-only email for users with a `zhylon_id`

- [ ] UI: render the email field as read-only/disabled when `zhylon_id` is set, with a
      "Change email" action that goes through `changeEmail()`.
- [ ] Server side: reject or ignore an `email` input for these users in validation or the
      update action. The disabled field alone is not enough.

#### 4. Configuration

- [ ] Leave `ZHYLONID_SERVICE_ON_FAILURE` unset (= `block`). Use `allow` only with a
      documented reason. It brings back the 1.x behaviour plus a warning log.
- [ ] Check the new timeout defaults: `ZHYLONID_SERVICE_HTTP_TIMEOUT=5` (was 10) and
      `ZHYLONID_SERVICE_CONNECT_TIMEOUT=2`.
- [ ] Re-publish or compare a published `config/zhylonid-service.php`
      (`on_failure`, `connect_timeout` are new).

#### 5. Sync command

- [ ] Run `php artisan zhylon-id:sync-users --dry-run` first and review the conflicts.
- [ ] Conflicts (email already exists in ZhylonID) are no longer linked automatically. They
      stay without `zhylon_id` until the account-linking flow (zhylon-auth v3.0) exists.
- [ ] The command refuses to run while `on_failure=allow` is set.

#### 6. Tests and custom implementations

- [ ] Fakes or custom classes implementing `ZhylonIdServiceContract` must add
      `findUserByEmail(string $email): ?ZhylonIdUser`,
      `getUser(string $zhylonId): ?ZhylonIdUser` and
      `changeEmail(string $zhylonId, string $newEmail): EmailChangeResult`.
- [ ] Tests that relied on `userExists()` returning `false` for HTTP errors must expect
      `ZhylonIdUnavailableException` instead.
- [ ] `Http::fake` for `api/internals/check-user` no longer applies. Fake
      `POST api/internals/users/lookup` instead: `200` with a JSON:API `users` resource
      (exists) or `404 {"errors":[{"code":"not_found"}]}` (does not exist).

### Behaviour reference

| Situation                             | 1.x `userExists()` | 2.0 `block` (default)                  | 2.0 `allow`       |
|---------------------------------------|--------------------|----------------------------------------|-------------------|
| Account exists (1.x: check-user `failed`, 2.0: lookup `200`) | `true` | `true`         | `true`            |
| No account (1.x: check-user `ok`, 2.0: lookup `404 not_found`) | `false` | `false`      | `false`           |
| Timeout / connection error            | `false`            | exception (`timeout` / `connection_failed`) | `false` + warning |
| 401 / 403                             | `false`            | exception (`unauthorized`)             | `false` + warning |
| 404 without `not_found` (route missing) | `false`          | exception (`unexpected_status`)        | `false` + warning |
| 5xx                                   | `false`            | exception (`server_error`)             | `false` + warning |
| Token request failed                  | `false`            | exception (`token_request_failed`)     | `false` + warning |
| Broken JSON / no `users` resource     | `true`             | exception (`invalid_response`)         | `false` + warning |
| Empty / invalid email                 | `false`            | `InvalidArgumentException`             | `InvalidArgumentException` |
