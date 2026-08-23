# ZhylonID Service

Server-to-server Laravel client for the [ZhylonID](https://id.zhylon.net) identity API.
Lets any Zhylon-ecosystem product check whether a ZhylonID account already exists for an
email address, and sync local users against ZhylonID via the `client_credentials` grant.

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

# Only for the `zhylon-id:sync-users` command:
ZHYLONID_SERVICE_USER_MODEL="App\\Models\\User"
ZHYLONID_SERVICE_ID_COLUMN=zhylon_id
ZHYLONID_SERVICE_PROVIDER=sitealarm
```

Credentials are issued per product in the ZhylonID admin panel with least-privilege
scopes — request only `internal.read` if you never call `getSyncUsers()`.

## Usage

Via dependency injection (preferred — code against the contract, not the concrete class):

```php
use Zhylon\ZhylonIdService\Contracts\ZhylonIdServiceContract;

class RegisterController extends Controller
{
    public function __construct(
        private readonly ZhylonIdServiceContract $zhylonId,
    ) {}

    public function store(Request $request)
    {
        if ($this->zhylonId->userExists($request->string('email'))) {
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

### Syncing users

```bash
php artisan zhylon-id:sync-users
```

Finds local users where `zhylonid-service.sync.id_column` (default `zhylon_id`) is `null`, pushes
them to ZhylonID, and backfills the returned remote ids. Requires the configured
`zhylonid-service.sync.user_model` to expose `id`, `name`, `email`, and the id column.

## Security

See [SECURITY.md](SECURITY.md) for the vulnerability disclosure process, and note:

- The client refuses non-`https://` endpoints by default (`ZHYLON_ID_REQUIRE_HTTPS`).
- Access tokens are cached per client id (`sha256` of the client id, never the secret) and
  are never logged. Failed token requests log only the HTTP status, never the response body.
- Client secrets live in `.env` only — this package never ships or reads secrets from
  version-controlled config.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT — see [LICENSE](LICENSE).
