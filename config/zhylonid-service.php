<?php

return [

    // Base URL of the ZhylonID instance, e.g. https://id.zhylon.net
    'endpoint' => env('ZHYLONID_SERVICE_ENDPOINT'),

    // OAuth2 client credentials grant — issued per consuming product in ZhylonID.
    // NEVER commit real values. Set these in the consuming app's .env only.
    'client_id' => env('ZHYLONID_SERVICE_CLIENT_ID'),
    'client_secret' => env('ZHYLONID_SERVICE_CLIENT_SECRET'),

    'scope' => env('ZHYLONID_SERVICE_SCOPE', 'internal.read internal.write'),

    // Refuse plaintext HTTP by default. Only disable for local dev against
    // a self-signed / http-only ZhylonID instance.
    'require_https' => env('ZHYLONID_SERVICE_REQUIRE_HTTPS', true),

    'user_agent' => env('ZHYLONID_SERVICE_USER_AGENT', config('app.name').'/1.0'),

    'http_timeout' => env('ZHYLONID_SERVICE_HTTP_TIMEOUT', 10),

    // Slightly under 3600s so the token is refreshed before Passport expires it.
    'token_cache_ttl' => env('ZHYLONID_SERVICE_TOKEN_TTL', 3500),

    'sync' => [
        // Eloquent model class used by `zhylon-id:sync-users`.
        'user_model' => env('ZHYLONID_SERVICE_USER_MODEL', \App\Models\User::class),

        // Column on that model holding the remote ZhylonID user id.
        'id_column' => env('ZHYLONID_SERVICE_ID_COLUMN', 'zhylon_id'),

        // Provider slug reported to ZhylonID (defaults to app name).
        'provider' => env('ZHYLONID_SERVICE_PROVIDER', config('app.name')),
    ],

];
