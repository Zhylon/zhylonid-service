<?php

namespace Zhylon\ZhylonIdService\Exceptions;

/**
 * ZhylonID rejected the GitHub access token (invalid, expired or without the `user:email` scope).
 * Ask the user to sign in with GitHub again.
 *
 * ZhylonID answers HTTP 422 with code `github_token_invalid`.
 */
final class GithubTokenInvalidException extends GithubResolveException
{
    public function __construct()
    {
        parent::__construct('The GitHub access token is invalid, expired or lacks the user:email scope.');
    }

    public function errorCode(): string
    {
        return 'github_token_invalid';
    }
}
