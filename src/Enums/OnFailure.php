<?php

namespace Zhylon\ZhylonIdService\Enums;

/**
 * What to do when ZhylonID cannot be reached or answers with an error.
 */
enum OnFailure: string
{
    // Throw ZhylonIdUnavailableException (default, fail closed).
    case Block = 'block';

    // Return the "negative" result and log a warning. Opt-in only.
    case Allow = 'allow';

    /**
     * Anything other than an explicit "allow" fails closed.
     */
    public static function fromConfig(mixed $value): self
    {
        return is_string($value) && self::Allow->value === strtolower(trim($value))
            ? self::Allow
            : self::Block;
    }
}
