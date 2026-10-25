<?php

namespace Zhylon\ZhylonIdService\Enums;

/**
 * Why a ZhylonID call could not produce a trustworthy answer.
 */
enum FailureReason: string
{
    case Timeout = 'timeout';
    case ConnectionFailed = 'connection_failed';
    case Unauthorized = 'unauthorized';
    case ServerError = 'server_error';
    case RateLimited = 'rate_limited';
    case UnexpectedStatus = 'unexpected_status';
    case InvalidResponse = 'invalid_response';
    case TokenRequestFailed = 'token_request_failed';
}
