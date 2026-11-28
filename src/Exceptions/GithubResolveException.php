<?php

namespace Zhylon\ZhylonIdService\Exceptions;

use RuntimeException;

/**
 * ZhylonID gave a definite answer that GitHub sign-in is not possible right now.
 * Nothing was created or linked. Thrown regardless of zhylonid-service.on_failure.
 *
 * Messages never contain the GitHub access token, response bodies or email addresses.
 */
abstract class GithubResolveException extends RuntimeException
{
    /** The machine-readable error code from ZhylonID. */
    abstract public function errorCode(): string;
}
