<?php

declare(strict_types=1);

namespace SurSolver\Exception;

/**
 * The task ended without a result (ERROR_FAILED, ERROR_TASK_TIMEOUT, ERROR_TASK_EXPIRED, or
 * ERROR_WRONG_USERAGENT after acceptance). Its charge is returned once.
 */
final class TaskFailedException extends ApiException
{
}
