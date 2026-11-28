<?php

namespace Zhylon\ZhylonIdService\Enums;

/**
 * meta.status of POST /api/internals/users/github/resolve (zhylon-id docs/internal-api-github.md).
 */
enum GithubResolutionStatus: string
{
    // The GitHub account was already linked to this ZhylonID user.
    case Existing = 'existing';

    // A ZhylonID user with the same verified email was found and the GitHub account is now linked to it.
    case Linked = 'linked';

    // No match: ZhylonID created a new user (email verified, GitHub account linked).
    case Created = 'created';

    // An account with this email exists but could not be linked (email unverified in ZhylonID,
    // or linked to another GitHub account). No user data: send the user to the normal ZhylonID login.
    case RequiresLogin = 'requires_login';
}
