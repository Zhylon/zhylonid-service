<?php

namespace Zhylon\ZhylonIdService\Exceptions;

/**
 * ZhylonID could not get a usable answer from GitHub (unreachable, rate limited, unexpected answer).
 * Ask the user to try again later; email registration keeps working.
 *
 * ZhylonID answers HTTP 503 with code `github_unavailable`.
 */
final class GithubUnavailableException extends GithubResolveException
{
    public function __construct()
    {
        parent::__construct('GitHub is unavailable, rate limited or gave an unexpected answer.');
    }

    public function errorCode(): string
    {
        return 'github_unavailable';
    }
}
