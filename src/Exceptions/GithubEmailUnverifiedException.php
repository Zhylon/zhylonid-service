<?php

namespace Zhylon\ZhylonIdService\Exceptions;

/**
 * The GitHub account has no primary email address with `verified: true`.
 * Show: "Please verify your email at GitHub or register with email".
 *
 * ZhylonID answers HTTP 422 with code `github_email_unverified`.
 */
final class GithubEmailUnverifiedException extends GithubResolveException
{
    public function __construct()
    {
        parent::__construct('The GitHub account has no verified primary email address.');
    }

    public function errorCode(): string
    {
        return 'github_email_unverified';
    }
}
