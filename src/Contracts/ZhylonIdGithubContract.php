<?php

namespace Zhylon\ZhylonIdService\Contracts;

use Zhylon\ZhylonIdService\Data\GithubResolution;
use Zhylon\ZhylonIdService\Exceptions\GithubUnavailableException;
use Zhylon\ZhylonIdService\Exceptions\GithubTokenInvalidException;
use Zhylon\ZhylonIdService\Exceptions\ZhylonIdUnavailableException;
use Zhylon\ZhylonIdService\Exceptions\GithubEmailUnverifiedException;

/**
 * "Sign in with GitHub" in apps with their own registration (DECISION-038).
 * Contract: zhylon-id docs/internal-api-github.md.
 *
 * A separate interface, so existing implementations and test doubles of
 * ZhylonIdServiceContract keep working.
 */
interface ZhylonIdGithubContract
{
    /**
     * POST /api/internals/users/github/resolve (scope internal.write).
     *
     * Pass the GitHub access token from the app's own GitHub OAuth flow (scope user:email).
     * ZhylonID reads the GitHub ID and verified email from GitHub itself; the token is not stored.
     *
     * The three Github*Exception classes extend GithubResolveException and are thrown
     * regardless of on_failure: they are definite answers, not outages.
     *
     * @throws GithubTokenInvalidException The token is invalid, expired or lacks user:email.
     * @throws GithubEmailUnverifiedException The GitHub account has no verified primary email.
     * @throws GithubUnavailableException GitHub is unavailable or rate limited.
     * @throws ZhylonIdUnavailableException ZhylonID gave no trustworthy answer (on_failure=block).
     * @throws \InvalidArgumentException The token is empty.
     *
     * @return GithubResolution|null Null only with on_failure=allow.
     */
    public function resolveGithubUser(string $githubAccessToken): ?GithubResolution;
}
