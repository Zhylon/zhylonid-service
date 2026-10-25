<?php

namespace Zhylon\ZhylonIdService\Enums;

enum EmailChangeStatus: string
{
    // ZhylonID sent a confirmation link to the new address. Nothing is swapped yet.
    case PendingVerification = 'pending_verification';

    // The new address already belongs to another ZhylonID account.
    case EmailTaken = 'email_taken';

    // ZhylonID was unavailable and on_failure=allow is set. Nothing happened.
    case Unavailable = 'unavailable';
}
