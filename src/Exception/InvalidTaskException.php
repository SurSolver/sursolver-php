<?php

declare(strict_types=1);

namespace SurSolver\Exception;

/**
 * ERROR_INVALID_REQUEST, ERROR_TYPE_NOT_SUPPORTED, or ERROR_WRONG_USERAGENT on create (see
 * $supportedUserAgents). Nothing was created or charged.
 */
final class InvalidTaskException extends ApiException
{
}
