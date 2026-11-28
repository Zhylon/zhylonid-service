<?php

namespace Zhylon\ZhylonIdService\Data;

use Zhylon\ZhylonIdService\Enums\GithubResolutionStatus;

/**
 * Outcome of ZhylonIdGithubContract::resolveGithubUser().
 *
 * $user is set for Existing, Linked and Created, and null only for RequiresLogin.
 * Log in locally only when canSignIn() is true, with $user->id as zhylon_id, and store
 * ZhylonID's $user->email (it can differ from the address at GitHub).
 */
final readonly class GithubResolution
{
    public function __construct(
        public GithubResolutionStatus $status,
        public bool $created,
        public ?ZhylonIdUser $user,
    ) {}

    public function canSignIn(): bool
    {
        return GithubResolutionStatus::RequiresLogin !== $this->status && null !== $this->user;
    }

    public function requiresLogin(): bool
    {
        return GithubResolutionStatus::RequiresLogin === $this->status;
    }
}
