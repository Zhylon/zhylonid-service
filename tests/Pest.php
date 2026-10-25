<?php

use Illuminate\Support\Facades\Http;
use Zhylon\ZhylonIdService\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

const ZHYLON_TOKEN_URL = 'id.zhylon.test/oauth/token';
const ZHYLON_LOOKUP_URL = 'id.zhylon.test/api/internals/users/lookup';
const ZHYLON_SYNC_URL = 'id.zhylon.test/api/internals/providers/sync-users';

function fakeToken(): array
{
    return [ZHYLON_TOKEN_URL => Http::response(['access_token' => 'fake-token'], 200)];
}

/**
 * Body of POST /api/internals/users/lookup and GET /api/internals/users/{id}.
 */
function zhylonUserBody(string $email = 'jane@example.com', bool $verified = true): array
{
    return ['data' => [
        'id'         => 'h:abc123',
        'type'       => 'users',
        'links'      => ['self' => ['href' => 'https://id.zhylon.test/api/internals/users/h:abc123']],
        'attributes' => [
            'name'                 => 'Jane Doe',
            'email'                => $email,
            'email_verified'       => $verified,
            'pending_email_change' => null,
        ],
    ]];
}

function notFoundBody(): array
{
    return ['errors' => [['status' => '404', 'code' => 'not_found', 'title' => 'Not Found', 'detail' => 'Record not found.']]];
}

function lookupFound(string $email = 'jane@example.com', bool $verified = true)
{
    return Http::response(zhylonUserBody($email, $verified), 200);
}

function lookupNotFound()
{
    return Http::response(notFoundBody(), 404);
}
