[![ZhylonID Service][zhylonid-service-image]][zhylonid-service-edit-link]

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zhylon/zhylonid-service.svg?style=flat-square)](https://packagist.org/packages/zhylon/zhylonid-service)
[![Tests](https://img.shields.io/github/actions/workflow/status/Zhylon/zhylonid-service/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/Zhylon/zhylonid-service/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/dependency-v/zhylon/zhylonid-service/php?style=flat-square)](https://packagist.org/packages/zhylon/zhylonid-service)
[![Laravel Version](https://img.shields.io/packagist/dependency-v/zhylon/zhylonid-service/illuminate%2Fsupport?label=laravel&style=flat-square)](https://packagist.org/packages/zhylon/zhylonid-service)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/zhylon/zhylonid-service.svg?style=flat-square)](https://packagist.org/packages/zhylon/zhylonid-service)

Server-to-server Laravel client for the [ZhylonID](https://id.zhylon.net) identity API.
Lets any Zhylon-ecosystem product check whether a ZhylonID account already exists for an
email address, request an email change in ZhylonID, and sync local users against ZhylonID
via the `client_credentials` grant.

If ZhylonID cannot give a trustworthy answer, the package **fails closed** by default: it
throws instead of pretending the user does not exist (DECISION-011). Upgrading from 1.x?
See [UPGRADE.md](UPGRADE.md).

This package only talks to ZhylonID's **internal API** (service-to-service). It is not an
OAuth2 login/SSO integration for end users — for "Login with ZhylonID" use
[`zhylon/zhylon-auth`](https://github.com/zhylon/zhylon-auth) instead.

## Installation

```bash
composer require zhylon/zhylonid-service
```

Laravel's package auto-discovery registers the service provider and `ZhylonId` facade
automatically. Publish the config file:

```bash
php artisan vendor:publish --tag=zhylonid-service-config
```

## Configuration

Set the following in your app's `.env` — **never commit real values**:

```env
ZHYLONID_SERVICE_ENDPOINT=https://id.zhylon.net
ZHYLONID_SERVICE_CLIENT_ID=your-client-id
ZHYLONID_SERVICE_CLIENT_SECRET=your-client-secret
ZHYLONID_SERVICE_SCOPE="internal.read internal.write"

# Optional — defaults shown:
ZHYLONID_SERVICE_ON_FAILURE=block       # block | allow
ZHYLONID_SERVICE_HTTP_TIMEOUT=5         # seconds
ZHYLONID_SERVICE_CONNECT_TIMEOUT=2      # seconds

# Only for the `zhylon-id:sync-users` command:
ZHYLONID_SERVICE_USER_MODEL="App\\Models\\User"
ZHYLONID_SERVICE_ID_COLUMN=zhylon_id
ZHYLONID_SERVICE_PROVIDER=sitealarm
```

Credentials are issued per product in the ZhylonID admin panel, and the client must be
allow-listed for the internal routes in ZhylonID. Scopes per call:

| Call                                      | ZhylonID endpoint                              | Scope                                    |
|-------------------------------------------|------------------------------------------------|------------------------------------------|
| `userExists()`, `findUserByEmail()`       | `POST /api/internals/users/lookup`             | `internal.read`                          |
| `getUser()`                               | `GET /api/internals/users/{zhylon_id}`         | `internal.read`                          |
| `changeEmail()`                           | `PATCH /api/internals/users/{zhylon_id}/email` | `internal.write`                         |
| `getSyncUsers()` / `zhylon-id:sync-users` | `POST /api/internals/providers/sync-users`     | `internal.read` **and** `internal.write` |
| `resolveGithubUser()`                     | `POST /api/internals/users/github/resolve`     | `internal.write`                         |

v2 requires a ZhylonID release that includes the lookup and email endpoints.

### Failure behaviour (`on_failure`)

`userExists()`, `findUserByEmail()`, `getUser()`, `changeEmail()` and `getSyncUsers()` drive
decisions, so they never guess.
On timeout, connection error, 401/403, 404, 5xx, a failed token request or an unparseable
response:

| `on_failure`      | Result                                                                                                                                                        |
|-------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `block` (default) | throws `ZhylonIdUnavailableException` (`reason`, `status`)                                                                                                    |
| `allow`           | `userExists()` → `false`, `findUserByEmail()` / `getUser()` → `null`, `changeEmail()` → status `unavailable`, `getSyncUsers()` → `null`, plus a `warning` log |

Any other value counts as `block`. Only set `allow` deliberately: with it, a ZhylonID outage
lets local registrations through without a check. Failure results are never cached, and a
401 drops the cached access token so the next call fetches a fresh one.

`get()` and `post()` are raw transport methods and do not apply `on_failure`.

## Usage

Via dependency injection (preferred — code against the contract, not the concrete class):

```php
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;

class RegisterController extends Controller
{
    public function __construct(
        private readonly ZhylonIdServiceContract $zhylonId,
    ) {}

    public function store(Request $request)
    {
        try {
            $exists = $this->zhylonId->userExists($request->string('email'));
        } catch (ZhylonIdUnavailableException) {
            // ZhylonID gave no trustworthy answer: never fall back to a local account.
            return back()->withInput()->withErrors([
                'email' => __('Registration is not possible right now. Please try again in a few minutes.'),
            ]);
        }

        if ($exists) {
            return redirect()->route('login')->with('status', __('auth.zhylon_account_exists'));
        }

        // ... continue with your app's own registration flow (Fortify, Breeze, etc.)
    }
}
```

Or via the facade:

```php
use Zhylon\ZhylonIdService\Facades\ZhylonIdService;

if (ZhylonIdService::userExists($email)) {
    // ...
}
```

> The package intentionally does **not** ship a Fortify `RegisterController`. Registration
> flows are app-specific (Fortify, Breeze, custom) — wire `ZhylonIdServiceContract::userExists()`
> into your own controller as shown above, per the Contract/Provider pattern in TECH_STACK.md.

### Looking up a user (`email_verified`)

```php
$zhylonUser = $this->zhylonId->getUser($user->zhylon_id);      // by zhylon_id, ?ZhylonIdUser
$zhylonUser = $this->zhylonId->findUserByEmail($email);        // by email, ?ZhylonIdUser

if ($zhylonUser?->emailVerified) {
    // The owner proved this address in ZhylonID.
}
```

Both return `id`, `name`, `email`, `emailVerified`, `personalOrgUuid`, `personalOrgSlug` and
`pendingEmailChange` (`newEmail`, `expiresAt`), and `null` for an unknown user (JSON:API `not_found`). Any other 404, e.g.
`route_not_found` from a ZhylonID without these routes, counts as a failure.
`emailVerified` is `true` only when the owner proved the address. Users created or synced
by a product start unverified, so **only trust `email` for account linking when
`emailVerified` is `true`**. With `on_failure=allow`, an outage also returns `null`, which
is the safe answer for linking.

### Changing a user's email

ZhylonID is the source of truth for email addresses. It checks uniqueness, sends a
confirmation link to the new address and swaps the address only after the user confirmed.
**Never change the local email before or because of this call.**

```php
use Zhylon\ZhylonIdService\Enums\EmailChangeStatus;

$result = $this->zhylonId->changeEmail($user->zhylon_id, $request->string('email'));

return match ($result->status) {
    EmailChangeStatus::PendingVerification => back()->with('status', __('We sent a confirmation link to your new address.')),
    EmailChangeStatus::EmailTaken          => back()->withErrors(['email' => __('This email address is already in use.')]),
    EmailChangeStatus::Unavailable         => back()->withErrors(['email' => __('Email changes are not possible right now.')]),
};
```

In `block` mode a ZhylonID outage throws `ZhylonIdUnavailableException` instead of returning
`Unavailable`. Other rejections (invalid address, address unchanged) throw
`InvalidArgumentException`. Users with a `zhylon_id` should see their email as read-only in
the app.

Details from the ZhylonID side (`docs/internal-api-email.md` in zhylon-id):

- Endpoint `PATCH /api/internals/users/{zhylon_id}/email`, scope `internal.write`. The
  `zhylon_id` is accepted with (`h:Xb9kPq`) or without prefix.
- The confirmation link is valid for 24 hours and works without login. The old address gets
  a notice without a link. A new request replaces the open one.
- Rate limits: 5 requests per hour per user, 60 per minute per client. A 429 throws
  `ZhylonIdUnavailableException` with reason `rate_limited` and `$retryAfter` (seconds).
- Requires a ZhylonID release that includes this endpoint. Older ZhylonID versions answer
  404, which fails as `unexpected_status`.

### Syncing users

```bash
php artisan zhylon-id:sync-users --dry-run   # list only, writes nothing
php artisan zhylon-id:sync-users
```

Finds local users where `zhylonid-service.sync.id_column` (default `zhylon_id`) is `null` and
checks each email with `userExists()`:

- **New** emails are pushed to ZhylonID, and the returned remote ids are backfilled.
- **Conflicts** (email already exists in ZhylonID) are listed and **never linked**
  automatically. Linking an existing account needs proof of ownership (zhylon-auth v3.0).

`--dry-run` prints both lists and writes nothing, locally or in ZhylonID. If ZhylonID is
unavailable, the command fails and writes nothing. It refuses to run while
`on_failure=allow` is set, because a bulk sync must always fail closed.

Only accounts that ZhylonID reports as `created: true` for this request are linked. An
account created in ZhylonID between the check and the push comes back as `created: false`
and stays unlinked, like every other conflict.

Requires the configured `zhylonid-service.sync.user_model` to expose `id`, `name`, `email`,
and the id column.

### Organizations

ZhylonID owns organizations, their members and the organization roles (`owner`, `admin`,
`member`). Apps own product permissions and data and reference an organization by its
`uuid`. The organization methods live on their own contract, implemented by the same
service (and available on the facade):

```php
use Zhylon\ZhylonIdService\Contracts\ZhylonIdOrganizationsContract;

public function __construct(private readonly ZhylonIdOrganizationsContract $organizations) {}

$org     = $this->organizations->getOrganization($uuid);            // ?ZhylonIdOrganization
$orgs    = $this->organizations->getUserOrganizations($zhylonId);   // ?Collection<ZhylonIdOrganization>, with ->role of that user
$members = $this->organizations->getOrganizationMembers($uuid);     // ?Collection<OrganizationMember> (zhylonId, role)
```

- **Store the `uuid`** (e.g. as `zhylon_org_id`). Use `slug` for URLs only. Slugs are owned by
  ZhylonID and globally unique: store them, **never generate your own**. `slug` is `null` only
  for a ZhylonID that does not send slugs yet.
- **Personal organizations never appear here.** Use `ZhylonIdUser::$personalOrgUuid` and
  `$personalOrgSlug` from `getUser()` / `findUserByEmail()`.
- `null` means unknown organization or user (JSON:API `not_found`). Lists fetch all pages.
  `on_failure` applies as for the user methods (`allow` → `null` + warning).
- Do not rely on a cached member list alone to revoke access.

#### Importing existing organizations

One-time, idempotent import keyed by `provider` + `external_id`. Organizations are never matched
by name, and an existing organization is never changed: differences are only reported.

```php
$report = $this->organizations->syncOrganizations(
    $teams->map(fn ($team) => [
        'external_id'     => $team->id,          // provider defaults to zhylonid-service.sync.provider
        'name'            => $team->name,
        'owner_zhylon_id' => $team->owner->zhylon_id,
        'members'         => $team->users->map(fn ($u) => ['zhylon_id' => $u->zhylon_id, 'role' => $u->pivot->role])->all(),
    ]),
    dryRun: true,
);

$report->isClean();                                  // no conflicts, skipped entries, unknown users or roles
$report->resultFor('sitealarm', 42)?->status;        // OrganizationSyncStatus
$report->resultFor('sitealarm', 42)?->organizationSlug;
$report->organizationIdFor('sitealarm', 42);         // uuid to store as zhylon_org_id
```

Run with `dryRun: true`, sync missing users and add role mappings in ZhylonID until the report
is clean, then run with `dryRun: false`. At most 100 organizations per call. A rejected document
(422) throws `InvalidArgumentException` with the error codes and pointers.

Requires a ZhylonID release with the internal organization endpoints and slugs.

### Sign in with GitHub

Apps with their own registration can offer "Sign in with GitHub" without the ZhylonID redirect
(DECISION-038). **The app owns the GitHub OAuth flow** (e.g. Socialite) and requests the scope
`user:email`. It passes only the GitHub access token; ZhylonID reads the GitHub ID and the
verified primary email from GitHub itself and never trusts IDs or emails sent by the app.

```php
use Laravel\Socialite\Facades\Socialite;
use Zhylon\ZhylonIdService\Contracts\ZhylonIdGithubContract;
use Zhylon\ZhylonIdService\Exceptions\GithubUnavailableException;
use Zhylon\ZhylonIdService\Exceptions\GithubTokenInvalidException;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;
use Zhylon\ZhylonIdService\Exceptions\GithubEmailUnverifiedException;

// redirect():  Socialite::driver('github')->scopes(['user:email'])->redirect();

public function callback(ZhylonIdGithubContract $zhylonId)
{
    $token = Socialite::driver('github')->user()->token;

    try {
        $resolution = $zhylonId->resolveGithubUser($token);
    } catch (GithubEmailUnverifiedException) {
        return redirect()->route('register')->withErrors(['github' => __('Please verify your email at GitHub or register with email.')]);
    } catch (GithubTokenInvalidException) {
        return redirect()->route('login')->withErrors(['github' => __('GitHub sign-in failed. Please try again.')]);
    } catch (GithubUnavailableException|ZhylonIdUnavailableException) {
        return redirect()->route('login')->withErrors(['github' => __('GitHub sign-in is not possible right now. Please try again later.')]);
    }

    if ($resolution->requiresLogin()) {
        // An account with this email exists but could not be linked: use the normal ZhylonID OAuth login.
        return redirect()->route('login.zhylonid')->with('status', __('Please sign in with your ZhylonID account.'));
    }

    // existing, linked or created: log in locally with zhylon_id = $resolution->user->id
    // and store ZhylonID's $resolution->user->email (it can differ from the address at GitHub).
}
```

| `status`        | `created` | `user`         | App does                                       |
|-----------------|-----------|----------------|------------------------------------------------|
| `Existing`      | `false`   | `ZhylonIdUser` | log in                                         |
| `Linked`        | `false`   | `ZhylonIdUser` | log in (GitHub was just linked to the account) |
| `Created`       | `true`    | `ZhylonIdUser` | create the local user, log in                  |
| `RequiresLogin` | `false`   | `null`         | log nobody in, redirect to the ZhylonID login  |

- `user` has the same shape as `getUser()` / `findUserByEmail()`. Log in only when
  `$resolution->canSignIn()` is `true`.
- `GithubTokenInvalidException`, `GithubEmailUnverifiedException` and `GithubUnavailableException`
  extend `GithubResolveException` (`errorCode()` returns ZhylonID's code). They are definite answers,
  so they are thrown regardless of `on_failure`. Nothing was created or linked.
- Everything else (unreachable, timeout, 401/403, 429, 5xx, a 404 from a ZhylonID without this route,
  any other error code, an invalid answer) follows `on_failure` like the lookup: `block` throws
  `ZhylonIdUnavailableException`, `allow` returns `null` plus a warning. Treat `null` as a failure,
  never as a sign-in.
- The token is sent in the request body only and never logged. ZhylonID does not store it.
- The method lives on its own contract `ZhylonIdGithubContract` (same instance, also on the facade),
  so existing implementations and test doubles of `ZhylonIdServiceContract` keep working.
- Requires a ZhylonID release with the GitHub resolve endpoint and a client with scope `internal.write`.

## Security

See [SECURITY.md](SECURITY.md) for the vulnerability disclosure process, and note:

- The client refuses non-`https://` endpoints by default (`ZHYLON_ID_REQUIRE_HTTPS`).
- Access tokens are cached per client id (`sha256` of the client id, never the secret) and
  are never logged. Failed token requests log only the HTTP status, never the response body.
- Failure logs contain only `operation`, `reason` and `status` — no response bodies, secrets,
  tokens or email addresses. Exception messages follow the same rule.
- Fails closed by default (`on_failure=block`).
- Client secrets live in `.env` only — this package never ships or reads secrets from
  version-controlled config.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT — see [LICENSE](LICENSE).


[zhylonid-service-image]: https://socialify.git.ci/Zhylon/zhylonid-service/image?description=1&font=Raleway&issues=1&language=1&owner=1&pattern=Charlie%20Brown&pulls=1&stargazers=1&theme=Light
[zhylonid-service-edit-link]: https://socialify.git.ci/Zhylon/zhylonid-service?description=1&font=Raleway&issues=1&language=1&owner=1&pattern=Charlie%20Brown&pulls=1&stargazers=1&theme=Light
