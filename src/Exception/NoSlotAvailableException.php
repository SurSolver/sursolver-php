<?php

declare(strict_types=1);

namespace SurSolver\Exception;

/**
 * ERROR_NO_SLOT_AVAILABLE: all your threads are busy. Nothing was created; submitting later is safe.
 */
final class NoSlotAvailableException extends ApiException
{
}
